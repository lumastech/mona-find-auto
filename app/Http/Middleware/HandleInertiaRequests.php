<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Admin\Services\AnnouncementBoard;
use App\Support\Captcha\CaptchaGuard;
use App\Support\Roles\Role;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'roles' => $user instanceof User ? $user->getRoleNames()->values()->all() : [],
                'isSeller' => $user instanceof User && $user->hasAnyRole(Role::sellerPortal()),
                'isStaff' => $user instanceof User && $user->hasAnyRole(Role::staffConsole()),
            ],
            'platform' => [
                'currency' => config('monafind.currency'),
                'timezone' => config('monafind.display_timezone'),
            ],
            /*
             * The public site key and the list of forms that carry a
             * challenge — never the secret, which stays server-side.
             *
             * Shared rather than passed per page so a form can render a
             * widget without its controller having to know the captcha
             * exists; on a deployment that challenges nobody, `siteKey` is
             * null and every widget renders as nothing.
             */
            'captcha' => fn (): array => [
                'siteKey' => app(CaptchaGuard::class)->siteKey(),
                'forms' => app(CaptchaGuard::class)->protectedForms(),
            ],
            /*
             * Only settings explicitly marked public reach the browser; the
             * lookup is cached, so this costs nothing per request.
             */
            'settings' => fn (): array => app(SettingsRepository::class)->publicValues(),
            /*
             * The banner staff scheduled for whichever area this request is
             * in. Cached for a minute inside the board — see its docblock for
             * why a scheduled banner is worth a lookup on every page.
             */
            'announcements' => fn (): array => app(AnnouncementBoard::class)->forRequest($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
