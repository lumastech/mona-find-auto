<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Mechanics\Enums\EndorsementStatus;
use App\Modules\Mechanics\Exceptions\EndorsementNotAllowed;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Notifications\EndorsementDecided;
use App\Modules\Mechanics\Notifications\EndorsementRequestReceived;
use App\Modules\Mechanics\Services\EndorsementService;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Support\Facades\Notification;

/*
 * The rule under test: a badge saying "Endorsed by Kabwata Motors" has to
 * mean Kabwata Motors said so. Only the addressed shop can answer a request
 * and only the granting shop can take it back — checked through the service,
 * through the policy, and through the HTTP routes, because each is a separate
 * way in.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->endorsements = app(EndorsementService::class);

    $this->profile = MechanicProfile::factory()->withSpecialities()->create();
    $this->mechanic = $this->profile->user;

    $this->seller = Seller::factory()->create(['business_name' => 'Kabwata Motors']);
    $this->shopkeeper = $this->seller->user;

    $this->otherSeller = Seller::factory()->create(['business_name' => 'Chelston Spares']);
    $this->otherShopkeeper = $this->otherSeller->user;
});

it('lets an approved mechanic ask a listed shop, and tells the shop', function (): void {
    $endorsement = $this->endorsements->request($this->profile, $this->seller, $this->mechanic, 'I rebuild gearboxes for your customers.');

    expect($endorsement->status)->toBe(EndorsementStatus::Requested)
        ->and($endorsement->requested_by)->toBe($this->mechanic->getKey());

    Notification::assertSentTo($this->shopkeeper, EndorsementRequestReceived::class);

    $this->assertDatabaseHas('audit_logs', ['action' => 'mechanic.endorsement.requested']);
});

it('refuses a request to a shop that is not publicly listed', function (): void {
    $draft = Seller::factory()->create(['verification_status' => VerificationStatus::Draft]);

    expect(fn () => $this->endorsements->request($this->profile, $draft, $this->mechanic))
        ->toThrow(EndorsementNotAllowed::class);
});

it('refuses a second request while the first is unanswered', function (): void {
    $this->endorsements->request($this->profile, $this->seller, $this->mechanic);

    expect(fn () => $this->endorsements->request($this->profile, $this->seller, $this->mechanic))
        ->toThrow(EndorsementNotAllowed::class, 'already asked');

    expect(MechanicEndorsement::query()->count())->toBe(1);
});

it('lets only the addressed shop endorse', function (): void {
    $endorsement = $this->endorsements->request($this->profile, $this->seller, $this->mechanic);

    expect(fn () => $this->endorsements->endorse($endorsement, $this->otherSeller, $this->otherShopkeeper))
        ->toThrow(EndorsementNotAllowed::class, 'Only the shop this request was sent to');

    expect($endorsement->refresh()->status)->toBe(EndorsementStatus::Requested);

    $this->endorsements->endorse($endorsement, $this->seller, $this->shopkeeper);

    expect($endorsement->refresh()->status)->toBe(EndorsementStatus::Endorsed)
        ->and($endorsement->decided_by)->toBe($this->shopkeeper->getKey());

    Notification::assertSentTo($this->mechanic, EndorsementDecided::class);
});

it('refuses another shop the endorse and revoke routes', function (): void {
    $endorsement = MechanicEndorsement::factory()->between($this->profile, $this->seller)->requested()->create();

    $this->actingAs($this->otherShopkeeper)
        ->post(route('seller.endorsements.endorse', $endorsement))
        ->assertForbidden();

    expect($endorsement->refresh()->status)->toBe(EndorsementStatus::Requested);

    $endorsement->forceFill(['status' => EndorsementStatus::Endorsed, 'endorsed_at' => now()])->save();

    $this->actingAs($this->otherShopkeeper)
        ->post(route('seller.endorsements.revoke', $endorsement), ['reason' => 'Not our mechanic at all.'])
        ->assertForbidden();

    expect($endorsement->refresh()->status)->toBe(EndorsementStatus::Endorsed);
});

it('puts a badge on the profile when endorsed and takes it off when revoked', function (): void {
    $endorsement = MechanicEndorsement::factory()->between($this->profile, $this->seller)->create();

    $this->getJson(route('api.v1.mechanics.show', $this->profile))
        ->assertOk()
        ->assertJsonCount(1, 'data.endorsements')
        ->assertJsonPath('data.endorsements.0.label', 'Endorsed by Kabwata Motors');

    $this->endorsements->revoke($endorsement, $this->seller, $this->shopkeeper, 'They no longer work out of our yard.');

    $this->getJson(route('api.v1.mechanics.show', $this->profile))
        ->assertOk()
        ->assertJsonCount(0, 'data.endorsements');

    /* The row survives the withdrawal — it is a rating source, and a record. */
    expect($endorsement->refresh()->status)->toBe(EndorsementStatus::Revoked)
        ->and($endorsement->revocation_reason)->toBe('They no longer work out of our yard.');

    $this->assertDatabaseHas('audit_logs', ['action' => 'mechanic.endorsement.revoked']);
});

