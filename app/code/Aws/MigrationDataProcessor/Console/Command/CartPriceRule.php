<?php
/**
 * Absolute Web Services Intellectual Property
 *
 * @category     Aws/CapezioTheme
 * @copyright    Copyright © 1999-2017 Absolute Web Services, Inc. (http://www.absolutewebservices.com)
 * @author       Absolute Web Services
 * @license      http://www.absolutewebservices.com/license-agreement/  Single domain license
 * @terms of use http://www.absolutewebservices.com/terms-of-use/
 */

namespace Aws\MigrationDataProcessor\Console\Command;

use Magento\Banner\Block\Adminhtml\Banner\Edit\Tab\Promotions\SalesruleTest;
use function PHPSTORM_META\type;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use \Magento\Framework\App\Arguments\ValidationState;

use Magento\Framework\App\Filesystem\DirectoryList;
use Migration\App\ProgressBar\LogLevelProcessor;

class CartPriceRule extends Command {

    /**
     * @var string
     */
    protected $logFilePath;

    /**
     * @var string
     */
    protected $websiteCode;

    /**
     * @var array
     */
    protected $customerGroups = [];

    /**
     * @var \Magento\Framework\Module\Dir\Reader
     */
    protected $moduleReader;

    /**
     * @var DirectoryList
     */
    protected $directoryList;

    /**
     * @var ValidationState
     */
    protected $validationState;

    /**
     * @var \DOMXPath
     */
    protected $xml;

    /**
     * @var array
     */
    protected $map = null;

    /**
     * @var array
     */
    protected $categoriesMapM1 = null;

    /**
     * @var array
     */
    protected $categoriesMapM2 = null;

    /**
     * @var \Magento\SalesRule\Model\RuleFactory
     */
    private $ruleFactory;

    /**
     * @var \Magento\SalesRule\Api\Data\RuleInterfaceFactory
     */
    private $ruleDataFactory;

    /**
     * @var \Magento\SalesRule\Model\CouponFactory
     */
    protected $couponFactory;

    /**
     * @var \Magento\Framework\App\State
     */
    private $state;

    /**
     * @var \Magento\SalesRule\Api\CouponRepositoryInterface
     */
    private $couponRepository;

    /**
     * Filter Builder
     *
     * @var \Magento\Framework\Api\FilterBuilder
     */
    private $filterBuilder;

    /**
     * Search Criteria Builder
     *
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    private $criteriaBuilder;

    /**
     * @var \Magento\SalesRule\Api\RuleRepositoryInterface
     */
    private $ruleRepository;

    /**
     * @var \Magento\SalesRule\Model\Converter\ToModel
     */
    protected $toModelConverter;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var \Magento\Customer\Api\GroupRepositoryInterface
     */
    protected $groupRepository;

    /**
     * @var LogLevelProcessor
     */
    protected $progressBar;

    /**
     * @param \Magento\Framework\Module\Dir\Reader $moduleReader
     * @param DirectoryList $directoryList
     * @param LogLevelProcessor $progressBar
     * @param \Magento\SalesRule\Model\RuleFactory|null $ruleFactory
     * @param \Magento\SalesRule\Api\Data\RuleInterfaceFactory $ruleDataFactory
     * @param \Magento\SalesRule\Model\CouponFactory $couponFactory
     * @param \Magento\SalesRule\Api\CouponRepositoryInterface $couponRepository
     * @param \Magento\Framework\Api\FilterBuilder $filterBuilder
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $criteriaBuilder
     * @param \Magento\SalesRule\Api\RuleRepositoryInterface $ruleRepository
     * @param \Magento\SalesRule\Model\Converter\ToModel $toModelConverter
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     * @param \Magento\Customer\Api\GroupRepositoryInterface $groupRepository
     */
    public function __construct(
        \Magento\Framework\Module\Dir\Reader $moduleReader,
        DirectoryList $directoryList,
        LogLevelProcessor $progressBar,
        \Magento\SalesRule\Model\RuleFactory $ruleFactory = null,
        \Magento\SalesRule\Api\Data\RuleInterfaceFactory $ruleDataFactory,
        \Magento\SalesRule\Model\CouponFactory $couponFactory,
        \Magento\Framework\App\State $state,
        \Magento\SalesRule\Api\CouponRepositoryInterface $couponRepository,
        \Magento\Framework\Api\FilterBuilder $filterBuilder,
        \Magento\Framework\Api\SearchCriteriaBuilder $criteriaBuilder,
        \Magento\SalesRule\Api\RuleRepositoryInterface $ruleRepository,
        \Magento\SalesRule\Model\Converter\ToModel $toModelConverter,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Customer\Api\GroupRepositoryInterface $groupRepository,
        ValidationState $validationState
    ) {
        $this->moduleReader = $moduleReader;
        $this->directoryList = $directoryList;
        $this->progressBar = $progressBar;
        $this->ruleFactory = $ruleFactory;
        $this->ruleDataFactory = $ruleDataFactory;
        $this->couponFactory = $couponFactory;
        $this->state = $state;
        $this->couponRepository = $couponRepository;
        $this->filterBuilder = $filterBuilder;
        $this->criteriaBuilder = $criteriaBuilder;
        $this->ruleRepository = $ruleRepository;
        $this->toModelConverter = $toModelConverter;
        $this->resourceConnection = $resourceConnection;
        $this->groupRepository = $groupRepository;
        $this->validationState = $validationState;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('cart-price-rule:process')->setDescription('Refactor cart price rule data');
        $this->addArgument( 'website-code',  InputArgument::REQUIRED,  '' );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_GLOBAL);

