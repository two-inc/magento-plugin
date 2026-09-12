<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Controller\Adminhtml\Config;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Two\Gateway\Service\Merchant\RecordRefresher;

/**
 * AJAX endpoint behind the Diagnostics "Refresh merchant profile" button.
 *
 * Refetches every merchant profile the scope being edited governs, so an
 * admin can pull a commercial change through immediately instead of waiting
 * for the hourly refresh. Same semantics as the cron: each cached record is
 * replaced on success and left alone on failure, which is reported inline.
 */
class RefreshMerchantRecord extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Sales::config_sales';

    /** Bounds only the start of an identity, so a press costs this plus one identity's two calls. */
    private const BUDGET_SECONDS = 20.0;

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var RecordRefresher
     */
    private $recordRefresher;

    public function __construct(
        Action\Context $context,
        JsonFactory $resultJsonFactory,
        RecordRefresher $recordRefresher
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->recordRefresher = $recordRefresher;
    }

    /**
     * @return ResponseInterface|Json|ResultInterface
     */
    public function execute()
    {
        try {
            $identities = $this->recordRefresher->governedIdentities(
                (string)$this->getRequest()->getParam('scope', 'default'),
                (int)$this->getRequest()->getParam('scopeId', 0)
            );
        } catch (NoSuchEntityException $e) {
            return $this->failure((string)__('This scope no longer exists — reload the page and try again.'));
        } catch (LocalizedException $e) {
            return $this->failure($e->getMessage());
        }

        $outcome = $this->recordRefresher->refreshWithin($identities, self::BUDGET_SECONDS);
        $refreshed = array_values(array_filter($outcome['records']));
        $total = count($identities);

        if ($outcome['skipped'] > 0) {
            return $this->failure(
                (string)__(
                    'Refreshed %1 of %2 merchant profiles before the request ran out of time. Press again for the rest.',
                    count($refreshed),
                    $total
                ),
                $refreshed
            );
        }

        if ($refreshed === []) {
            return $this->failure(
                (string)__(
                    'Could not refresh the merchant profile — the previously loaded values are still in use. Check that the API key for this scope is valid and that the Two API is reachable.'
                )
            );
        }

        // Neither blanket message is true when only some refreshed.
        if (count($refreshed) < $total) {
            return $this->failure(
                (string)__(
                    'Refreshed %1 of %2 merchant profiles. The rest still use their previously loaded values — check the API key and environment on the store views that use them.',
                    count($refreshed),
                    $total
                ),
                $refreshed
            );
        }

        return $this->resultJsonFactory->create()->setData([
            'success' => true,
            'message' => (string)__('Merchant profile refreshed.'),
            'merchant' => $this->identifyAll($refreshed),
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $refreshed merchants to name, if any
     * @return ResponseInterface|Json|ResultInterface
     */
    private function failure(string $message, array $refreshed = [])
    {
        return $this->resultJsonFactory->create()->setData([
            'success' => false,
            'message' => $message,
            'merchant' => $this->identifyAll($refreshed),
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $records
     */
    private function identifyAll(array $records): string
    {
        return implode(', ', array_unique(array_filter(array_map([$this, 'identify'], $records))));
    }

    /** Which merchant was refreshed — the point of the button at a non-default scope. */
    private function identify(array $record): string
    {
        return implode(' · ', array_filter([
            trim((string)($record['short_name'] ?? '')),
            trim((string)($record['id'] ?? '')),
        ], 'strlen'));
    }
}
