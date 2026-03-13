<?php
/**
 * RegenerateProductRewrites.php
 *
 * @package OlegKoval_RegenerateUrlRewrites
 * @author Oleg Koval <olegkoval.ca@gmail.com>
 * @copyright 2017-2067 Oleg Koval
 * @license OSL-3.0, AFL-3.0
 */

namespace OlegKoval\RegenerateUrlRewrites\Model;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Action;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use OlegKoval\RegenerateUrlRewrites\Helper\Regenerate as RegenerateHelper;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\ActionFactory as ProductActionFactory;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGeneratorFactory;
use Magento\CatalogUrlRewrite\Model\ProductUrlPathGeneratorFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;

class RegenerateProductRewrites extends AbstractRegenerateRewrites
{
    /**
     * @var string
     */
    protected $entityType = 'product';

    /**
     * @var int
     */
    protected $productsCollectionPageSize = 1000;

    /**
     * @var ProductActionFactory
     */
    protected $productActionFactory;

    /**
     * @var Action
     */
    protected $productAction;

    /**
     * @var ProductUrlRewriteGeneratorFactory
     */
    protected $productUrlRewriteGeneratorFactory;

    /**
     * @var ProductUrlRewriteGenerator
     */
    protected $productUrlRewriteGenerator;

    /**
     * @var ProductUrlPathGeneratorFactory
     */
    protected $productUrlPathGeneratorFactory;

    /**
     * @var ProductUrlPathGenerator
     */
    protected $productUrlPathGenerator;

    /**
     * @var ProductCollectionFactoryy
     */
    protected $productCollectionFactory;

    /**
     * RegenerateProductRewrites constructor.
     *
     * @param RegenerateHelper $helper
     * @param ResourceConnection $resourceConnection
     * @param ProductActionFactory $productActionFactory
     * @param ProductUrlRewriteGeneratorFactory\Proxy $productUrlRewriteGeneratorFactory
     * @param ProductUrlPathGeneratorFactory\Proxy $productUrlPathGeneratorFactory
     * @param ProductCollectionFactory $productCollectionFactory
     */
    public function __construct(
        RegenerateHelper                        $helper,
        ResourceConnection                      $resourceConnection,
        ProductActionFactory                    $productActionFactory,
        ProductUrlRewriteGeneratorFactory\Proxy $productUrlRewriteGeneratorFactory,
        ProductUrlPathGeneratorFactory\Proxy    $productUrlPathGeneratorFactory,
        ProductCollectionFactory                $productCollectionFactory
    )
    {
        parent::__construct($helper, $resourceConnection);

        $this->productActionFactory = $productActionFactory;
        $this->productUrlRewriteGeneratorFactory = $productUrlRewriteGeneratorFactory;
        $this->productUrlPathGeneratorFactory = $productUrlPathGeneratorFactory;
        $this->productCollectionFactory = $productCollectionFactory;
    }

    /**
     * Regenerate Products Url Rewrites in specific store
     *
     * @return $this
     */
    public function regenerate(int $storeId = 0): static
    {
        if (count($this->regenerateOptions['productsFilter']) > 0) {
            $this->regenerateProductsRangeUrlRewrites(
                $this->regenerateOptions['productsFilter'],
                $storeId
            );
        } elseif (!empty($this->regenerateOptions['productId'])) {
            $this->regenerateSpecificProductUrlRewrites(
                $this->regenerateOptions['productId'],
                $storeId
            );
        } else {
            $this->regenerateAllProductsUrlRewrites($storeId);
        }

        return $this;
    }

    /**
     * @param int $storeId
     * @return $this
     */
    public function regenerateAllProductsUrlRewrites(int $storeId = 0): static
    {
        $this->regenerateProductsRangeUrlRewrites([], $storeId);

        return $this;
    }

    /**
     * Regenerate Url Rewrites for a specific product
     *
     * @param int $productId
     * @param int $storeId
     * @return $this
     */
    public function regenerateSpecificProductUrlRewrites(int $productId, int $storeId = 0): static
    {
        $this->regenerateProductsRangeUrlRewrites([$productId], $storeId);

        return $this;
    }

    /**
     * Regenerate Url Rewrites for a product range
     *
     * @param array $productsFilter
     * @param int $storeId
     * @return $this
     */
    public function regenerateProductsRangeUrlRewrites(array $productsFilter = [], int $storeId = 0): static
    {
        $products = $this->_getProductsCollection($productsFilter, $storeId);
        $pageCount = $products->getLastPageNumber();
        $this->progressBarProgress = 1;
        $this->progressBarTotal = (int)$products->getSize();
        $currentPage = 1;

        while ($currentPage <= $pageCount) {
            $products->clear();
            $products->setCurPage($currentPage);

            foreach ($products as $product) {
                $this->_showProgress();
                $this->processProduct($product, $storeId);
            }

            $currentPage++;
        }

        $this->_updateSecondaryTable();

        return $this;
    }

