<?php
App::uses('AppModel', 'Model');
App::uses('RandomTool', 'Tools');

/**
 * @property Attribute $Attribute
 */
class Correlation extends AppModel
{
    const CACHE_NAME = 'misp:top_correlations',
        CACHE_AGE = 'misp:top_correlations_age';

    private $__compositeTypes = [];

    public $belongsTo = array(
        'Attribute' => [
            'className' => 'Attribute',
            'foreignKey' => 'attribute_id'
        ],
        'Event' => array(
            'className' => 'Event',
            'foreignKey' => 'event_id'
        ),
        'Object' => array(
            'className' => 'Object',
            'foreignKey' => 'object_id'
        ),
        'CorrelationValue' => [
            'className' => 'CorrelationValue',
            'foreignKey' => 'value_id'
        ]
    );

    public $validEngines = [
        'Default' => 'default_correlations',
        'NoAcl' => 'no_acl_correlations',
        'Legacy' => 'correlations'
    ];

    public $actsAs = array(
        'Containable'
    );

    /** @var array */
    private $exclusions;

    /**
     * Use old schema with `date` and `info` fields.
     * @var bool
     */
    private $oldSchema;

    /** @var bool */
    private $deadlockAvoidance;

    /** @var bool */
    private $advancedCorrelationEnabled;

    /** @var array */
    private $cidrListCache;

    private $__correlationEngine = 'DefaultCorrelation';

    protected $_config = [];

    private $__tempContainCache = [];

    public $OverCorrelatingValue = null;

    public function __construct($id = false, $table = null, $ds = null)
    {
        parent::__construct($id, $table, $ds);
        $this->__correlationEngine = $this->getCorrelationModelName();
        $this->deadlockAvoidance = Configure::check('MISP.deadlock_avoidance') ? Configure::read('MISP.deadlock_avoidance') : false;
        // load the currently used correlation engine
        $this->Behaviors->load($this->__correlationEngine . 'Correlation', ['deadlockAvoidance' => false]);
        // getTableName() needs to be implemented by the engine - this points us to the table to be used
        $this->useTable = $this->getTableName();
        $this->advancedCorrelationEnabled = (bool)Configure::read('MISP.enable_advanced_correlations');
        // load the overcorrelatingvalue model for chaining
        $this->OverCorrelatingValue = ClassRegistry::init('OverCorrelatingValue');
    }

    public function correlateValueRouter($value)
    {
        if (Configure::read('MISP.background_jobs')) {

            /** @var Job $job */
            $job = ClassRegistry::init('Job');
            $jobId = $job->createJob(
                'SYSTEM',
                Job::WORKER_DEFAULT,
                'correlateValue',
                $value,
                'Recorrelating'
            );

            $this->getBackgroundJobsTool()->enqueue(
                BackgroundJobsTool::DEFAULT_QUEUE,
                BackgroundJobsTool::CMD_EVENT,
                [
                    'correlateValue',
                    $value,
                    $jobId
                ],
                true,
                $jobId
            );

            return true;
        } else {
            return $this->correlateValue($value);
        }
    }

    /**
     * @param array $attribute Simple attribute array
     * @return array|null
     */
    private function __buildAdvancedCorrelationConditions($attribute)
    {
        if (!$this->advancedCorrelationEnabled) {
            return null;
        }

        if (in_array($attribute['Attribute']['type'], ['ip-src', 'ip-dst', 'ip-src|port', 'ip-dst|port'], true)) {
            return $this->cidrCorrelation($attribute);
        } else if ($attribute['Attribute']['type'] === 'ssdeep' && function_exists('ssdeep_fuzzy_compare')) {
            return $this->ssdeepCorrelation($attribute);
        }
        return null;
    }

