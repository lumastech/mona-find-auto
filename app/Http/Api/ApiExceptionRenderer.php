<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns any exception thrown inside /api/v1 into the standard error envelope,
 * so an API consumer never receives an HTML error page or a bare framework
 * payload.
 */
final class ApiExceptionRenderer
{
    public function __invoke(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $this->handles($request)) {
            return null;
        }

        return match (true) {
            $exception instanceof ValidationException => ApiResponse::error(
                'validation_failed',
                'The given data was invalid.',
                ['fields' => $exception->errors()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ),
            $exception instanceof AuthenticationException => ApiResponse::error(
                'unauthenticated',
                'This endpoint requires an authenticated API token.',
                status: Response::HTTP_UNAUTHORIZED,
            ),
            $exception instanceof AuthorizationException => ApiResponse::error(
                'forbidden',
                $exception->getMessage() !== '' ? $exception->getMessage() : 'You are not allowed to do that.',
                status: Response::HTTP_FORBIDDEN,
            ),
            $exception instanceof ModelNotFoundException, $exception instanceof NotFoundHttpException => ApiResponse::error(
                'not_found',
                'The requested resource does not exist.',
                status: Response::HTTP_NOT_FOUND,
            ),
            $exception instanceof HttpExceptionInterface => ApiResponse::error(
                $this->codeForStatus($exception->getStatusCode()),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                status: $exception->getStatusCode(),
            ),
            default => $this->renderUnexpected($exception),
        };
    }

    private function handles(Request $request): bool
    {
        return $request->is('api/*');
    }

    private function renderUnexpected(Throwable $exception): JsonResponse
    {
        $details = config('app.debug') === true
            ? [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile().':'.$exception->getLine(),
            ]
            : [];

        return ApiResponse::error(
            'server_error',
            'Something went wrong on our side. The problem has been logged.',
            $details,
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    private function codeForStatus(int $status): string
    {
        return match ($status) {
            Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
            Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
            Response::HTTP_CONFLICT => 'conflict',
            Response::HTTP_SERVICE_UNAVAILABLE => 'service_unavailable',
            default => 'request_failed',
        };
    }
}
