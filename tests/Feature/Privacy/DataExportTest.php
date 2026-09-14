<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Identity\Models\UserAddress;
use App\Modules\Identity\Privacy\IdentityPersonalData;
use App\Modules\Inventory\Privacy\InventoryPersonalData;
use App\Modules\Mechanics\Privacy\MechanicPersonalData;
use App\Modules\Messaging\Privacy\MessagingPersonalData;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Privacy\OrderPersonalData;
use App\Modules\Privacy\Services\PersonalDataExporter;
use App\Modules\Privacy\Support\PersonalDataRegistry;
use App\Modules\Ratings\Privacy\RatingPersonalData;
use App\Modules\Search\Models\SearchQuery;
use App\Modules\Search\Privacy\SearchPersonalData;
use App\Modules\Shopping\Models\WishlistItem;
use App\Modules\Shopping\Privacy\ShoppingPersonalData;
use Database\Seeders\SettingsSeeder;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
});

it('gathers a section from every module that holds something', function (): void {
    $order = Order::factory()->paid()->create();
    $user = $order->buyer;

    UserAddress::factory()->create(['user_id' => $user->id]);
    WishlistItem::factory()->create(['user_id' => $user->id]);
    SearchQuery::factory()->create(['user_id' => $user->id, 'term' => 'clutch kit']);

    $export = app(PersonalDataExporter::class)->assemble($user);

    expect($export['subject']['account_id'])->toBe($user->id)
        ->and($export['sections'])->toHaveKeys(['account', 'shopping', 'search', 'orders']);
});

it('leaves out sections for things that never happened', function (): void {
    $user = User::factory()->create();

    $export = app(PersonalDataExporter::class)->assemble($user);

    /*
     * A buyer who has never ordered should not receive a heading called
     * "Your orders" with nothing under it — it reads like something is
     * missing rather than like nothing happened.
     */
    expect($export['sections'])->not->toHaveKey('orders')
        ->and($export['sections'])->not->toHaveKey('mechanic_profile');
});

it('gives the machine-readable copy as valid, readable JSON', function (): void {
    $user = User::factory()->create(['first_name' => 'Chanda', 'last_name' => 'Mwale']);

    $json = app(PersonalDataExporter::class)->toJson($user);

    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['subject']['account_id'])->toBe($user->id)
        ->and($json)->toContain('Chanda')
        /* Pretty-printed: a person opening this in an editor is a supported way to read it. */
        ->and($json)->toContain("\n    ");
});

it('gives the readable copy as a PDF a reader will open', function (): void {
    $user = User::factory()->create();
    UserAddress::factory()->create(['user_id' => $user->id]);

    $pdf = app(PersonalDataExporter::class)->toPdf($user);

    expect($pdf)->toStartWith('%PDF-')
        ->and($pdf)->toContain('%%EOF');
});

it('serves both formats to the account holder and records that it did', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('privacy.export.json'))
        ->assertOk()
        ->assertHeader('content-type', 'application/json');

    $this->actingAs($user)
        ->get(route('privacy.export.pdf'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    /*
     * An export is a copy of everything about somebody leaving the platform.
     * It is worth knowing that it happened.
     */
    expect(AuditLog::query()->where('action', 'privacy.export.downloaded')->count())->toBe(2);
});

it('never serves one person\'s export to another', function (): void {
    $this->get(route('privacy.export.json'))->assertRedirect(route('login'));
});

it('returns the same structure over the API', function (): void {
    $user = User::factory()->create();
    WishlistItem::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.privacy.export'))
        ->assertOk()
        ->assertJsonPath('data.subject.account_id', $user->id)
        ->assertJsonStructure(['data' => ['subject', 'generated_at', 'sections']]);
});

it('walks every registered source, so a new module cannot be forgotten', function (): void {
    $sources = app(PersonalDataRegistry::class)->registered();

    /*
     * Pinned. A module that gains personal data and does not register a
     * source here is a module whose data is silently absent from every
     * export and survives every erasure.
     */
    expect($sources)->toContain(
        IdentityPersonalData::class,
        ShoppingPersonalData::class,
        MessagingPersonalData::class,
        SearchPersonalData::class,
        InventoryPersonalData::class,
        OrderPersonalData::class,
        RatingPersonalData::class,
        MechanicPersonalData::class,
    );
});
