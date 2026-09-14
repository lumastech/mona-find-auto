<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\CaptchaVerifier;
use App\Contracts\MapsProvider;
use App\Contracts\PaymentGateway;
use App\Contracts\SmsProvider;
use App\Integrations\Captcha\FakeCaptchaVerifier;
use App\Integrations\Captcha\NullCaptchaVerifier;
use App\Integrations\Captcha\TurnstileVerifier;
use App\Integrations\Maps\FakeMapsProvider;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\LencoGateway;
use App\Integrations\Sms\FakeSmsProvider;
use App\Integrations\Sms\LogSmsProvider;
use App\Integrations\Sms\ZamtelSmsProvider;
use App\Support\Captcha\CaptchaGuard;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Binds each third-party integration interface to the implementation named in
 * config/integrations.php.
 *
 * The live drivers are contributed as the modules that need them are built:
 * `lenco` by Payments, `zamtel` by Messaging. `google` is still to come from
 * Sellers, so the maps integration resolves only its fake.
 *
 * The captcha follows the same shape, with one difference worth knowing: its
 * default driver challenges nobody rather than pretending to. A deployment
 * without Turnstile keys gets a working signup form, not a broken one.
 */
class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function (): PaymentGateway {
            return match ($driver = $this->driver('payment_gateway')) {
                'lenco' => $this->lenco(),
                'fake' => new FakePaymentGateway,
                default => throw $this->unsupported('payment gateway', $driver, ['lenco', 'fake']),
            };
        });

        $this->app->singleton(SmsProvider::class, function (): SmsProvider {
            return match ($driver = $this->driver('sms')) {
                'zamtel' => $this->zamtel(),
                'log' => new LogSmsProvider,
                'fake', 'array' => new FakeSmsProvider,
                default => throw $this->unsupported('SMS provider', $driver, ['zamtel', 'log', 'fake']),
            };
        });

        $this->app->singleton(MapsProvider::class, function (): MapsProvider {
            return match ($driver = $this->driver('maps')) {
                'fake' => new FakeMapsProvider,
                default => throw $this->unsupported('maps provider', $driver, ['fake']),
            };
        });

        $this->app->singleton(CaptchaVerifier::class, function (): CaptchaVerifier {
            return match ($driver = $this->driver('captcha')) {
                'turnstile' => $this->turnstile(),
                'fake' => new FakeCaptchaVerifier,
                'null', 'none' => new NullCaptchaVerifier,
                default => throw $this->unsupported('captcha driver', $driver, ['turnstile', 'fake', 'null']),
            };
        });

        /*
         * The policy over the driver. A singleton so that a test binding a
         * FakeCaptchaVerifier gets a guard that uses it, rather than one
         * holding the instance resolved before the swap.
         */
        $this->app->singleton(CaptchaGuard::class);
    }

    /**
     * The live challenge, built from config/integrations.php.
     *
     * Missing keys are a configuration error worth failing loudly on: a
     * Turnstile driver with no secret would verify nothing while looking like
     * it was protecting the form.
     */
    private function turnstile(): TurnstileVerifier
    {
        $siteKey = (string) config('integrations.captcha.turnstile.site_key');
        $secretKey = (string) config('integrations.captcha.turnstile.secret_key');

        if ($siteKey === '' || $secretKey === '') {
            throw new InvalidArgumentException(
                'The turnstile captcha driver needs both TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY.',
            );
        }

        return new TurnstileVerifier(
            secretKey: $secretKey,
            siteKey: $siteKey,
            timeout: (int) config('integrations.captcha.turnstile.timeout', 5),
            expectedHostname: config('integrations.captcha.turnstile.hostname'),
        );
    }

    /**
     * The live gateway, built from config/lenco.php.
     *
     * The environment picks the endpoints so that the API host and the widget
     * host can never be chosen separately — a sandbox widget talking to a live
     * API is a class of bug worth making unrepresentable.
     */
    private function lenco(): LencoGateway
    {
        $environment = (string) config('lenco.environment', 'sandbox');

        return new LencoGateway(
            baseUrl: (string) config("lenco.endpoints.{$environment}.api"),
            secretKey: (string) config('lenco.secret_key'),
            accountId: config('lenco.account_id'),
            country: (string) config('lenco.country', 'zm'),
            /* Staff-owned; config only supplies the seeded default. */
            defaultBearer: (string) settings('payments.fee_bearer', config('lenco.collections.bearer', 'merchant')),
            timeout: (int) config('lenco.http.timeout', 30),
            retryTimes: (int) config('lenco.http.retry_times', 2),
            retrySleepMs: (int) config('lenco.http.retry_sleep_ms', 200),
            webhookSecret: config('lenco.webhook_secret'),
        );
    }

    /**
     * The live SMS network, built from config/integrations.php.
     *
     * The sender ID is read from the shared `sms.sender_id` rather than from
     * the Zamtel block, because it is what recipients see and it should not
     * change when the network behind it does.
     */
    private function zamtel(): ZamtelSmsProvider
    {
        return new ZamtelSmsProvider(
            baseUrl: (string) config('integrations.sms.zamtel.base_url'),
            apiKey: (string) config('integrations.sms.zamtel.api_key'),
            senderId: (string) config('integrations.sms.sender_id', 'MonaFind'),
            username: config('integrations.sms.zamtel.username'),
            timeout: (int) config('integrations.sms.zamtel.timeout', 15),
        );
    }

    private function driver(string $integration): string
    {
        $driver = config("integrations.{$integration}.driver");

        return is_string($driver) ? $driver : 'fake';
    }

    /**
     * @param  array<int, string>  $supported
     */
    private function unsupported(string $integration, string $driver, array $supported): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Unsupported %s driver [%s]. Available drivers: %s.',
            $integration,
            $driver,
            implode(', ', $supported),
        ));
    }
}