    /**
     * @param $entity
     * @param int $storeId
     * @return $this
     */
    public function processProduct($entity, int $storeId = 0): static
    {
        $entity->setStoreId($storeId)->setData('url_path', null);

        if ($this->regenerateOptions['saveOldUrls']) {
            $entity->setData('save_rewrites_history', true);
        }

        // reset url_path to null, we need this to set a flag to use an Url Rewrites:
        // see logic in a core Product Url model: \Magento\Catalog\Model\Product\Url::getUrl()
        // if "request_path" is not null or equal to "false" then Magento do not search and do not use Url Rewrites
        $updateAttributes = ['url_path' => null];
        if (!$this->regenerateOptions['noRegenUrlKey']) {
            $generatedKey = $this->_getProductUrlPathGenerator()->getUrlKey($entity->setUrlKey(null));
            $updateAttributes['url_key'] = $generatedKey;
        }

        try {
            $urlRewrites = $this->_getProductUrlRewriteGenerator()->generate($entity);
            $urlRewrites = $this->helper->sanitizeProductUrlRewrites($urlRewrites);

            if (!empty($urlRewrites)) {
                $savedRewites = $this->saveUrlRewrites(
                    $urlRewrites,
                    [['entity_type' => $this->entityType, 'entity_id' => $entity->getId(), 'store_id' => $storeId]]
                );

                if (!$this->regenerateOptions['noRegenUrlKey'] && $savedRewites) {
                    // Update Product url_key's with updated request_path
                    $this->updateProductUrlKeys($savedRewites->getSavedUrlRewrites());
                }
            }
        } catch (\Exception $e) {
            sprintf("Error. processProduct:%s\n", $e->getMessage());
            // go to the next product
        }

        $this->progressBarProgress++;

        return $this;
    }

    /**
     * @param array $savedRewites
     */
    protected function updateProductUrlKeys(array $savedRewites): void
    {
        foreach ($savedRewites as $savedRewrite) {

            // Skip unchanged url_key
            if ($savedRewrite['original_request_path'] === $savedRewrite['request_path']) {
                continue;
            }

            try {
                $entityId         = $savedRewrite['entity_id'];
                $storeId          = $savedRewrite['store_id'];
                $productUrlSuffix = $this->helper->getProductUrlSuffix($storeId);

                // Replace $productUrlSuffix from $savedRewrite['request_path']
                // By default: Product URL must be written excluding $productUrlSuffix
                $updateAttributes['url_key'] = preg_replace(
                    sprintf('/%s$/', $productUrlSuffix),
                    '',
                    $savedRewrite['request_path']
                );

                $this->_getProductAction()->updateAttributes(
                    [$entityId],
                    $updateAttributes,
                    $storeId
                );
            } catch (\Exception $e) {
                echo sprintf("Error. updateProductUrlKeys: %s\n", $e->getMessage());
                // go to the next product
            }
        }
    }

    /**
     * @return Action
     */
    protected function _getProductAction(): Action
    {
        if (is_null($this->productAction)) {
            $this->productAction = $this->productActionFactory->create();
        }

        return $this->productAction;
    }

    /**
     * @return ProductUrlRewriteGenerator
     */
    protected function _getProductUrlRewriteGenerator(): ProductUrlRewriteGenerator
    {
        if (is_null($this->productUrlRewriteGenerator)) {
            $this->productUrlRewriteGenerator = $this->productUrlRewriteGeneratorFactory->create();
        }

        return $this->productUrlRewriteGenerator;
    }

    /**
     * @return ProductUrlPathGenerator
     */
    protected function _getProductUrlPathGenerator(): ProductUrlPathGenerator
    {
        if (is_null($this->productUrlPathGenerator)) {
            $this->productUrlPathGenerator = $this->productUrlPathGeneratorFactory->create();
        }

        return $this->productUrlPathGenerator;
    }

    /**
     * Get products collection
     *
     * @param array $productsFilter
     * @param int $storeId
     * @return Collection
     */
    protected function _getProductsCollection(array $productsFilter = [], int $storeId = 0): Collection
    {
        $productsCollection = $this->productCollectionFactory->create();

        $productsCollection->setStore($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToSelect('name')
            ->addAttributeToSelect('visibility')
            ->addAttributeToSelect('url_key')
            ->addAttributeToSelect('url_path')
            ->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE])
            // use limit to avoid an "eating" of a memory
            ->setPageSize($this->productsCollectionPageSize);

        if (count($productsFilter) > 0) {
            $productsCollection->addIdFilter($productsFilter);
        }

        return $productsCollection;
    }
}
