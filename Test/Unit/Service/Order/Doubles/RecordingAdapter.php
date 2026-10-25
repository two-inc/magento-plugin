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
    /** @var list<array{endpoint: string, payload: array<string, mixed>, timeout: int|null, idempotency_key: string|null}> */
    public array $calls = [];

    private ?string $nextIdempotencyKey = null;

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
            'idempotency_key' => $this->nextIdempotencyKey,
        ];
        $this->nextIdempotencyKey = null;
        return $this->response;
    }

    /** Records the key on the next call rather than handing out a copy. */
    public function withIdempotencyKey(string $key): Adapter
    {
        $this->nextIdempotencyKey = $key;
        return $this;
    }
}
