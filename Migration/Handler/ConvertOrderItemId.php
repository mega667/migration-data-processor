<?php
namespace Aws\MigrationDataProcessor\Migration\Handler;

use Migration\Handler\AbstractHandler;
use Migration\ResourceModel\Record;
use Magento\Framework\App\ResourceConnection;

/**
 * Handler to transform field according to the map
 */
class ConvertOrderItemId extends AbstractHandler
{

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $resourceConnection;

    protected $lastItemId = 0;

    public function __construct(ResourceConnection $resourceConnection) {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Record $recordToHandle, Record $oppositeRecord)
    {
        $this->validate($recordToHandle);
        $value = $recordToHandle->getValue($this->field);
        if (null !== $value) {
            $value += $this->getLastOrderEntityId();
        }
        $recordToHandle->setValue($this->field, $value);
    }

    protected function getLastOrderEntityId()
    {
        if ($this->lastItemId == 0) {
            $connection = $this->resourceConnection->getConnection();
            $sqlCommand = "SELECT MAX(item_id) FROM sales_order_item";
            $this->lastItemId = $connection->fetchOne($sqlCommand);
        }
        return $this->lastItemId;
    }
}
