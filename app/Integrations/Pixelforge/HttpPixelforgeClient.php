<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge;

use App\Domain\Pricing\Data\PriceTableData;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

final readonly class HttpPixelforgeClient implements PixelforgeApi
{
    public function __construct(
        private HttpFactory $http,
        private string $baseUrl,
        private ?string $apiKey,
        private int $connectTimeoutSeconds,
        private int $timeoutSeconds,
    ) {}

    public function pricing(): PriceTableData
    {
        try {
            $response = $this->request()->get('pricing');
        } catch (ConnectionException $exception) {
            throw PixelforgeUnavailable::connectionFailed($exception);
        }

        if (! $response->successful()) {
            throw PixelforgeUnavailable::unexpectedStatus($response->status());
        }

        $body = json_decode($response->body(), true);

        if (! is_array($body)) {
            throw PixelforgeUnavailable::invalidBody();
        }

        return PriceTableData::fromArray($body);
    }

    private function request(): PendingRequest
    {
        $request = $this->http->baseUrl($this->baseUrl)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->timeoutSeconds)
            ->acceptJson();

        return $this->apiKey === null || $this->apiKey === '' ? $request : $request->withToken($this->apiKey);
    }
}
