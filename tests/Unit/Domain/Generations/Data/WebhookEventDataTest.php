<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generations\Data;

use App\Domain\Generations\Data\WebhookEventData;
use App\Domain\Generations\Exceptions\InvalidWebhookEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class WebhookEventDataTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[TestWith([['type' => 'ping']])]
    #[TestWith([['id' => 'evt_1']])]
    #[TestWith([['id' => '', 'type' => 'ping']])]
    public function itRejectsABodyThatIsNotAnEvent(array $payload): void
    {
        $this->expectException(InvalidWebhookEvent::class);

        WebhookEventData::fromPayload($payload, deliveryAttempt: 1);
    }
}
