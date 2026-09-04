<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Fee;

use Throwable;
use Two\Gateway\Api\Fee\FeeLineProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Aggregates all registered FeeLineProviderInterface implementations.
 *
 * Injected as a plain array via etc/di.xml so providers can be added later
 * without touching Order/ComposeOrder/ComposeCapture/ComposeRefund.
 * Defaults to an empty array — see etc/di.xml, no concrete providers are
 * wired in yet.
 *
 * Isolates each provider: a provider that throws or returns a malformed
 * line does not take down checkout/capture/refund for every other order.
 * This is the seam future (and potentially less-trusted) provider code
 * plugs into, so it doesn't get to trust its callers implicitly.
 */
class FeeLineProviderPool
{
    /**
     * @var FeeLineProviderInterface[]
     */
    private $providers;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * @param FeeLineProviderInterface[] $providers
     * @param LogRepository|null $logRepository Nullable purely for
     *        hand-instantiated/test convenience (e.g. an empty pool built
     *        inline in a test, where a throw/malformed line is impossible
     *        with zero providers). NOT a "silent logging mode" toggle —
     *        any real DI-constructed pool gets one auto-wired via the
     *        existing Api\Log\RepositoryInterface preference in
     *        etc/di.xml, since di.xml only names the `providers` argument
     *        explicitly.
     */
    public function __construct(array $providers = [], ?LogRepository $logRepository = null)
    {
        $this->providers = $providers;
        $this->logRepository = $logRepository;
    }

    /**
     * @param \Magento\Sales\Model\Order|\Magento\Sales\Model\Order\Invoice|\Magento\Sales\Model\Order\Creditmemo $entity
     * @return array[]
     */
    public function getFeeLines($entity): array
    {
        $lines = [];
        foreach ($this->providers as $provider) {
            $providerClass = get_class($provider);

            try {
                $providerLines = $provider->getFeeLines($entity);
            } catch (Throwable $e) {
                $this->log(
                    'FeeLineProviderThrew',
                    sprintf('%s::getFeeLines() threw: %s', $providerClass, $e->getMessage())
                );
                continue;
            }

            foreach ($providerLines as $line) {
                if (!$this->isWellFormed($line)) {
                    $this->log(
                        'FeeLineProviderMalformedLine',
                        sprintf(
                            '%s::getFeeLines() returned a line missing/non-numeric '
                            . 'gross_amount, net_amount, or tax_amount, or missing '
                            . 'order_item_id/type; dropped: %s',
                            $providerClass,
                            json_encode($line)
                        )
                    );
                    continue;
                }
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Enforces the full field set Api\Fee\FeeLineProviderInterface's
     * docblock promises ("real gross/net/tax amounts and tax rate"), not
     * just the two fields getOtherChargesLineItem() happens to sum.
     * gross_amount/tax_amount missing or non-numeric would silently cast
     * to 0 in that residual sum; net_amount/tax_rate missing would instead
     * surface as an undefined-array-key notice later — in
     * Order::getTaxSubtotals() (keys directly on every line) and
     * everywhere this line eventually reaches Two's API payload.
     * order_item_id/type are the two fields every other line builder in
     * this codebase always sets and Two's API needs to classify the line.
     *
     * @param mixed $line
     * @return bool
     */
    private function isWellFormed($line): bool
    {
        if (!is_array($line)) {
            return false;
        }

        foreach (['gross_amount', 'net_amount', 'tax_amount'] as $amountKey) {
            if (!isset($line[$amountKey]) || !is_numeric($line[$amountKey])) {
                return false;
            }
        }

        foreach (['order_item_id', 'type', 'tax_rate'] as $requiredKey) {
            if (!isset($line[$requiredKey]) || $line[$requiredKey] === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $type
     * @param string $message
     * @return void
     */
    private function log(string $type, string $message): void
    {
        if ($this->logRepository) {
            $this->logRepository->addErrorLog($type, $message);
        }
    }
}
