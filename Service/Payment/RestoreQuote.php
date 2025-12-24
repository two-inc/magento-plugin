<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Service\Payment;

use Magento\Catalog\Model\Indexer\Product\Price\Processor as ProductPriceProcessor;
use Magento\CatalogInventory\Api\StockManagementInterface;
use Magento\CatalogInventory\Model\Indexer\Stock\Processor as StockIndexerProcessor;
use Magento\CatalogInventory\Observer\ProductQty;
use Magento\Checkout\Model\Session;
use ABN\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Restore Quote Service
 */
class RestoreQuote
{
    /**
     * @var StockManagementInterface
     */
    protected $stockManagement;
    /**
     * @var StockIndexerProcessor
     */
    protected $stockIndexerProcessor;
    /**
     * @var ProductPriceProcessor
     */
    protected $priceIndexer;
    /**
     * @var Session
     */
    private $session;
    /**
     * @var ConfigRepository
     */
    private $configRepository;
    /**
     * @var ProductQty
     */
    private $productQty;

    /**
     * RestoreQuote constructor.
     *
     * @param Session $session
     * @param ConfigRepository $configRepository
     * @param ProductQty $productQty
     * @param StockManagementInterface $stockManagement
     * @param StockIndexerProcessor $stockIndexerProcessor
     * @param ProductPriceProcessor $priceIndexer
     */
    public function __construct(
        Session $session,
        ConfigRepository $configRepository,
        ProductQty $productQty,
        StockManagementInterface $stockManagement,
        StockIndexerProcessor $stockIndexerProcessor,
        ProductPriceProcessor $priceIndexer
    ) {
        $this->session = $session;
        $this->configRepository = $configRepository;
        $this->productQty = $productQty;
        $this->stockManagement = $stockManagement;
        $this->stockIndexerProcessor = $stockIndexerProcessor;
        $this->priceIndexer = $priceIndexer;
    }

    /**
     * Restore quote
     *
     * @return bool
     */
    public function execute()
    {
        $result = $this->session->restoreQuote();
        // Versions 2.2.4 onwards need an explicit action to return items.
        if ($result && $this->isReturnItemsToInventoryRequired()) {
            $this->returnItemsToInventory();
        }

        return $result;
    }

    /**
     * Checks if version requires restore quote fix.
     *
     * @return bool
     */
    private function isReturnItemsToInventoryRequired()
    {
        $version = $this->configRepository->getMagentoVersion();
        return version_compare($version, "2.2.4", ">=");
    }

    /**
     * Returns items to inventory.
     */
    private function returnItemsToInventory()
    {
        // Code from \Magento\CatalogInventory\Observer\RevertQuoteInventoryObserver
        $quote = $this->session->getQuote();
        $items = $this->productQty->getProductQty($quote->getAllItems());
        $revertedItems = $this->stockManagement->revertProductsSale($items, $quote->getStore()->getWebsiteId());

        // If the Magento 2 server has multi source inventory enabled,
        // the revertProductsSale method is intercepted with new logic that returns a boolean.
        // In such case, no further action is necessary.
        if (is_bool($revertedItems)) {
            return;
        }

        $productIds = array_keys($revertedItems);
        if (!empty($productIds)) {
            $this->stockIndexerProcessor->reindexList($productIds);
            $this->priceIndexer->reindexList($productIds);
        }
        // Clear flag, so if order placement retried again with success - it will be processed
        $quote->setInventoryProcessed(false);
    }
}