it('will not revoke something that was never endorsed', function (): void {
    $endorsement = MechanicEndorsement::factory()->between($this->profile, $this->seller)->requested()->create();

    expect(fn () => $this->endorsements->revoke($endorsement, $this->seller, $this->shopkeeper, 'Changed our minds.'))
        ->toThrow(EndorsementNotAllowed::class);
});

it('will not answer a request twice', function (): void {
    $endorsement = MechanicEndorsement::factory()->between($this->profile, $this->seller)->requested()->create();

    $this->endorsements->decline($endorsement, $this->seller, $this->shopkeeper, 'We have not worked with them.');

    expect(fn () => $this->endorsements->endorse($endorsement->refresh(), $this->seller, $this->shopkeeper))
        ->toThrow(EndorsementNotAllowed::class, 'already been answered');
});

it('lets a mechanic ask again after a decline, reusing the row and clearing the old answer', function (): void {
    $endorsement = MechanicEndorsement::factory()->between($this->profile, $this->seller)->requested()->create();

    $this->endorsements->decline($endorsement, $this->seller, $this->shopkeeper, 'Not this year.');

    $again = $this->endorsements->request($this->profile, $this->seller, $this->mechanic, 'We have worked together since.');

    expect($again->getKey())->toBe($endorsement->getKey())
        ->and($again->status)->toBe(EndorsementStatus::Requested)
        ->and($again->response_note)->toBeNull()
        ->and($again->decided_by)->toBeNull()
        ->and(MechanicEndorsement::query()->count())->toBe(1);
});

it('holds multiple endorsements on one profile and lists each', function (): void {
    MechanicEndorsement::factory()->between($this->profile, $this->seller)->create();
    MechanicEndorsement::factory()->between($this->profile, $this->otherSeller)->create();

    $this->getJson(route('api.v1.mechanics.show', $this->profile))
        ->assertOk()
        ->assertJsonCount(2, 'data.endorsements')
        ->assertJsonPath('data.endorsements.0.seller.business_name', 'Chelston Spares')
        ->assertJsonPath('data.endorsements.1.seller.business_name', 'Kabwata Motors');
});

it('shows a shop its own queue and nobody else\'s', function (): void {
    MechanicEndorsement::factory()->between($this->profile, $this->seller)->requested()->create();

    $other = MechanicProfile::factory()->withSpecialities()->create();
    MechanicEndorsement::factory()->between($other, $this->otherSeller)->requested()->create();

    $this->actingAs($this->shopkeeper)
        ->get(route('seller.endorsements.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('pending', 1));
});

it('refuses an unapproved mechanic the request route', function (): void {
    $draft = MechanicProfile::factory()->withSpecialities()->draft()->create();

    $this->actingAs($draft->user)
        ->post(route('mechanics.endorsements.store'), ['seller_id' => $this->seller->getKey()])
        ->assertForbidden();

    expect(MechanicEndorsement::query()->count())->toBe(0);
});

it('refuses an account with no profile at all', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('mechanics.endorsements.store'), ['seller_id' => $this->seller->getKey()])
        ->assertNotFound();
});
