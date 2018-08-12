<?php
namespace Aws\MigrationDataProcessor\Migration\Handler;

use Migration\Handler\AbstractHandler;
use Migration\ResourceModel\Record;
use Magento\Framework\App\ResourceConnection;

/**
 * Handler to transform field according to the map
 */
class ConvertAddressId extends AbstractHandler
{

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $resourceConnection;

    protected $addressType;

    public function __construct($addressType, ResourceConnection $resourceConnection) {
        $this->addressType = $addressType;
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
            $value = $this->getAddressId($recordToHandle->getValue('entity_id'));
        }
        $recordToHandle->setValue($this->field, $value);
    }

    protected function getAddressId($orderId)
    {
        $connection = $this->resourceConnection->getConnection();
        $sqlCommand = "SELECT entity_id FROM sales_order_address WHERE parent_id = {$orderId} AND address_type = '{$this->addressType}'";
        return $connection->fetchOne($sqlCommand);
    }
}