    private function __addAdvancedCorrelations($correlatingAttribute)
    {
        if (!$this->advancedCorrelationEnabled) {
            return [];
        }
        $extraConditions = $this->__buildAdvancedCorrelationConditions($correlatingAttribute);
        if (empty($extraConditions)) {
            return [];
        }
        return $this->Attribute->find('all', [
            'conditions' => [
                'AND' => $extraConditions,
                'NOT' => [
                    'Attribute.type' => Attribute::NON_CORRELATING_TYPES,
                ],
                'Attribute.disable_correlation' => 0,
                'Event.disable_correlation' => 0,
                'Attribute.deleted' => 0
            ],
            'recursive' => -1,
            'fields' => $this->getFieldRules(),
            'contain' => $this->getContainRules(),
            'order' => [],
        ]);
    }

    private function __getMatchingAttributes($value)
    {
        // stupid hack to allow statically retrieving the constants
        ClassRegistry::init('Attribute');
        $conditions = [
            'OR' => [
                'Attribute.value1' => $value,
                'AND' => [
                    'Attribute.value2' => $value,
                    'NOT' => ['Attribute.type' => Attribute::PRIMARY_ONLY_CORRELATING_TYPES]
                ]
            ],
            'NOT' => [
                'Attribute.type' => Attribute::NON_CORRELATING_TYPES,
            ],
            'Attribute.disable_correlation' => 0,
            'Event.disable_correlation' => 0,
            'Attribute.deleted' => 0
        ];
        $correlatingAttributes = $this->Attribute->find('all', [
            'conditions' => $conditions,
            'recursive' => -1,
            'fields' => $this->getFieldRules(),
            'contain' => $this->getContainRules(),
            'order' => [],
        ]);
        return $correlatingAttributes;
    }

    /**
     * @param string $value
     * @param array $a Attribute A
     * @param array $b Attribute B
     * @return array
     */
    private function __createCorrelationEntry($value, $a, $b)
    {
        return $this->createCorrelationEntry($value, $a, $b);
    }

    public function correlateValue($value, $jobId = false)
    {
        $correlatingAttributes = $this->__getMatchingAttributes($value);
        $count = count($correlatingAttributes);
        $correlations = [];
        if ($jobId) {
            if (empty($this->Job)) {
                $this->Job = ClassRegistry::init('Job');
            }
            $job = $this->Job->find('first', [
                'recursive' => -1,
                'conditions' => ['id' => $jobId]
            ]);
            if (empty($job)) {
                $jobId = false;
            }
        }
        foreach ($correlatingAttributes as $k => $correlatingAttribute) {
            foreach ($correlatingAttributes as $correlatingAttribute2) {
                if ($correlatingAttribute['Attribute']['event_id'] === $correlatingAttribute2['Attribute']['event_id']) {
                    continue;
                }
                $correlations[] = $this->__createCorrelationEntry($value, $correlatingAttribute, $correlatingAttribute2);
            }
            $extraCorrelations = $this->__addAdvancedCorrelations($correlatingAttribute);
            if (!empty($extraCorrelations)) {
                foreach ($extraCorrelations as $extraCorrelation) {
                    if ($correlatingAttribute['Attribute']['event_id'] === $extraCorrelation['Attribute']['event_id']) {
                        continue;
                    }
                    $correlations[] = $this->__createCorrelationEntry($value, $correlatingAttribute, $extraCorrelation);
                    //$correlations = $this->__createCorrelationEntry($value, $extraCorrelation, $correlatingAttribute, $correlations);
                }
            }
            if ($jobId && $k % 100 === 0) {
                $this->Job->saveProgress($jobId, __('Correlating Attributes based on value. %s attributes correlated out of %s.', $k, $count), floor(100 * $k / $count));
            }
        }
        if (empty($correlations)) {
            return true;
        }
        return $this->__saveCorrelations($correlations);
    }

    /**
     * @param array $correlations
     * @return array|bool|bool[]|mixed
     */
    private function __saveCorrelations(array $correlations)
    {
        return $this->saveCorrelations($correlations);
    }

