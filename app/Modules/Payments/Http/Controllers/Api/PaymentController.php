<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Payments\Exceptions\PaymentUnavailable;
use App\Modules\Payments\Http\Requests\Api\CollectMobileMoneyRequest;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CollectionService;
use App\Modules\Payments\Services\WidgetConfigurator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Paying, for the mobile app.
 *
 * The app has no browser to open the inline widget in, so the mobile-money
 * path here is server-initiated: it pushes a USSD prompt to the customer's
 * handset and returns a pending payment the app polls. The card path still
 * needs the widget, so `intent` hands back the same public-key config the web
 * checkout uses and the app opens it in a web view.
 */
class PaymentController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly CollectionService $collections,
        private readonly WidgetConfigurator $widget,
    ) {}

    /**
     * Everything needed to start paying, without starting yet.
     */
    public function intent(Request $request, OrderGroup $group): JsonResponse
    {
        $this->authoriseGroup($request, $group);

        $attempt = $this->collections->begin($group);

        return ApiResponse::ok([
            'group' => [
                'publicId' => $group->public_id,
                'totalNgwee' => $group->total_ngwee->ngwee,
            ],
            'widget' => $this->widget->forAttempt($group->refresh(), $attempt, $this->currentUser($request)),
        ]);
    }

    /**
     * Push a PIN prompt to a phone.
     */
    public function mobileMoney(CollectMobileMoneyRequest $request, OrderGroup $group): JsonResponse
    {
        $this->authoriseGroup($request, $group);

        try {
            $payment = $this->collections->collectMobileMoney(
                $group,
                $request->phone(),
                $request->network(),
            );
        } catch (PaymentUnavailable $exception) {
            return ApiResponse::error('payment_unavailable', $exception->getMessage(), status: 422);
        }

        return ApiResponse::created(PaymentResource::make($payment)->resolve($request));
    }

    /**
     * Where has it got to? Asks the gateway, not our own row.
     */
    public function status(Request $request, OrderGroup $group): JsonResponse
    {
        $this->authoriseGroup($request, $group);

        $reference = $this->collections->currentReference($group);

        if ($reference === null) {
            return ApiResponse::error('no_attempt', __('No payment has been started for this order.'), status: 404);
        }

        $payment = $this->collections->verify($reference);

        return ApiResponse::ok([
            'payment' => PaymentResource::make($payment)->resolve($request),
            'isPaid' => $group->refresh()->isPaid(),
        ]);
    }

    /**
     * The buyer's payment history.
     */
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->where('user_id', $this->currentUser($request)->getKey())
            ->latest('id')
            ->paginate(25);

        return ApiResponse::paginated($payments->through(
            static fn (Payment $payment): PaymentResource => PaymentResource::make($payment),
        ));
    }

    private function authoriseGroup(Request $request, OrderGroup $group): void
    {
        if (! $this->collections->canBePaidBy($group, $this->currentUser($request))) {
            throw new AccessDeniedHttpException('This is not your order.');
        }
    }
}