        $websiteCodes = [
            'EU' => 'eu_website',
            'AU' => 'au_website'
        ];

        $websiteCode = $input->getArgument('website-code');

        if (!in_array($websiteCode, $websiteCodes)) {
            $output->writeln("website-code '{$websiteCode}' does not exists");
            exit;
        }

        $this->websiteCode = $websiteCode;

        $couponNames = [];

        $fieldsToRefactor = [
            '<conditions_serialized>',
            '<actions_serialized>'
        ];

        $moduleDir = $this->moduleReader->getModuleDir('', 'Aws_MigrationDataProcessor');
        $varDir = $this->directoryList->getPath(DirectoryList::VAR_DIR);

        $fileName = $websiteCode . '_cart_rules';

        $filePath = $varDir . '/migration_data_src/' . $fileName . '.csv';

        $configFile = $moduleDir  . '/etc/class-map.xml';

        $this->logFilePath = $varDir  . '/migration_data_src/' .  $fileName . '-processing.log';
        file_put_contents($this->logFilePath, "--- Start logging ---" . "\r\n");

        $this->initCategoriesMapM1();
        $this->initCategoriesMapM2();

        $xml = file_get_contents($configFile);
        $document = new \Magento\Framework\Config\Dom($xml, $this->validationState);
        $this->xml = new \DOMXPath($document->getDom());

        $content = file_get_contents($filePath);

        $content = preg_replace(["/\r\n/", "/[*]\n/"],["\n", "*\r\n"], $content);

        $lines = str_getcsv($content, "\r\n");

        $this->progressBar->start(count($lines));

        foreach ($lines as $index => $line) {
            if ($index == 0) {
                $header = str_getcsv($line, ',', '*');
            } else {

                $this->progressBar->advance();

                $row = array_combine($header, str_getcsv($line, ',', '*'));

                foreach ($fieldsToRefactor as $field) {
                    if (!empty($row[$field])) {
                        $unserializedData = @unserialize($row[$field]);
                        if (is_array($unserializedData)) {
                            if (strstr($row['<conditions_serialized>'], 'category_ids') ||
                                strstr($row['<actions_serialized>'], 'category_ids')
                            ) {
                                $this->mapCategoryIds($unserializedData);
                            }
                            $this->replaceValues($unserializedData);
                        }
                        $row[$field] = str_replace('\\', '\\\\', json_encode($unserializedData));
                    }
                }
                $row['<website_codes>'] = $websiteCode;
                $row['<name>'] .= ' ' . array_search($websiteCode, $websiteCodes);
                $row['<from_date>'] = ($row['<from_date>']) ? date('Y-m-d', strtotime($row['<from_date>'])) : '';
                $row['<to_date>']   = ($row['<to_date>']) ? date('Y-m-d', strtotime($row['<to_date>'])) : '';

                if (array_key_exists($row['<name>'], $couponNames)) {
                    $couponNames[$row['<name>']]++;
                    $row['<name>'] = $row['<name>'] . ' - ' . $couponNames[$row['<name>']];
                } else {
                    $couponNames[$row['<name>']] = 1;
                }

                if ($this->ruleExists($row['<name>'])) {
                    continue;
                }

                $rule = $this->createRule($row);

                if ($rule && $rule->getRuleId()) {
                    $this->createCoupons($row['<coupon>'], $rule->getRuleId());
                } else {
                    continue;
                }
            }
        }

