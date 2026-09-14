<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Services\WebhookIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/lenco.
 *
 * ## Deliberately does almost nothing
 *
 * Verify, store, queue, 200. Every second spent here is a second Lenco waits
 * before deciding we are down and redelivering, so the actual work happens in
 * ProcessLencoWebhook. A handler that did real work inside the request would
 * turn one slow database call into a retry storm.
 *
 * ## Why the signature is checked against the RAW body
 *
 * `$request->getContent()`, not the parsed array re-encoded. PHP's json_decode
 * and json_encode do not round-trip byte-for-byte — key order, unicode escapes
 * and float formatting all shift — so a signature checked against re-encoded
 * JSON would reject perfectly good webhooks and, worse, could be made to
 * accept a body that differs from the one that was signed.
 *
 * ## 401 for a bad signature, 200 for a duplicate
 *
 * An unsigned or wrongly signed request is rejected outright and never
 * touches the database. A redelivery of something already stored gets a 200,
 * because telling Lenco an event failed on the grounds that we already have
 * it would only make them send it again.
 */
class LencoWebhookController extends Controller
{
    public function __construct(private readonly WebhookIngestor $ingestor) {}

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header('x-lenco-signature');

        if (! $this->ingestor->isAuthentic($rawBody, $signature)) {
            /*
             * Logged without the body: an unverified payload is unattributed
             * input, and copying it into the logs is how a forged webhook
             * becomes a log-injection problem.
             */
            Log::warning('Rejected a Lenco webhook with an invalid signature.', [
                'ip' => $request->ip(),
                'has_signature' => $signature !== null,
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Malformed payload.'], 400);
        }

        $event = $this->ingestor->ingest($payload, $rawBody);

        return response()->json([
            'received' => true,
            /* Tells Lenco's dashboard, and us, that a redelivery was recognised. */
            'duplicate' => $event === null,
        ]);
    }
}
