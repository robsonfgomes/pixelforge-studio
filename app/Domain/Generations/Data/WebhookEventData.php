<?php

declare(strict_types=1);

namespace App\Domain\Generations\Data;

use UnexpectedValueException;

final readonly class WebhookEventData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public int $deliveryAttempt,
        public ?ProviderGenerationData $generation,
        public array $payload,
    ) {}

    /**
     * Generation events (`generation.*`) carry the full generation object in `data.object`; other events carry none.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, int $deliveryAttempt): self
    {
        $id = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($type) || $type === '') {
            throw new UnexpectedValueException('The webhook body is not a Pixelforge event.');
        }

        $isGenerationEvent = str_starts_with($type, 'generation.');

        return new self(
            $id,
            $type,
            $deliveryAttempt,
            $isGenerationEvent ? ProviderGenerationData::fromArray($payload['data']['object']) : null,
            $payload,
        );
    }
}
