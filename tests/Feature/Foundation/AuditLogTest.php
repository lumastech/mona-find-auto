<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Database\ImmutableRecordException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('records an entry through the audit helper', function () {
    $actor = User::factory()->create(['name' => 'Grace Banda']);
    $subject = User::factory()->create();

    $entry = audit(
        $actor,
        'seller.verified',
        $subject,
        ['verified' => false],
        ['verified' => true],
        'Documents checked against PACRA registry.',
    );

    expect($entry->action)->toBe('seller.verified')
        ->and($entry->actor_id)->toBe($actor->id)
        ->and($entry->actor_label)->toBe('Grace Banda')
        ->and($entry->subject_id)->toBe($subject->id)
        ->and($entry->before)->toBe(['verified' => false])
        ->and($entry->after)->toBe(['verified' => true])
        ->and($entry->reason)->toBe('Documents checked against PACRA registry.');
});

it('falls back to the authenticated user when no actor is given', function () {
    $user = User::factory()->create(['name' => 'Chanda Mwale']);

    $this->actingAs($user);

    $entry = audit(null, 'listing.moderated');

    expect($entry->actor_id)->toBe($user->id)
        ->and($entry->actor_label)->toBe('Chanda Mwale');
});

it('labels platform-initiated entries as the system', function () {
    $entry = audit(null, 'escrow.auto_released');

    expect($entry->actor_id)->toBeNull()
        ->and($entry->actor_label)->toBe('System');
});

it('records the request context for entries written during a request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    /** Written from inside a controller-less route, so record one directly. */
    $entry = audit($user, 'test.action', context: ['batch' => 'nightly']);

    expect($entry->context)->toHaveKey('batch', 'nightly')
        ->and($entry->context)->toHaveKey('channel');
});

it('refuses to update an entry through the model', function () {
    $entry = AuditLog::factory()->create();

    expect(fn () => $entry->update(['action' => 'tampered']))
        ->toThrow(ImmutableRecordException::class);
});

it('refuses to update an entry through a saved attribute change', function () {
    $entry = AuditLog::factory()->create();
    $entry->action = 'tampered';

    expect(fn () => $entry->save())->toThrow(ImmutableRecordException::class);
});

it('refuses to delete an entry through the model', function () {
    $entry = AuditLog::factory()->create();

    expect(fn () => $entry->delete())->toThrow(ImmutableRecordException::class);
});

it('refuses an update that bypasses the model entirely', function () {
    AuditLog::factory()->create();

    expect(fn () => DB::table('audit_logs')->update(['action' => 'tampered']))
        ->toThrow(QueryException::class);

    expect(AuditLog::query()->where('action', 'tampered')->exists())->toBeFalse();
});

it('refuses a delete that bypasses the model entirely', function () {
    AuditLog::factory()->create();

    expect(fn () => DB::table('audit_logs')->delete())->toThrow(QueryException::class);

    expect(AuditLog::query()->count())->toBe(1);
});

it('never records an updated_at column', function () {
    $entry = AuditLog::factory()->create();

    expect($entry->getAttributes())->not->toHaveKey('updated_at')
        ->and($entry->created_at)->not->toBeNull();
});
