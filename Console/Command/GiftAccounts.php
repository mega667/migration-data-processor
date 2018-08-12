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

class GiftAccounts extends Command {
    /**
     * @var DirectoryList
     */
    protected $directoryList;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var LogLevelProcessor
     */
    protected $progressBar;

    /**
     * @param DirectoryList $directoryList
     * @param LogLevelProcessor $progressBar
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     */
    public function __construct(
        DirectoryList $directoryList,
        LogLevelProcessor $progressBar,
        \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
        $this->directoryList = $directoryList;
        $this->progressBar = $progressBar;
        $this->resourceConnection = $resourceConnection;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('gift-accounts:process')->setDescription('Generate sql file to import gift accounts');
        $this->addArgument( 'website-code',  InputArgument::REQUIRED,  '' );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $websiteCodes = [
            3 => 'eu_website',
            4 => 'au_website'
        ];

        $websiteCode = $input->getArgument('website-code');

        if (!in_array($websiteCode, $websiteCodes)) {
            $output->writeln("website-code '{$websiteCode}' does not exists");
            exit;
        }

        $addedGiftCodes = [];
        $addedGiftCodesPool = [];

        $tableFields = [
            'magento_giftcardaccount' => [
                'code',
                'status',
                'date_created',
                'date_expires',
                'website_id',
                'balance',
                'state',
                'is_redeemable'
            ],
            'magento_giftcardaccount_history' => [
                // 'history_id',
                'giftcardaccount_id',
                'updated_at',
                'action',
                'balance_amount',
                'balance_delta',
                'additional_info',
            ],
            'magento_giftcardaccount_pool' => [
                'code',
                'magento_giftcardaccount_pool_status'
            ]
        ];

        $varDir = $this->directoryList->getPath(DirectoryList::VAR_DIR);

        $fileName = $websiteCode . '_gift_accounts_data';

        $filePath = $varDir . '/migration_data_src/' . $fileName . '.txt';

        $content = file_get_contents($filePath);

        $lines = str_getcsv($content, "\r\n");

        $this->progressBar->start(count($lines));

        foreach ($lines as $index => $line) {
            if ($index == 0) {
                $header = str_getcsv($line, "\t" );
            } else {
                $row = array_combine($header, str_getcsv(trim($line), "\t"));

                $row['website_id'] = array_search($websiteCode, $websiteCodes);
                $row['additional_info'] = str_replace("'", "\'", $row['additional_info']);

                foreach ($tableFields as $table => $fields) {

                    if ($row['giftcardaccount_id'] == 'NULL' && $table == 'magento_giftcardaccount_history') {
                        continue;
                    }

                    if (in_array($row['code'], $addedGiftCodes) && $table == 'magento_giftcardaccount') {
                        continue;
                    }

                    if (in_array($row['code'], $addedGiftCodesPool) && $table == 'magento_giftcardaccount_pool') {
                        continue;
                    }

                    $fieldValues = [];

                    foreach ($fields as $field) {
                        $fieldValues[str_replace($table . '_', '', $field)] = $this->processValue($row[$field]);
                    }

                    $giftcardaccount_id = '';
                    if ($table == 'magento_giftcardaccount_history') {
                        unset($fieldValues['giftcardaccount_id']);
                        $giftcardaccount_id = '(SELECT giftcardaccount_id FROM magento_giftcardaccount WHERE code = "' . $row['code'] . '"), ';
                    }

                    if ($table == 'magento_giftcardaccount') {
                        $addedGiftCodes[] = $row['code'];
                    }

                    if ($table == 'magento_giftcardaccount_pool') {
                        $addedGiftCodesPool[] = $row['code'];
                    }

                    $this->insertGeftAccountData($table, $giftcardaccount_id, $fieldValues);
                }
            }
            $this->progressBar->advance();
        }

        $this->progressBar->finish();
    }

    protected function insertGeftAccountData($table, $giftcardaccount_id, $fieldValues)
    {
        $connection = $this->resourceConnection->getConnection();

        $sqlUpdateCommand = "INSERT IGNORE INTO {$table} (" . ($giftcardaccount_id ? "giftcardaccount_id, " : ""). implode(', ', array_keys($fieldValues)) . ") VALUES ({$giftcardaccount_id}" . implode(", ", $fieldValues) . ")";

        $connection->query($sqlUpdateCommand);
    }

    public function processValue($value) {
        if ($value !== 'NULL') {
            $value = "'{$value}'";
        }
        return $value;
    }
}
