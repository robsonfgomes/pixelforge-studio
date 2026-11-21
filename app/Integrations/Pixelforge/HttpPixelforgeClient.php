<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge;

use App\Domain\Generations\Data\GenerationRequestData;
use App\Domain\Generations\Data\ProviderGenerationData;
use App\Domain\Pricing\Data\PriceTableData;
use App\Integrations\Pixelforge\Exceptions\PixelforgeOverloaded;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRateLimited;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRejected;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

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

        return PriceTableData::fromArray($this->decodedBody($response));
    }

    public function createGeneration(GenerationRequestData $request, string $generationId): ProviderGenerationData
    {
        try {
            $response = $this->request()
                ->withHeaders(['Idempotency-Key' => $generationId])
                ->post('generations', [
                    'prompt' => $request->prompt,
                    'size' => $request->size->value,
                    'quality' => $request->quality->value,
                    'reference_images' => $request->referenceImages,
                    'metadata' => ['generation_id' => $generationId],
                ]);
        } catch (ConnectionException $exception) {
            throw PixelforgeUnavailable::connectionFailed($exception);
        }

        return $this->generationFrom($response);
    }

    public function retrieveGeneration(string $pixelforgeId): ProviderGenerationData
    {
        try {
            $response = $this->request()->get('generations/'.rawurlencode($pixelforgeId));
        } catch (ConnectionException $exception) {
            throw PixelforgeUnavailable::connectionFailed($exception);
        }

        return $this->generationFrom($response);
    }

    private function generationFrom(Response $response): ProviderGenerationData
    {
        if (! $response->successful()) {
            throw $this->errorFor($response);
        }

        try {
            return ProviderGenerationData::fromArray($this->decodedBody($response));
        } catch (PixelforgeUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw PixelforgeUnavailable::invalidBody($exception);
        }
    }

    private function request(): PendingRequest
    {
        $request = $this->http->baseUrl($this->baseUrl)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->timeoutSeconds)
            ->acceptJson();

        return $this->apiKey === null || $this->apiKey === '' ? $request : $request->withToken($this->apiKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedBody(Response $response): array
    {
        $body = json_decode($response->body(), true);

        if (! is_array($body)) {
            throw PixelforgeUnavailable::invalidBody();
        }

        return $body;
    }

    private function errorFor(Response $response): RuntimeException
    {
        $code = $response->json('error.code');
        $code = is_string($code) ? $code : 'unknown';
        $message = $response->json('error.message');
        $message = is_string($message) ? $message : "Pixelforge answered with HTTP {$response->status()}.";
        $retryAfterSeconds = $this->retryAfterSeconds($response);

        return match (true) {
            $response->status() === 429 && $code !== 'daily_limit_exceeded' => new PixelforgeRateLimited(
                $code,
                $message,
                $retryAfterSeconds,
            ),
            $response->status() === 503 && $code === 'overloaded' => new PixelforgeOverloaded(
                $message,
                $retryAfterSeconds,
            ),
            $response->clientError() => new PixelforgeRejected($response->status(), $code, $message),
            default => PixelforgeUnavailable::unexpectedStatus($response->status()),
        };
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $retryAfter = $response->header('Retry-After');

        return preg_match('/^\d+$/', $retryAfter) === 1 ? (int) $retryAfter : null;
    }
}