    public function correlateAttribute(array $attribute)
    {
        $this->runBeforeSaveCorrelation($attribute);
        $this->afterSaveCorrelation($attribute);
    }

    public function beforeSaveCorrelation(array $attribute)
    {
        $this->runBeforeSaveCorrelation($attribute);
    }

    private function __cachedGetContainData($scope, $id)
    {
        if (!empty($this->getContainRules($scope))) {
            if (empty($this->__tempContainCache[$scope][$id])) {
                $temp = $this->Attribute->$scope->find('first', array(
                    'recursive' => -1,
                    'fields' => $this->getContainRules($scope)['fields'],
                    'conditions' => ['id' => $id],
                    'order' => array(),
                ));
                $temp = empty($temp) ? false : $temp[$scope];
                $this->__tempContainCache[$scope][$id] = $temp;
                return $temp;
            } else {
                return $this->__tempContainCache[$scope][$id];
            }
        }
    }

    /**
     * @param array $a
     * @param bool $full
     * @param array|false $event
     * @return array|bool|bool[]|mixed
     */
    public function afterSaveCorrelation($a, $full = false, $event = false)
    {
        $a = ['Attribute' => $a];
        if (!empty($a['Attribute']['disable_correlation']) || Configure::read('MISP.completely_disable_correlation')) {
            return true;
        }
        // Don't do any correlation if the type is a non correlating type
        if (in_array($a['Attribute']['type'], Attribute::NON_CORRELATING_TYPES, true)) {
            return true;
        }
        if (!$event) {
            $a['Event'] = $this->__cachedGetContainData('Event', $a['Attribute']['event_id']);
            if (!$a['Event']) {
                // orphaned attribute, do not correlate
                return true;
            }
        } else {
            $a['Event'] = $event['Event'];
        }
        if (!empty($a['Event']['disable_correlation'])) {
            return true;
        }

        if (!empty($a['Attribute']['object_id'])) {
            $a['Object'] = $this->__cachedGetContainData('Object', $a['Attribute']['object_id']);
            if (!$a['Object']) {
                // orphaned attribute, do not correlate
                return true;
            }
        }
        // generate additional correlating attribute list based on the advanced correlations
        if (!$this->__preventExcludedCorrelations($a['Attribute']['value1'])) {
            $extraConditions = $this->__buildAdvancedCorrelationConditions($a);
            $correlatingValues = [$a['Attribute']['value1']];
        } else {
            $extraConditions = null;
            $correlatingValues = [];
        }
        if (!empty($a['Attribute']['value2']) && !in_array($a['Attribute']['type'], Attribute::PRIMARY_ONLY_CORRELATING_TYPES, true) && !$this->__preventExcludedCorrelations($a['Attribute']['value2'])) {
            $correlatingValues[] = $a['Attribute']['value2'];
        }
        if (empty($correlatingValues)) {
            return true;
        }
        $correlations = [];
        foreach ($correlatingValues as $k => $cV) {
            if ($cV === null) {
                continue;
            }
            $conditions = [
                'OR' => [
                    'Attribute.value1' => $cV,
                    'AND' => [
                        'Attribute.value2' => $cV,
                        'NOT' => ['Attribute.type' => Attribute::PRIMARY_ONLY_CORRELATING_TYPES]
                    ],
                ],
                'NOT' => [
                    'Attribute.event_id' => $a['Attribute']['event_id'],
                    'Attribute.type' => Attribute::NON_CORRELATING_TYPES,
                ],
                'Attribute.disable_correlation' => 0,
                'Event.disable_correlation' => 0,
                'Attribute.deleted' => 0,
            ];
            $correlationLimit = $this->OverCorrelatingValue->getLimit();

            $correlatingAttributes = $this->Attribute->find('all', [
                'conditions' => $conditions,
                'recursive' => -1,
                'fields' => $this->getFieldRules(),
                'contain' => $this->getContainRules(),
                'order' => [],
                'callbacks' => 'before', // memory leak fix
                // let's fetch the limit +1 - still allows us to detect overcorrelations, but we'll also never need more
                'limit' => empty($correlationLimit) ? null : ($correlationLimit+1)
            ]);

            // Let's check if we don't have a case of an over-correlating attribute
            $count = count($correlatingAttributes);
            if ($count > $correlationLimit) {
                // If we have more correlations for the value than the limit, set the block entry and stop the correlation process
                $this->OverCorrelatingValue->block($cV, $count);
                return true;
            } else {
                // If we have fewer hits than the limit, proceed with the correlation, but first make sure we remove any existing blockers
                $this->OverCorrelatingValue->unblock($cV);
            }
            foreach ($correlatingAttributes as $b) {
                if (isset($b['Attribute']['value1'])) {
                    // TODO: Currently it is hard to check if value1 or value2 correlated, so we check value2 and if not, it is value1
                    $value = $cV === $b['Attribute']['value2'] ? $b['Attribute']['value2'] : $b['Attribute']['value1'];
                } else {
                    $value = $cV;
                }
                if ($a['Attribute']['id'] > $b['Attribute']['id']) {
                    $correlations[] = $this->__createCorrelationEntry($value, $a, $b);
                } else {
                    $correlations[] = $this->__createCorrelationEntry($value, $b, $a);
                }
            }
        }
        if (empty($correlations)) {
            return true;
        }
        return $this->__saveCorrelations($correlations);
    }

