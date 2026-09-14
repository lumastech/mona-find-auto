<?php

declare(strict_types=1);

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Money\MoneyException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A throwaway model so the cast can be exercised against a real column
 * before any module has one.
 */
final class PricedThing extends Model
{
    protected $table = 'priced_things';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price' => MoneyCast::class];
    }
}

beforeEach(function () {
    Schema::create('priced_things', function (Blueprint $table): void {
        $table->id();
        $table->integer('price')->nullable();
    });
});

it('stores a Money object as integer ngwee', function () {
    $thing = PricedThing::query()->create(['price' => Money::ofKwacha('10.75')]);

    expect($thing->getRawOriginal('price'))->toBe(1075)
        ->and($thing->fresh()->price)->toBeInstanceOf(Money::class)
        ->and($thing->fresh()->price->ngwee)->toBe(1075);
});

it('accepts a kwacha decimal string', function () {
    $thing = PricedThing::query()->create(['price' => '1299.05']);

    expect($thing->fresh()->price->ngwee)->toBe(129905);
});

it('accepts an integer as ngwee', function () {
    $thing = PricedThing::query()->create(['price' => 1075]);

    expect($thing->fresh()->price->ngwee)->toBe(1075);
});

it('keeps null null', function () {
    $thing = PricedThing::query()->create(['price' => null]);

    expect($thing->fresh()->price)->toBeNull();
});

it('refuses a float on the way in', function () {
    expect(fn () => PricedThing::query()->create(['price' => 10.75]))
        ->toThrow(MoneyException::class);
});

it('refuses a value it cannot read', function () {
    expect(fn () => PricedThing::query()->create(['price' => ['ngwee' => 1075]]))
        ->toThrow(MoneyException::class);
});
