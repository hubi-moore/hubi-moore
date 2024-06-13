<?php
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\Action;

require 'app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

/** @var State $appState */
$appState = $objectManager->get(State::class);
$appState->setAreaCode('frontend');

/** @var StoreManagerInterface $storeManager */
$storeManager = $objectManager->get(StoreManagerInterface::class);

/** @var ProductRepositoryInterface $productRepository */
$productRepository = $objectManager->get(ProductRepositoryInterface::class);

/** @var ResourceConnection $resource */
$resource = $objectManager->get(ResourceConnection::class);
$connection = $resource->getConnection();

/** @var Action $productAction */
$productAction = $objectManager->get(Action::class);

$originStoreCode = 'pl'; # 
$targetStoreCode = 'en';
$attributeCode = 'short_description';

# Pobieranie ID store view
$originStore = $storeManager->getStore($originStoreCode);
$targetStore = $storeManager->getStore($targetStoreCode);

$originStoreId = $originStore->getId();
$targetStoreId = $targetStore->getId();

# Gather all product IDs
$productIds = $connection->fetchCol(
    $connection->select()
        ->from($resource->getTableName('catalog_product_entity'), 'entity_id')
);

foreach ($productIds as $productId) {
    try {
        $originProduct = $productRepository->getById($productId, false, $originStoreId);
        $attributeValue = $originProduct->getData($attributeCode);
        
        # Set provided product attribute value to specific store view 
        $productAction->updateAttributes(
            [$productId],
            [$attributeCode => $attributeValue],
            $targetStoreId
        );

        echo "$attributeCode ($originStoreCode) value has been copied to $productId ($targetStoreCode)\n";
    } catch (Exception $e) {
        echo "Error for Product ID: $productId - " . $e->getMessage() . "\n";
    }
}
echo "That's all folks\n";
