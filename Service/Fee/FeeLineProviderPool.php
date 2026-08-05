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
     * @param LogRepository|null $logRepository
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
                            . 'gross_amount or tax_amount; dropped: %s',
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
     * A provider-returned line must at minimum carry numeric gross_amount
     * and tax_amount — those are what getOtherChargesLineItem() sums to
     * compute the residual. A missing/non-numeric value would silently
     * cast to 0 there, either double-counting (if the real amount never
     * makes it into $lineItems at all) or masking a real residual.
     *
     * @param mixed $line
     * @return bool
     */
    private function isWellFormed($line): bool
    {
        return is_array($line)
            && isset($line['gross_amount'], $line['tax_amount'])
            && is_numeric($line['gross_amount'])
            && is_numeric($line['tax_amount']);
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
