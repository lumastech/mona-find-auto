<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Admin\Database\Seeders\ContentPageSeeder;
use App\Modules\Catalog\Database\Seeders\PartCategorySeeder;
use App\Modules\Catalog\Models\Category;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Messaging\Models\Message;
use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Models\Cart;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * The journeys the API coverage audit (docs/API_COVERAGE.md) found missing.
 *
 * The first of them is the one that mattered: registration left an account
 * Pending, and there was no endpoint anywhere under /api/v1 that could prove
 * a phone number. A mobile client could create an account and then had
 * nowhere to go.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
});

describe('registration can now be finished from the app', function (): void {
    it('registers, sends a code, and activates the account when it is right', function (): void {
        $token = $this->postJson(route('api.v1.auth.register'), registrationPayload())
            ->assertCreated()
            ->json('data.token');

        $user = User::query()->sole();

        expect($user->status)->toBe(AccountStatus::Pending)
            ->and($user->hasVerifiedPhone())->toBeFalse();

        $this->withToken($token)
            ->getJson(route('api.v1.phone.show'))
            ->assertOk()
            ->assertJsonPath('data.verified', false);

        $code = codeSentTo($user->phone);

        $this->withToken($token)
            ->postJson(route('api.v1.phone.verify'), ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        expect($user->refresh()->hasVerifiedPhone())->toBeTrue()
            ->and($user->status)->toBe(AccountStatus::Active);
    });

    it('refuses a wrong code without activating anything', function (): void {
        $user = User::factory()->pending()->create();

        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.phone.verify'), ['code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        expect($user->refresh()->hasVerifiedPhone())->toBeFalse();
    });

    it('lets a social signup set the number it never had', function (): void {
        $user = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);

        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.phone.store'), ['phone' => '0977123456'])
            ->assertOk()
            ->assertJsonPath('data.sent', true);

        expect($user->refresh()->phone)->toBe('+260977123456');
    });
});

describe('an app user can get back into their account', function (): void {
    it('sends a reset code and changes the password', function (): void {
        $user = User::factory()->create(['phone' => '+260977123456']);

        $this->postJson(route('api.v1.auth.password.request'), ['phone' => '0977123456'])
            ->assertOk()
            ->assertJsonPath('data.sent', true);

        $this->postJson(route('api.v1.auth.password.reset'), [
            'phone' => '0977123456',
            'code' => codeSentTo('+260977123456'),
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        expect(Hash::check('a-brand-new-password', $user->refresh()->password))->toBeTrue();
    });

    it('answers the same for a number it has never seen', function (): void {
        /*
         * Otherwise the endpoint is a way to test whether somebody has a
         * MonaFind account, one guessable number at a time.
         */
        $known = $this->postJson(route('api.v1.auth.password.request'), ['phone' => '0977123456']);

        User::factory()->create(['phone' => '+260966111222']);

        $unknown = $this->postJson(route('api.v1.auth.password.request'), ['phone' => '0966111222']);

        expect($known->json('data.message'))->toBe($unknown->json('data.message'));
    });
});

describe('the pages an app has to be able to show', function (): void {
    it('serves the terms and privacy notice with their version numbers', function (): void {
        $this->seed(ContentPageSeeder::class);

        $this->getJson(route('api.v1.pages.index'))
            ->assertOk()
            ->assertJsonPath('data.0.slug', fn (string $slug): bool => $slug !== '');

        /*
         * Registration records consent against the CURRENT version, so an app
         * that cannot fetch the text is asking people to agree to something
         * it cannot show them.
         */
        $this->getJson(route('api.v1.pages.show', 'privacy'))
            ->assertOk()
            ->assertJsonPath('data.slug', 'privacy')
            ->assertJsonPath('data.version', 1)
            ->assertJsonStructure(['data' => ['title', 'body', 'version']]);
    });

    it('does not serve a draft staff are still writing', function (): void {
        $this->getJson(route('api.v1.pages.show', 'not-a-page'))->assertNotFound();
    });
});

describe('browsing and shopping', function (): void {
    it('browses the category tree and the listings under one', function (): void {
        $this->seed(PartCategorySeeder::class);

        $this->getJson(route('api.v1.categories.index'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['tree', 'popular_makes']]);

        $category = Category::query()->first();

        $this->getJson(route('api.v1.categories.show', $category->slug))
            ->assertOk()
            ->assertJsonPath('meta.category.slug', $category->slug);
    });

    it('empties a whole cart in one call', function (): void {
        $user = User::factory()->create();
        Cart::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->deleteJson(route('api.v1.cart.clear'))
            ->assertOk()
            ->assertJsonPath('data.is_empty', true)
            ->assertJsonPath('data.line_count', 0);
    });

    it('sends an enquiry to a seller', function (): void {
        $seller = Seller::factory()->create(['verification_status' => VerificationStatus::Verified]);
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.sellers.enquiries.store', $seller), [
            'message' => 'Do you have this for a 2008 Hilux? I can collect today.',
        ])->assertCreated();

        /*
         * Asserted against Messaging rather than Shopping's own table:
         * MessagingServiceProvider binds over Shopping's stub channel, so a
         * live enquiry becomes a thread the seller reads on the web — which
         * is the whole point of routing the app through the same contract.
         */
        expect(Message::query()->where('user_id', $user->id)->count())->toBe(1);
    });

    it('will not write to a shop that is not publicly visible', function (): void {
        /*
         * Submitted and under-review shops ARE publicly visible — a buyer can
         * write to a shop whose paperwork is still being checked. Suspended
         * is the one that is not.
         */
        $seller = Seller::factory()->create(['verification_status' => VerificationStatus::Suspended]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('api.v1.sellers.enquiries.store', $seller), ['message' => 'Hello there.'])
            ->assertNotFound();
    });
});

