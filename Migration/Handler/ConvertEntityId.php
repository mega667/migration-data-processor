<?php
namespace Aws\MigrationDataProcessor\Migration\Handler;

use Migration\Handler\AbstractHandler;
use Migration\ResourceModel\Record;
use Magento\Framework\App\ResourceConnection;

/**
 * Handler to transform field according to the map
 */
class ConvertEntityId extends AbstractHandler
{

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $resourceConnection;

    protected $table;

    protected $lastEntityId = 0;

    public function __construct($table, ResourceConnection $resourceConnection) {
        $this->table = $table;
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
        if ($this->lastEntityId == 0) {
            $connection = $this->resourceConnection->getConnection();
            $sqlCommand = "SELECT MAX(entity_id) FROM " . $this->table;
            $this->lastEntityId = $connection->fetchOne($sqlCommand);
        }
        return $this->lastEntityId;
    }
}