        $this->progressBar->finish();
    }

    /**
     * @param string $className
     * @return mixed
     */
    public function convertClassName($className)
    {
        if (is_string($className) && array_key_exists($className, $this->getMap())) {
            return $this->getMap()[$className];
        }
        return $className;
    }

    /**
     * @return array|mixed
     * @throws Exception
     */
    public function getMap()
    {
        if ($this->map === null) {
            /** @var \DOMNodeList $renameNodes */
            /** @var \DOMElement $renameNode */
            /** @var \DOMElement $classNode */
            $renameNodes = $this->xml->query('/classmap/*');
            foreach ($renameNodes as $renameNode) {
                $map = ['from' => null, 'to' => null];
                foreach ($renameNode->childNodes as $classNode) {
                    if ($classNode->nodeName == 'from') {
                        $map['from'] = $classNode->nodeValue;
                    } else if ($classNode->nodeName == 'to') {
                        $map['to'] = $classNode->nodeValue ?: null;
                    }
                }
                if ($map['from']) {
                    $this->map[$map['from']] = $map['to'];
                }
            }
        }
        return $this->map;
    }

    protected function getWebsiteId()
    {
        $websites = [
            'eu_website' => 8,
            'au_website' => 7
        ];

        return $websites[$this->websiteCode];
    }

    protected function mapCategoryIds(&$unserializedData)
    {
        if (!empty($unserializedData['conditions'])) {
            $websiteId = $this->getWebsiteId();
            foreach ($unserializedData['conditions'] as &$conditions) {
                if (!empty($conditions['attribute'] && $conditions['attribute'] == 'category_ids')) {
                    $categoryIds = explode(', ', $conditions['value']);
                    $newCategoryIds = $this->getNewCategoryIds($categoryIds, $websiteId);
                    if ($newCategoryIds) {
                        $conditions['value'] = implode(', ', $newCategoryIds);
                    }
                }
                if (!empty($conditions['conditions'])) {
                    foreach ($conditions['conditions'] as &$condition) {
                        if ($condition['attribute'] == 'category_ids') {
                            $categoryIds = explode(', ', $condition['value']);
                            $newCategoryIds = $this->getNewCategoryIds($categoryIds, $websiteId);
                            if ($newCategoryIds) {
                                $condition['value'] = implode(', ', $newCategoryIds);
                            }
                        }
                    }
                }
            }
        }
    }

    protected function getNewCategoryIds($categoryIds, $websiteId)
    {
        $newCategoryIds = [];
        if ($categoryIds) {
            foreach ($categoryIds as $categoryId) {
                if (!array_key_exists($categoryId, $this->categoriesMapM1)) {
                    continue;
                }
                $categoryName = $this->categoriesMapM1[$categoryId];
                $newCatIds = [];
                if (!empty($this->categoriesMapM2[$websiteId])) {
                    $newCatIds = array_keys($this->categoriesMapM2[$websiteId], $categoryName);
                }
                if (!$newCatIds) {
                    $newCatIds = array_keys($this->categoriesMapM2[0], $categoryName);
                }
                if ($newCatIds) {
                    $newCategoryIds = array_merge($newCategoryIds, $newCatIds);
                }
            }
        }
        return $newCategoryIds;
    }

    protected function initCategoriesMapM1()
    {
        $moduleDir = $this->moduleReader->getModuleDir('', 'Aws_MigrationDataProcessor');
        $categoryMapFile = $moduleDir  . '/etc/m1-catalog-categories.xml';

        $xml = file_get_contents($categoryMapFile);
        $document = new \Magento\Framework\Config\Dom($xml, $this->validationState);
        $categoriesXml = new \DOMXPath($document->getDom());

        $tableNodes = $categoriesXml->query('/categories/*');
        foreach ($tableNodes as $tableNode) {
            $map = ['entity_id' => null, 'value' => null];
            foreach ($tableNode->childNodes as $columnNode) {
                if (!empty($columnNode->attributes)) {
                    $attrName = $columnNode->getAttribute('name');
                    if ($attrName == 'entity_id') {
                        $map['entity_id'] = $columnNode->nodeValue;
                    } elseif ($attrName == 'value') {
                        $map['value'] = strtolower($columnNode->nodeValue);
                    }
                }
            }
            if ($map['entity_id']) {
                $this->categoriesMapM1[$map['entity_id']] = $map['value'];
            }
        }
    }

    protected function initCategoriesMapM2()
    {
        $moduleDir = $this->moduleReader->getModuleDir('', 'Aws_MigrationDataProcessor');
        $categoryMapFile = $moduleDir  . '/etc/m2-catalog-categories.xml';

        $xml = file_get_contents($categoryMapFile);
        $document = new \Magento\Framework\Config\Dom($xml, $this->validationState);
        $categoriesXml = new \DOMXPath($document->getDom());

        $tableNodes = $categoriesXml->query('/categories/*');
        foreach ($tableNodes as $tableNode) {
            $map = ['row_id' => null, 'value' => null];
            foreach ($tableNode->childNodes as $columnNode) {
                if (!empty($columnNode->attributes)) {
                    $attrName = $columnNode->getAttribute('name');
                    if ($attrName == 'row_id') {
                        $map['row_id'] = $columnNode->nodeValue;
                    } elseif ($attrName == 'value') {
                        $map['value'] = strtolower($columnNode->nodeValue);
                    } elseif ($attrName == 'store_id') {
                        $map['store_id'] = $columnNode->nodeValue;
                    }
                }
            }
            if (isset($map['store_id']) && $map['row_id']) {
                $this->categoriesMapM2[$map['store_id']][$map['row_id']] = $map['value'];
            }
        }
    }

    /**
     * @param array $data
     * @return array
     */
    protected function replaceValues(array &$data)
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->replaceValues($value);
            } elseif (is_string($value)) {
                $value = $this->convertClassName($value);
            }
        }

        return $data;
    }

    protected function ruleExists($name)
    {
        //load rule collection that matched name
        $this->criteriaBuilder->addFilters(
            [$this->filterBuilder->setField('name')->setValue($name)->setConditionType('eq')->create()]
        );
        $searchCriteria = $this->criteriaBuilder->create();
        try {
            $rules = $this->ruleRepository->getList($searchCriteria);
             // foreach ($rules->getItems() as $rule) {
             //     $this->ruleRepository->deleteById($rule->getRuleId());
             // }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            return false;
        }
        return $rules->getTotalCount();
    }

    protected function couponExists($code)
    {
        //load coupons collection that matched code
        $this->criteriaBuilder->addFilters(
            [$this->filterBuilder->setField('code')->setValue($code)->setConditionType('eq')->create()]
        );
        $searchCriteria = $this->criteriaBuilder->create();
        try {
            $coupons = $this->couponRepository->getList($searchCriteria);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            return false;
        }
        return $coupons->getTotalCount();
    }

    protected function getAllcustomerGroups() {
        if ($this->customerGroups) {
            return $this->customerGroups;
        }
        try {
            $searchCriteria = $this->criteriaBuilder->create();
            $groups = $this->groupRepository->getList($searchCriteria);
            foreach ($groups->getItems() as $group) {
                $this->customerGroups[$group->getCode()] = $group->getId();
            }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->addMessageToLog(" [Can't get groups] : " . $e->getMessage());
            return [];
        }
        return $this->customerGroups;
    }

    protected function getRuleCustomerGroupIds($customers, $name)
    {
        $customerGroupIds = [];

        $missingGroups = [];

        $customerGroups = $this->getAllcustomerGroups();

        if (!empty($customers) && $customerGroups) {
            $rowCustomerGroups = str_getcsv($customers, ',', "'");
            if (!empty($rowCustomerGroups)) {
                foreach ($rowCustomerGroups as $groupName) {
                    if (isset($customerGroups[$groupName])) {
                        $customerGroupIds[] = $customerGroups[$groupName];
                    } else {
                        $missingGroups[] = $groupName;
                    }
                }
            }
        }

        if (!empty($missingGroups)) {
            $this->addMessageToLog($name . " [missing groups] : " . implode(", ", $missingGroups));
        }

        return $customerGroupIds;
    }

    protected function generateRuleData($row)
    {
        $websitesMap = [
            'eu_website' => 3,
            'au_website' => 4
        ];

        $customerGroupIds = $this->getRuleCustomerGroupIds($row['<customers>'], $row['<name>']);

        $data = [
            'rule_id'               => null,
            'product_ids'           => '',
            'name'                  => $row['<name>'],
            'description'           => $row['<description>'],
            'is_active'             => $row['<is_active>'],
            'website_ids'           => [$websitesMap[$row['<website_codes>']]],
            'customer_group_ids'    => $customerGroupIds,
            'coupon_type'           => $row['<coupon_type>'],
            'uses_per_customer'     => $row['<uses_per_customer>'],
            'from_date'             => ($row['<from_date>']) ? date('Y-m-d', strtotime($row['<from_date>'])) : null,
            'to_date'               => ($row['<to_date>']) ? date('Y-m-d', strtotime($row['<to_date>'])) : null,
            'sort_order'            => $row['<sort_order>'],
            'times_used'            => $row['<times_used>'],
            'is_rss'                => $row['<is_rss>'],
            'simple_action'         => $row['<simple_action>'],
            'discount_amount'       => $row['<discount_amount>'],
            'discount_qty'          => $row['<discount_qty>'],
            'discount_step'         => $row['<discount_step>'],
            'apply_to_shipping'     => $row['<apply_to_shipping>'],
            'simple_free_shipping'  => $row['<simple_free_shipping>'],
            'stop_rules_processing' => $row['<stop_rules_processing>'],
            'is_advanced'           => $row['<is_advanced>'],
            'use_auto_generation'   => $row['<use_auto_generation>'],
            'uses_per_coupon'       => $row['<uses_per_coupon>'],
            'store_labels'          => [],
            'conditions_serialized' => $row['<conditions_serialized>'],
            'actions_serialized'    => $row['<actions_serialized>'],
        ];

        return $data;
    }

    protected function updateAdditionalRuleData($row, $ruleId)
    {
        $connection = $this->resourceConnection->getConnection();

        $fromDate = ($row['<from_date>']) ? "'" . date('Y-m-d', strtotime($row['<from_date>'])) . "'"  : 'NULL';
        $toDate = ($row['<to_date>']) ? "'" . date('Y-m-d', strtotime($row['<to_date>'])) . "'" : 'NULL';

        $dateCondition = ", `from_date` = {$fromDate}, `to_date` = {$toDate}";

        $sqlUpdateCommand = "UPDATE `salesrule` SET `conditions_serialized` = '{$row['<conditions_serialized>']}', `actions_serialized` = '{$row['<actions_serialized>']}' {$dateCondition} WHERE `salesrule`.`rule_id` = {$ruleId}";
        $connection->query($sqlUpdateCommand);
    }

    protected function createRule($row)
    {
        $newRule = [];

        $ruleData = $this->generateRuleData($row);

        $rule = $this->ruleDataFactory->create();
        foreach ($ruleData as $key => $value) {
            $rule->setData($key, $value);
        }

        try {
            $newRule = $this->ruleRepository->save($rule);

            if ($newRule->getRuleId()) {
                $this->updateAdditionalRuleData($row, $newRule->getRuleId());
            }

        } catch (\Magento\Framework\Exception\InputException $e) {
            $this->addMessageToLog($ruleData['name'] . " : " . $e->getMessage());
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->addMessageToLog($ruleData['name'] . " : " . $e->getMessage());
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->addMessageToLog($ruleData['name'] . " : " . $e->getMessage());
        }

        return $newRule;
    }

    protected function createCoupons($rawData, $ruleId)
    {
        $couponsProcessed = [];
        if (!empty($rawData)) {
            $coupons = str_getcsv($rawData, ';', "'");
            if (!empty($coupons)) {
                foreach ($coupons as $rawCoupon) {
                    $couponFieldsData = [];
                    $couponData = str_getcsv($rawCoupon, ',', "'");
                    if (!empty($couponData)) {
                        foreach ($couponData as $rawField) {
                            $field = str_getcsv($rawField, ':', "'");
                            if (isset($field[0]) && isset($field[1])) {
                                $couponFieldsData[$field[0]] = $field[1];
                            }
                        }
                        $couponsProcessed[] = $couponFieldsData;
                    }
                }
            }
        }

        if ($couponsProcessed) {
            foreach ($couponsProcessed as $couponData) {
                if ($this->couponExists($couponData['code'])) {
                    continue;
                }
                $newCoupon = $this->couponFactory->create();
                $newCoupon->setData($couponData);
                $newCoupon->setRuleId($ruleId);

                try {
                    $this->couponRepository->save($newCoupon);
                } catch (\Magento\Framework\Exception\InputException $e) {
                    $this->addMessageToLog($couponData['code'] . "[rule id - {$ruleId}] : " . $e->getMessage());
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                    $this->addMessageToLog($couponData['code'] . "[rule id - {$ruleId}] : " . $e->getMessage());
                } catch (\Magento\Framework\Exception\LocalizedException $e) {
                    $this->addMessageToLog($couponData['code'] . "[rule id - {$ruleId}] : " . $e->getMessage());
                }
            }
        }

        return $couponsProcessed;
    }

    protected function addMessageToLog($messages)
    {
        if ($messages) {
            if (is_array($messages)) {
                $messages = implode("\r\n", $messages);
            }
            file_put_contents($this->logFilePath, $messages . "\r\n",FILE_APPEND);
        }
    }
}
