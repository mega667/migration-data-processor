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

use function PHPSTORM_META\type;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

use Magento\Framework\App\Filesystem\DirectoryList;
use Migration\App\ProgressBar\LogLevelProcessor;

class Customers extends Command {
    /**
     * @var array
     */
    protected $customerGroups = [];

    /**
     * @var \Magento\Customer\Api\GroupRepositoryInterface
     */
    protected $groupRepository;

    /**
     * @var DirectoryList
     */
    protected $directoryList;

    /**
     * Search Criteria Builder
     *
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    private $criteriaBuilder;

    /**
     * @var LogLevelProcessor
     */
    protected $progressBar;

    /**
     * @param DirectoryList $directoryList
     * @param LogLevelProcessor $progressBar
     * @param \Magento\Customer\Api\GroupRepositoryInterface $groupRepository
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $criteriaBuilder
     */
    public function __construct(
        DirectoryList $directoryList,
        LogLevelProcessor $progressBar,
        \Magento\Customer\Api\GroupRepositoryInterface $groupRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $criteriaBuilder
    ) {
        $this->directoryList = $directoryList;
        $this->progressBar = $progressBar;
        $this->groupRepository = $groupRepository;
        $this->criteriaBuilder = $criteriaBuilder;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('customers:process')->setDescription('Refactor customers data before import');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fileName = 'customers';

        $varDir = $this->directoryList->getPath(DirectoryList::VAR_DIR);

        $filePath = $varDir . '/migration_data_src/' . $fileName . '.csv';

        $resultFilePath = $varDir  . '/migration_data_src/' .  $fileName . '-refactored.csv';

        $fieldsToRefactor = [
            'Associate to Website <website_id>' => [
                'capezio.com' => 'EU Website',
                'capezioaustralia.com' => 'AU Website'
            ],
            'Create In <store_id>' => [
                2 => 8,
                8 => 7
            ]
        ];

        $m2CustomerGroups = $this->getAllM2customerGroups();

        if (!$m2CustomerGroups) {
            $output->writeln("Can't get M2 user groups");
            exit;
        }

        $content = file_get_contents($filePath);

        $lines = str_getcsv($content, "\r\n");

        $this->progressBar->start(count($lines));

        foreach ($lines as $index => $line) {
            if ($index == 0) {
                $header = str_getcsv($line, ',', '"');
                file_put_contents($resultFilePath, '"' . implode('","', $header) . '"' . "\r\n");
            } else {

                $row = array_combine($header, str_getcsv($line, ',', '"'));

                $m2UserGroupId = $this->mapUserGroup($row['Group <group_id>']);

                if (!$m2UserGroupId) {
                    $output->writeln("Can't get user group [{$row['Email <email>']}] - user skipped");
                    continue;
                }

                $row['Group <group_id>'] = $m2UserGroupId;

                $row['Customer Address Country <customer_address_country_id>'] = $this->convertCountryId($row);

                foreach ($row as $key => $value) {
                    $row[$key] = str_replace('"',"'", $row[$key]);
                }

                foreach ($fieldsToRefactor as $field => $map) {
                    if (!empty($row[$field])) {
                        foreach ($map as $from => $to) {
                            if ($row[$field] == $from) {
                                $row[$field] = $to;
                                break;
                            }
                        }
                    }
                }
                file_put_contents($resultFilePath, '"' . implode('","', $row) . '"' . "\r\n",FILE_APPEND);
                $this->progressBar->advance();
            }
        }

        $this->progressBar->finish();
    }

    protected function mapUserGroup($m1GroupId)
    {
        $m1CustomerGroups = [
            0 => 'NOT LOGGED IN',
            1 => 'General',
            2 => 'Wholesale',
            3 => 'Retailer',
            4 => 'Abandoned Cart',
            5 => 'Students',
            6 => 'SYNOPSIS DANSE Perpignan',
            7 => 'Teachers'
        ];

        $m2CustomerGroups = $this->getAllM2customerGroups();

        if (!empty($m1CustomerGroups[$m1GroupId])) {
            $m1GroupName = $m1CustomerGroups[$m1GroupId];
        } else {
            return false;
        }

        if (!empty($m2CustomerGroups[$m1GroupName])) {
            return $m2CustomerGroups[$m1GroupName];
        }

        return false;
    }

    protected function getAllM2customerGroups()
    {
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
            return [];
        }
        return $this->customerGroups;
    }

    protected function convertCountryId($row)
    {
        $map = [
            2 => 'GB',
            8 => 'AU'
        ];
        if (empty($row['Customer Address Country <customer_address_country_id>']) && !empty($map[$row['Create In <store_id>']])) {
            return $map[$row['Create In <store_id>']];
        }
        return $row['Customer Address Country <customer_address_country_id>'];
    }
}
