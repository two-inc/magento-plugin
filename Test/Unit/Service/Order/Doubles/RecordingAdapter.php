<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order\Doubles;

use Two\Gateway\Service\Api\Adapter;

/**
 * Records what reached the wire, including the per-call timeout a mock of the
 * real adapter would silently accept and drop.
 */
class RecordingAdapter extends Adapter
{
    /** @var list<array{endpoint: string, payload: array<string, mixed>, timeout: int|null}> */
    public array $calls = [];

    /** @var array<string, mixed> */
    private array $response;

    /**
     * @param array<string, mixed> $response
     */
    public function __construct(array $response)
    {
        $this->response = $response;
    }

    public function execute(
        string $endpoint,
        array $payload = [],
        string $method = 'POST',
        ?int $storeId = null,
        ?string $apiKeyOverride = null,
        ?string $modeOverride = null,
        ?int $timeoutSeconds = null
    ): array {
        $this->calls[] = [
            'endpoint' => $endpoint,
            'payload' => $payload,
            'timeout' => $timeoutSeconds,
        ];
        return $this->response;
    }
}
