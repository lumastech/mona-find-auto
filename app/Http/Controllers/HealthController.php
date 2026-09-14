<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Health\HealthReport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /health — is this instance actually able to do its job?
 *
 * The sibling of `/up`, which Laravel wires up in bootstrap/app.php and which
 * answers whether PHP is running. That is the right check for a process
 * supervisor and the wrong one for a load balancer: an instance that boots,
 * answers 200 and cannot reach MySQL will be sent traffic it cannot serve.
 *
 * ## 503 when something critical is down
 *
 * The status code is the part that matters, because it is the part
 * infrastructure reads. A failing critical check answers 503 so the instance
 * is taken out of rotation; a failing non-critical one still answers 200
 * with the detail in the body, so a Meilisearch outage does not empty the
 * load balancer.
 *
 * ## Never cached, and never public without a token
 *
 * The body names which dependencies exist and which are broken, which is a
 * map worth having if you are attacking the platform. `HEALTH_CHECK_TOKEN`
 * gates it; where none is configured the endpoint is open, which is the
 * right default for a local install and is flagged on the launch checklist.
 */
class HealthController extends Controller
{
    public function __invoke(HealthReport $report): JsonResponse
    {
        $body = $report->toArray();

        return response()
            ->json(
                $body,
                $body['status'] === 'ok' ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
            )
            /*
             * A cached health check is worse than none: it reports the state
             * of the instance at some point in the past, with confidence.
             */
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
