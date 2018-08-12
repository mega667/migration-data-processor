<?php
namespace Aws\MigrationDataProcessor\Migration\Handler;

use Migration\Handler\AbstractHandler;
use Migration\ResourceModel\Record;
use Magento\Customer\Api\CustomerRepositoryInterface;

/**
 * Handler to transform field according to the map
 */
class ConvertCustomerId extends AbstractHandler
{

    /**
     * @var CustomerRepositoryInterface
     */
    protected $customerRepository;

    public function __construct(
       CustomerRepositoryInterface $customerRepository
    ) {
        $this->customerRepository = $customerRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Record $recordToHandle, Record $oppositeRecord)
    {
        $this->validate($recordToHandle);
        $value = $recordToHandle->getValue($this->field);
        if (null !== $value &&
            !empty($recordToHandle->getValue('store_id')) &&
            !empty($recordToHandle->getValue('customer_email'))
        ) {
            $email = $recordToHandle->getValue('customer_email');
            $websiteId = $this->getWebSiteId($recordToHandle->getValue('store_id'));
            if ($websiteId) {
                try {
                    $customer = $this->customerRepository->get($email, $websiteId);
                    $value = $customer->getId();
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                    $value = null;
                }
            } else {
                $value = null;
            }
        }
        $recordToHandle->setValue($this->field, $value);
    }

    protected function getWebSiteId($storeId)
    {
        $map = [
            8 => 3,
            7 => 4
        ];

        if (!isset($map[$storeId])) {
            return false;
        }

        return $map[$storeId];
    }
}