describe('after an order', function (): void {
    it('downloads the receipt as a PDF', function (): void {
        $order = Order::factory()->paid()->create();

        Sanctum::actingAs($order->buyer);

        $response = $this->get(route('api.v1.orders.receipt', $order))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        expect($response->streamedContent())->toStartWith('%PDF-');
    });

    it('will not hand a receipt to somebody else', function (): void {
        $order = Order::factory()->paid()->create();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson(route('api.v1.orders.receipt', $order))->assertForbidden();
    });

    it('reports a review', function (): void {
        $order = Order::factory()->paid()->create();
        $user = $order->buyer;

        $rating = Rating::factory()->create([
            'ratee_type' => $user->getMorphClass(),
            'ratee_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.ratings.report', $rating), [
            'reason' => 'abusive',
            'details' => 'This review calls me a thief and names my employer.',
        ])->assertStatus(202);
    });
});

describe('managing your own account', function (): void {
    it('updates the profile and says the new number needs proving', function (): void {
        $user = User::factory()->create(['phone' => '+260977123456']);

        Sanctum::actingAs($user);

        $this->patchJson(route('api.v1.profile.update'), [
            ...registrationPayload(['email' => $user->email, 'phone' => '0966111222']),
        ])
            ->assertOk()
            ->assertJsonPath('data.phone_verification_required', true);

        expect($user->refresh()->phone)->toBe('+260966111222')
            ->and($user->hasVerifiedPhone())->toBeFalse();
    });

    it('changes the password and keeps the caller signed in', function (): void {
        $user = User::factory()->create(['password' => 'password']);

        Sanctum::actingAs($user);

        $this->putJson(route('api.v1.profile.password'), [
            'current_password' => 'password',
            'password' => 'a-completely-new-one',
            'password_confirmation' => 'a-completely-new-one',
        ])->assertOk();

        expect(Hash::check('a-completely-new-one', $user->refresh()->password))->toBeTrue();
    });

    it('refuses a password change that cannot prove the old one', function (): void {
        $user = User::factory()->create(['password' => 'password']);

        Sanctum::actingAs($user);

        $this->putJson(route('api.v1.profile.password'), [
            'current_password' => 'not-the-password',
            'password' => 'a-completely-new-one',
            'password_confirmation' => 'a-completely-new-one',
        ])->assertUnprocessable();
    });
});
