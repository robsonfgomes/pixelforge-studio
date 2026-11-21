<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Generations\Actions\RecordWebhookEvent;
use App\Domain\Generations\Data\WebhookEventData;
use App\Integrations\Pixelforge\Exceptions\InvalidPixelforgeSignature;
use App\Integrations\Pixelforge\Exceptions\PixelforgeWebhookSecretMissing;
use App\Integrations\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WebhookSignature $signature,
        RecordWebhookEvent $recordWebhookEvent,
    ): Response {
        $rawBody = $request->getContent();

        try {
            $signature->verify($rawBody, $request->header('Pixelforge-Signature'));
        } catch (PixelforgeWebhookSecretMissing $exception) {
            Log::error($exception->getMessage());

            return response('Webhook secret not configured.', Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (InvalidPixelforgeSignature) {
            return response('Invalid Pixelforge signature.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $event = WebhookEventData::fromPayload(
                json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR),
                (int) ($request->header('Pixelforge-Delivery-Attempt') ?? 1),
            );
        } catch (Throwable $exception) {
            Log::error('A correctly signed Pixelforge webhook is not a valid event.', [
                'reason' => $exception->getMessage(),
            ]);

            return response('Invalid Pixelforge event.', Response::HTTP_BAD_REQUEST);
        }

        $recordWebhookEvent->handle($event);

        return response()->noContent();
    }
}
