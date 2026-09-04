<?php
/**
 * Stubs for the Magento collaborators the self-invoice-upload flow
 * (Service/Invoice/UploadService.php, Cron/ProcessInvoiceUploads.php)
 * depends on. The bootstrap catch-all autoloader only produces empty,
 * method-less classes/interfaces, which PHPUnit's createMock() cannot
 * configure methods on — these stubs declare the real method surface
 * so the mocks in UploadServiceTest / ProcessInvoiceUploadsTest work.
 */
declare(strict_types=1);

namespace Magento\Sales\Model\Order\Status;

use Two\Gateway\Test\Stubs\AbstractSalesModelStub;

if (!class_exists(History::class, false)) {
    class History extends AbstractSalesModelStub
    {
    }
}

if (!class_exists(HistoryFactory::class, false)) {
    class HistoryFactory
    {
        public function create(): History
        {
            return new History();
        }
    }
}

namespace Magento\Sales\Api;

if (!interface_exists(OrderStatusHistoryRepositoryInterface::class, false)) {
    interface OrderStatusHistoryRepositoryInterface
    {
        public function save($entry);
    }
}

if (!interface_exists(OrderRepositoryInterface::class, false)) {
    interface OrderRepositoryInterface
    {
        public function get($id);
        public function save($order);
        public function getList($searchCriteria);
        public function delete($order);
        public function deleteById($id);
    }
}

namespace Magento\Framework\Api;

if (!class_exists(SearchCriteriaBuilder::class, false)) {
    class SearchCriteriaBuilder
    {
        public function addFilter($field, $value, $conditionType = 'eq'): self
        {
            return $this;
        }

        public function setPageSize(int $pageSize): self
        {
            return $this;
        }

        public function create()
        {
            return new SearchCriteria();
        }
    }
}

if (!class_exists(SearchCriteria::class, false)) {
    class SearchCriteria
    {
    }
}

namespace Magento\Framework\Lock;

if (!interface_exists(LockManagerInterface::class, false)) {
    interface LockManagerInterface
    {
        public function lock(string $name, int $timeout = -1): bool;
        public function unlock(string $name): bool;
        public function isLocked(string $name): bool;
    }
}

namespace Magento\Sales\Model\Order\Pdf;

if (!class_exists(Invoice::class, false)) {
    /**
     * @method array getPdf(array $invoices)
     */
    class Invoice
    {
        public function getPdf(array $invoices)
        {
            return new PdfDocumentStub();
        }
    }

    class PdfDocumentStub
    {
        public function render(): string
        {
            return '';
        }
    }
}
