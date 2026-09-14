<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where "my account" goes, whoever asks for it.
 *
 * /dashboard is Fortify's home (config/fortify.php) and so the landing place
 * after every login, password confirmation and intended() redirect. Staff do
 * not buy parts, so handing them the buyer's account page — in the storefront
 * shell, with an empty orders card — puts the console a navigation step away
 * from the screen they just signed in to reach. They are sent to it instead.
 *
 * Sellers are not redirected: a seller account is also a buyer account, and
 * the storefront header already carries its own link to the seller portal.
 */
class DashboardController extends Controller
{
    public function __invoke(): Response|RedirectResponse
    {
        if (Gate::allows('staff')) {
            return redirect()->route('admin.dashboard');
        }

        return Inertia::render('storefront/Dashboard');
    }
}
