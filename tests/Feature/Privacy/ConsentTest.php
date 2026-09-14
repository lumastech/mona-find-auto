<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Admin\Database\Seeders\ContentPageSeeder;
use App\Modules\Admin\Models\ContentPage;
use App\Modules\Privacy\Enums\ConsentType;
use App\Modules\Privacy\Models\ConsentRecord;
use App\Modules\Privacy\Services\ConsentRecorder;
use App\Support\Database\ImmutableRecordException;
use Database\Seeders\SettingsSeeder;

beforeEach(function (): void {
    $this->seed([SettingsSeeder::class, ContentPageSeeder::class]);
});

it('records the terms, the privacy notice and the marketing answer at registration', function (): void {
    $this->post(route('register.store'), registrationPayload(['accept_marketing' => true]))
        ->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'chanda@example.test')->sole();

    $records = ConsentRecord::query()->where('user_id', $user->id)->get();

    expect($records)->toHaveCount(3)
        ->and($records->firstWhere('type', ConsentType::Terms)->granted)->toBeTrue()
        ->and($records->firstWhere('type', ConsentType::Privacy)->granted)->toBeTrue()
        ->and($records->firstWhere('type', ConsentType::Marketing)->granted)->toBeTrue()
        ->and($records->pluck('source')->unique()->all())->toBe(['registration']);
});

it('records a refusal of marketing as a row, not as an absence', function (): void {
    $this->post(route('register.store'), registrationPayload(['accept_marketing' => false]));

    $user = User::query()->sole();

    $marketing = ConsentRecord::query()
        ->where('user_id', $user->id)
        ->where('type', ConsentType::Marketing)
        ->sole();

    /*
     * The distinction the Act turns on: a "no" that was asked and answered,
     * versus a question never put to the person.
     */
    expect($marketing->granted)->toBeFalse();
});

it('refuses a registration that does not accept the terms or the privacy notice', function (string $field): void {
    $this->post(route('register.store'), registrationPayload([$field => false]))
        ->assertSessionHasErrors($field);

    expect(User::query()->count())->toBe(0)
        ->and(ConsentRecord::query()->count())->toBe(0);
})->with(['accept_terms', 'accept_privacy']);

it('stamps the version of the document that was on screen', function (): void {
    $this->post(route('register.store'), registrationPayload());

    $user = User::query()->sole();

    $privacy = ConsentRecord::query()
        ->where('user_id', $user->id)
        ->where('type', ConsentType::Privacy)
        ->sole();

    expect($privacy->document_slug)->toBe('privacy')
        ->and($privacy->document_version)->toBe(1);
});

it('is append-only: a consent record can never be edited or removed', function (): void {
    $record = ConsentRecord::factory()->create();

    expect(fn () => $record->update(['granted' => false]))
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $record->delete())
        ->toThrow(ImmutableRecordException::class);
});

it('reads the newest row as the current position', function (): void {
    $user = User::factory()->create();
    $consents = app(ConsentRecorder::class);

    $consents->record($user, ConsentType::Marketing, true, 'registration');
    expect($consents->hasGranted($user, ConsentType::Marketing))->toBeTrue();

    $this->travel(1)->minutes();
    $consents->record($user, ConsentType::Marketing, false, 'settings');

    expect($consents->hasGranted($user, ConsentType::Marketing))->toBeFalse()
        /* And the earlier grant is still on the record. */
        ->and(ConsentRecord::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('notices when the document has moved on since a person agreed to it', function (): void {
    $user = User::factory()->create();
    $consents = app(ConsentRecorder::class);

    ConsentRecord::factory()->ofType(ConsentType::Privacy)->atVersion(1)->create(['user_id' => $user->id]);

    expect($consents->needsReconsent($user, ConsentType::Privacy))->toBeFalse();

    /* Staff publish a new version of the notice. */
    $page = ContentPage::query()->where('slug', 'privacy')->sole();
    $version = $page->versions()->create([
        'version' => 2,
        'title' => $page->title,
        'body' => 'A revised notice.',
        'published_at' => now(),
    ]);
    $page->forceFill(['current_version_id' => $version->id])->save();

    expect($consents->needsReconsent($user, ConsentType::Privacy))->toBeTrue();
});

it('lets a person withdraw marketing from the settings screen', function (): void {
    $user = User::factory()->create();

    app(ConsentRecorder::class)->record($user, ConsentType::Marketing, true, 'registration');

    $this->actingAs($user)
        ->put(route('privacy.consents.update'), [
            'type' => ConsentType::Marketing->value,
            'granted' => false,
        ])
        ->assertSessionHasNoErrors();

    expect(app(ConsentRecorder::class)->hasGranted($user, ConsentType::Marketing))->toBeFalse();
});

it('refuses to withdraw a consent the account cannot exist without', function (string $type): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('privacy.consents.update'), ['type' => $type, 'granted' => false])
        ->assertSessionHasErrors('type');

    expect(ConsentRecord::query()->count())->toBe(0);
})->with([ConsentType::Terms->value, ConsentType::Privacy->value]);