    /**
     * @param string $value
     * @return bool True if attribute value is excluded
     */
    private function __preventExcludedCorrelations($value)
    {
        if ($this->exclusions === null) {
            try {
                $redis = $this->setupRedisWithException();
                $this->exclusions = $redis->sMembers('misp:correlation_exclusions');
            } catch (Exception $e) {
                return false;
            }
        } else if (empty($this->exclusions)) {
            return false;
        }

        foreach ($this->exclusions as $exclusion) {
            if (!empty($exclusion)) {
                $firstChar = $exclusion[0];
                $lastChar = substr($exclusion, -1);
                if ($firstChar === '%' && $lastChar === '%') {
                    $exclusion = substr($exclusion, 1, -1);
                    if (strpos($value, $exclusion) !== false) {
                        return true;
                    }
                } else if ($firstChar === '%') {
                    $exclusion = substr($exclusion, 1);
                    if (substr($value, -strlen($exclusion)) === $exclusion) {
                        return true;
                    }
                } else if ($lastChar === '%') {
                    $exclusion = substr($exclusion, 0, -1);
                    if (substr($value, 0, strlen($exclusion)) === $exclusion) {
                        return true;
                    }
                } else {
                    if ($value === $exclusion) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @param array $attribute Simple attribute array
     * @return array[]|false
     */
    private function ssdeepCorrelation($attribute)
    {
        if (!isset($this->FuzzyCorrelateSsdeep)) {
            $this->FuzzyCorrelateSsdeep = ClassRegistry::init('FuzzyCorrelateSsdeep');
        }
        $value = $attribute['Attribute']['value1'];
        $fuzzyIds = $this->FuzzyCorrelateSsdeep->query_ssdeep_chunks($value, $attribute['Attribute']['id']);
        if (!empty($fuzzyIds)) {
            $ssdeepIds = $this->Attribute->find('list', array(
                'recursive' => -1,
                'conditions' => array(
                    'Attribute.type' => 'ssdeep',
                    'Attribute.id' => $fuzzyIds
                ),
                'fields' => array('Attribute.id', 'Attribute.value1')
            ));
            $threshold = Configure::read('MISP.ssdeep_correlation_threshold') ?: 40;
            $attributeIds = [];
            foreach ($ssdeepIds as $attributeId => $v) {
                $ssdeepValue = ssdeep_fuzzy_compare($value, $v);
                if ($ssdeepValue >= $threshold) {
                    $attributeIds[] = $attributeId;
                }
            }
            return ['Attribute.id' => $attributeIds];
        }
        return false;
    }

    /**
     * @param array $attribute Simple attribute array
     * @return array|array[][]
     */
    private function cidrCorrelation($attribute)
    {
        $ipValues = array();
        $ip = $attribute['Attribute']['value1'];
        if (strpos($ip, '/') !== false) { // IP is CIDR
            list($networkIp, $mask) = explode('/', $ip);
            $ip_version = filter_var($networkIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : 6;

            $conditions = array(
                'type' => array('ip-src', 'ip-dst', 'ip-src|port', 'ip-dst|port'),
                'value1 NOT LIKE' => '%/%', // do not return CIDR, just plain IPs
                'disable_correlation' => 0,
                'deleted' => 0,
            );

            if ($this->isMysql()) {
                // Massive speed up for CIDR correlation. Instead of testing all in PHP, database can do that work much
                // faster. But these methods are just supported by MySQL.
                if ($ip_version === 4) {
                    $startIp = ip2long($networkIp) & ((-1 << (32 - $mask)));
                    $endIp = $startIp + pow(2, (32 - $mask)) - 1;
                    // Just fetch IP address that fit in CIDR range.
                    $conditions['INET_ATON(value1) BETWEEN ? AND ?'] = array($startIp, $endIp);

                    // Just fetch IPv4 address that starts with given prefix. This is fast, because value1 is indexed.
                    // This optimisation is possible just to mask bigger than 8 bites.
                    if ($mask >= 8) {
                        $ipv4Parts = explode('.', $networkIp);
                        $ipv4Parts = array_slice($ipv4Parts, 0, intval($mask / 8));
                        $prefix = implode('.', $ipv4Parts);
                        $conditions['value1 LIKE'] = $prefix . '%';
                    }
                } else {
                    $conditions[] = 'IS_IPV6(value1)';
                    // Just fetch IPv6 address that starts with given prefix. This is fast, because value1 is indexed.
                    if ($mask >= 16) {
                        $ipv6Parts = explode(':', rtrim($networkIp, ':'));
                        $ipv6Parts = array_slice($ipv6Parts, 0, intval($mask / 16));
                        $prefix = implode(':', $ipv6Parts);
                        $conditions['value1 LIKE'] = $prefix . '%';
                    }
                }
            }

            $ipList = $this->Attribute->find('column', [
                'conditions' => $conditions,
                'fields' => ['Attribute.value1'],
                'unique' => true,
                'order' => false,
                'callbacks' => false,
            ]);
            foreach ($ipList as $ipToCheck) {
                $ipToCheckVersion = filter_var($ipToCheck, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : 6;
                if ($ipToCheckVersion === $ip_version) {
                    if ($ip_version === 4) {
                        if ($this->__ipv4InCidr($ipToCheck, $ip)) {
                            $ipValues[] = $ipToCheck;
                        }
                    } else {
                        if ($this->__ipv6InCidr($ipToCheck, $ip)) {
                            $ipValues[] = $ipToCheck;
                        }
                    }
                }
            }
        } else {
            $ip_version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : 6;
            $cidrList = $this->getCidrList();
            foreach ($cidrList as $cidr) {
                if (strpos($cidr, '.') !== false) {
                    if ($ip_version === 4 && $this->__ipv4InCidr($ip, $cidr)) {
                        $ipValues[] = $cidr;
                    }
                } else {
                    if ($ip_version === 6 && $this->__ipv6InCidr($ip, $cidr)) {
                        $ipValues[] = $cidr;
                    }
                }
            }
        }
        $extraConditions = array();
        if (!empty($ipValues)) {
            $extraConditions = array('OR' => array(
                'Attribute.value1' => $ipValues,
                'Attribute.value2' => $ipValues
            ));
        }
        return $extraConditions;
    }

    // using Alnitak's solution from http://stackoverflow.com/questions/594112/matching-an-ip-to-a-cidr-mask-in-php5
    private function __ipv4InCidr($ip, $cidr)
    {
        list($subnet, $bits) = explode('/', $cidr);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask; # nb: in case the supplied subnet wasn't correctly aligned
        return ($ip & $mask) == $subnet;
    }

    // Using solution from https://github.com/symfony/symfony/blob/master/src/Symfony/Component/HttpFoundation/IpUtils.php
    private function __ipv6InCidr($ip, $cidr)
    {
        list($address, $netmask) = explode('/', $cidr);

        $bytesAddr = unpack('n*', inet_pton($address));
        $bytesTest = unpack('n*', inet_pton($ip));

        for ($i = 1, $ceil = ceil($netmask / 16); $i <= $ceil; ++$i) {
            $left = $netmask - 16 * ($i - 1);
            $left = ($left <= 16) ? $left : 16;
            $mask = ~(0xffff >> $left) & 0xffff;
            if (($bytesAddr[$i] & $mask) != ($bytesTest[$i] & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return int|bool
     * @throws Exception
     */
    public function generateTopCorrelationsRouter()
    {
        if (Configure::read('MISP.background_jobs')) {
            /** @var Job $job */
            $job = ClassRegistry::init('Job');
            $jobId = $job->createJob(
                'SYSTEM',
                Job::WORKER_DEFAULT,
                'generateTopCorrelations',
                '',
                'Starting generation of top correlations.'
            );

            $this->getBackgroundJobsTool()->enqueue(
                BackgroundJobsTool::DEFAULT_QUEUE,
                BackgroundJobsTool::CMD_EVENT,
                [
                    'generateTopCorrelations',
                    $jobId
                ],
                true,
                $jobId
            );

            return $jobId;
        } else {
            return $this->generateTopCorrelations();
        }
    }

    public function generateTopCorrelations($jobId = false)
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            throw new NotFoundException(__('No redis connection found.'));
        }
        $maxId = $this->find('first', [
            'fields' => ['MAX(id) AS max_id'],
            'recursive' => -1,
        ]);
        if (empty($maxId)) {
            return false;
        }
        if ($jobId) {
            if (empty($this->Job)) {
                $this->Job = ClassRegistry::init('Job');
            }
            $job = $this->Job->find('first', [
                'recursive' => -1,
                'conditions' => ['id' => $jobId]
            ]);
            if (empty($job)) {
                $jobId = false;
            }
        }
        $maxId = $maxId[0]['max_id'];

        $redis->del(self::CACHE_NAME);
        $redis->set(self::CACHE_AGE, time());
        $chunkSize = 1000000;
        $maxPage = ceil($maxId / $chunkSize);
        for ($page = 0; $page < $maxPage; $page++) {
            $correlations = $this->find('column', [
                'fields' => ['value_id'],
                'conditions' => [
                    'id >' => $page * $chunkSize,
                    'id <=' => ($page + 1) * $chunkSize
                ],
                'callbacks' => false, // when callbacks are enabled, memory is leaked
            ]);
            $newElements = count($correlations);
            $correlations = array_count_values($correlations);
            $pipeline = $redis->pipeline();
            foreach ($correlations as $correlation => $count) {
                $pipeline->zIncrBy(self::CACHE_NAME, $count, $correlation);
            }
            $pipeline->exec();
            if ($jobId) {
                $this->Job->saveProgress($jobId, __('Generating top correlations. Processed %s IDs.', ($page * $chunkSize) + $newElements), floor(100 * $page / $maxPage));
            }
        }
        return true;
    }

    public function findTop(array $query)
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            return false;
        }
        $start = $query['limit'] * ($query['page'] -1);
        $end = $query['limit'] * $query['page'] - 1;
        $list = $redis->zRevRange(self::CACHE_NAME, $start, $end, true);
        $results = [];
        foreach ($list as $value => $count) {
            $realValue = $this->CorrelationValue->find('first',
                [
                    'recursive' => -1,
                    'conditions' => ['CorrelationValue.id' => $value],
                    'fields' => 'CorrelationValue.value'
                ]
            );
            $results[] = [
                'Correlation' => [
                    'value' => $realValue['CorrelationValue']['value'],
                    'count' => $count,
                    'excluded' => $this->__preventExcludedCorrelations($value),
                ]
            ];
        }
        return $results;
    }

    public function getTopTime()
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            return false;
        }
        return $redis->get(self::CACHE_AGE);
    }

    /**
     * @param array $attribute
     * @return void
     */
    public function advancedCorrelationsUpdate(array $attribute)
    {
        if ($this->advancedCorrelationEnabled && in_array($attribute['type'], ['ip-src', 'ip-dst'], true) && strpos($attribute['value'], '/')) {
            $this->updateCidrList();
        }
    }

    /**
     * Get list of all CIDR for correlation from database
     * @return array
     */
    private function getCidrListFromDatabase()
    {
        return $this->Attribute->find('column', [
            'conditions' => [
                'type' => ['ip-src', 'ip-dst'],
                'disable_correlation' => 0,
                'deleted' => 0,
                'value1 LIKE' => '%/%',
            ],
            'fields' => ['Attribute.value1'],
            'unique' => true,
            'order' => false,
        ]);
    }

    /**
     * @return array
     */
    public function updateCidrList()
    {
        $redis = $this->setupRedisWithException();
        $cidrList = [];
        $this->cidrListCache = null;
        if ($redis) {
            $cidrList = $this->getCidrListFromDatabase();

            $redis->pipeline();
            $redis->del('misp:cidr_cache_list');
            if (method_exists($redis, 'saddArray')) {
                $redis->sAddArray('misp:cidr_cache_list', $cidrList);
            } else {
                foreach ($cidrList as $cidr) {
                    $redis->sadd('misp:cidr_cache_list', $cidr);
                }
            }
            $redis->exec();
        }
        return $cidrList;
    }

    /**
     * @return void
     */
    public function clearCidrCache()
    {
        $this->cidrListCache = null;
    }

    /**
     * @return array
     */
    public function getCidrList()
    {
        if ($this->cidrListCache !== null) {
            return $this->cidrListCache;
        }

        $redis = $this->setupRedisWithException();
        if ($redis) {
            if (!$redis->exists('misp:cidr_cache_list')) {
                $cidrList = $this->updateCidrList();
            } else {
                $cidrList = $redis->smembers('misp:cidr_cache_list');
            }
        } else {
            $cidrList = $this->getCidrListFromDatabase();
        }
        $this->cidrListCache = $cidrList;
        return $cidrList;
    }

    /**
     * @param array $user User array
     * @param int $eventIds List of event IDs
     * @param array $sgids List of sharing group IDs
     * @return array
     */
    public function getAttributesRelatedToEvent(array $user, $eventIds, array $sgids)
    {
        return $this->runGetAttributesRelatedToEvent($user, $eventIds, $sgids);
    }


    /**
     * @param array $user User array
     * @param array $attribute Attribute Array
     * @param array $fields List of fields to include
     * @param bool $includeEventData Flag to include the event data in the response
     * @return array
     */
    public function getRelatedAttributes($user, $sgids, $attribute, $fields=[], $includeEventData = false)
    {
        if (in_array($attribute['type'], Attribute::NON_CORRELATING_TYPES)) {
            return [];
        }
        return $this->runGetRelatedAttributes($user, $sgids, $attribute, $fields, $includeEventData);
    }
    
    /**
     * @param array $user User array
     * @param int $eventIds List of event IDs
     * @param array $sgids List of sharing group IDs
     * @return array
     */
    public function getRelatedEventIds(array $user, int $eventId, array $sgids)
    {
        $relatedEventIds = $this->fetchRelatedEventIds($user, $eventId, $sgids);
        if (empty($relatedEventIds)) {
            return [];
        }
        return $relatedEventIds;
    }

    public function attachExclusionsToOverCorrelations($data)
    {
        foreach ($data as $k => $v) {
            $data[$k]['OverCorrelatingValue']['excluded'] = $this->__preventExcludedCorrelations($data[$k]['OverCorrelatingValue']['value']);
        }
        return $data;
    }

    public function setCorrelationExclusion($attribute)
    {
        if (empty($this->__compositeTypes)) {
            $this->__compositeTypes = $this->Attribute->getCompositeTypes();
        }
        $values = [$attribute['value']];
        if (in_array($attribute['type'], $this->__compositeTypes)) {
            $values = explode('|', $attribute['value']);
        }
        if ($this->__preventExcludedCorrelations($values[0])) {
            $attribute['correlation_exclusion'] = true;
        }
        if (!empty($values[1]) && $this->__preventExcludedCorrelations($values[1])) {
            $attribute['correlation_exclusion'] = true;
        }
        if ($this->OverCorrelatingValue->checkValue($values[0])) {
            $attribute['over_correlation'] = true;
        }
        if (!empty($values[1]) && $this->OverCorrelatingValue->checkValue($values[1])) {
            $attribute['over_correlation'] = true;
        }
        return $attribute;
    }

    public function collectMetrics()
    {
        $results['engine'] = $this->getCorrelationModelName();
        $results['db'] = [
            'Default' => [
                'name' => __('Default correlation engine'),
                'tables' => [
                    'default_correlations' => [
                        'id_limit' => 4294967295
                    ],
                    'correlation_values' => [
                        'id_limit' => 4294967295
                    ]
                ]
            ],
            'NoAcl' => [
                'name' => __('No ACL correlation engine'),
                'tables' => [
                    'no_acl_correlations' => [
                        'id_limit' => 4294967295
                    ],
                    'correlation_values' => [
                        'id_limit' => 4294967295
                    ]
                ]
            ],
            'Legacy' => [
                'name' => __('Legacy correlation engine (< 2.4.160)'),
                'tables' => [
                    'correlations' => [
                        'id_limit' => 2147483647
                    ]
                ]
            ]
        ];
        $results['over_correlations'] = $this->OverCorrelatingValue->find('count');
        $this->CorrelationExclusion = ClassRegistry::init('CorrelationExclusion');
        $results['excluded_correlations'] = $this->CorrelationExclusion->find('count');
        foreach ($results['db'] as &$result) {
            foreach ($result['tables'] as $table_name => &$table_data) {
                $size_metrics = $this->query(sprintf('show table status like \'%s\';', $table_name));
                if (!empty($size_metrics)) {
                    $table_data['size_on_disk'] = $this->query(
                        //'select FILE_SIZE from information_schema.innodb_sys_tablespaces where FILENAME like \'%/' . $table_name . '.ibd\';'
                        sprintf(
                            'select TABLE_NAME, ROUND((DATA_LENGTH + INDEX_LENGTH)) AS size FROM information_schema.TABLES where TABLE_SCHEMA="%s" AND TABLE_NAME="%s"',
                            $this->getDataSource()->config['database'],
                            $table_name
                        )
                    )[0][0]['size'];
                    $last_id = $this->query(sprintf('select max(id) as max_id from %s;', $table_name));
                    $table_data['row_count'] = $size_metrics[0]['TABLES']['Rows'];
                    $table_data['last_id'] = $last_id[0][0]['max_id'];
                    $table_data['id_saturation'] = round(100 * $table_data['last_id'] / $table_data['id_limit'], 2);
                }
            }
        }
        return $results;
    }

    public function truncate(array $user, string $engine)
    {
        $table = $this->validEngines[$engine];
        $result = $this->query('truncate table ' . $table);
        if ($result !== true) {
            $this->loadLog()->createLogEntry(
                $user,
                'truncate',
                'Correlation',
                0,
                'Could not truncate table ' . $table,
                'Errors: ' . json_encode($result)
            );
        }
        return $result === true;
    }
}
