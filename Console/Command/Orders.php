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

use Migration\Logger\Manager;
use Migration\Config;
use Migration\App\Progress;
use Migration\App\ProgressBar\LogLevelProcessor;

use Magento\Sales\Model\ResourceModel\GridPool as GridPoolResource;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Framework\App\ResourceConnection;

class Orders extends \Migration\Console\AbstractMigrateCommand {

    /**
     * @var \Aws\MigrationDataProcessor\Migration\SalesOrder
     */
    protected $salesOrderMigration;

    /**
     * @var \Aws\MigrationDataProcessor\Migration\AdditionalOrderData
     */
    protected $additionalOrderDataMigration;

    /**
     * @var GridPoolResource
     */
    protected $gridPoolResource;

    /**
     * @var CollectionFactory
     */
    protected $orderCollectionFactory;

    /**
     * @var LogLevelProcessor
     */
    protected $progressBar;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @param \Magento\Framework\Module\Dir\Reader $moduleReader
     * @param \Aws\MigrationDataProcessor\Migration\SalesOrder $salesOrderMigration
     * @param GridPoolResource $gridPoolResource
     * @param CollectionFactory $orderCollectionFactory
     */
    public function __construct(
        \Aws\MigrationDataProcessor\Migration\SalesOrder $salesOrderMigration,
        \Aws\MigrationDataProcessor\Migration\AdditionalOrderData $additionalOrderDataMigration,
        GridPoolResource $gridPoolResource,
        CollectionFactory $orderCollectionFactory,
        LogLevelProcessor $progressBar,
        ResourceConnection $resourceConnection,
        Config $config,
        Manager $logManager,
        Progress $progress
    ) {
        $this->salesOrderMigration = $salesOrderMigration;
        $this->additionalOrderDataMigration = $additionalOrderDataMigration;
        $this->gridPoolResource = $gridPoolResource;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->progressBar = $progressBar;
        $this->resourceConnection = $resourceConnection;

        parent::__construct($config, $logManager, $progress);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('order-data:process')->setDescription('Refactor orders data before import');
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->additionalOrderDataMigration->initMap($this->config);
        $this->additionalOrderDataMigration->perform();

        $this->salesOrderMigration->initMap($this->config);
        $this->salesOrderMigration->perform();

        $this->refreshOrderGrid();
    }

    protected function refreshOrderGrid()
    {
        $orders = $this->orderCollectionFactory
            ->create()
            ->addFieldToFilter('store_id', ['in' => [7, 8]])
            ->getAllIds();

         $connection = $this->resourceConnection->getConnection();
         $sqlCommand = "SELECT entity_id FROM sales_order_grid WHERE store_id IN (7, 8)";
         $gridOrderIds = $connection->fetchCol($sqlCommand);

        $this->progressBar->start(count($orders));
        foreach ($orders as $id) {
            if (!in_array($id, $gridOrderIds)) {
                $this->gridPoolResource->refreshByOrderId($id);
            }
            $this->progressBar->advance();
        }
        $this->progressBar->finish();
    }
}
