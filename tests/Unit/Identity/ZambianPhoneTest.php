<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\MobileNetwork;
use App\Modules\Identity\Support\ZambianPhone;

it('normalises every shape a Zambian number is written in', function (string $input) {
    expect(ZambianPhone::tryParse($input)?->e164())->toBe('+260977123456');
})->with([
    'trunk zero' => '0977123456',
    'spaced trunk zero' => '0977 123 456',
    'hyphenated' => '0977-123-456',
    'country code, no plus' => '260977123456',
    'country code with plus' => '+260977123456',
    'international 00 prefix' => '00260977123456',
    'international, spaced' => '+260 977 123 456',
]);

it('identifies the network from the subscriber prefix', function (string $input, MobileNetwork $network) {
    expect(ZambianPhone::tryParse($input)?->network)->toBe($network);
})->with([
    'MTN 096' => ['0961234567', MobileNetwork::Mtn],
    'MTN 076' => ['0761234567', MobileNetwork::Mtn],
    'Airtel 097' => ['0971234567', MobileNetwork::Airtel],
    'Airtel 077' => ['0771234567', MobileNetwork::Airtel],
    'Zamtel 095' => ['0951234567', MobileNetwork::Zamtel],
    'Zamtel 075' => ['0751234567', MobileNetwork::Zamtel],
]);

it('rejects anything that is not a Zambian mobile number', function (?string $input) {
    expect(ZambianPhone::tryParse($input))->toBeNull()
        ->and(ZambianPhone::isValid($input))->toBeFalse();
})->with([
    'null' => null,
    'empty' => '',
    'too short' => '097712345',
    'too long' => '09771234567',
    'unissued prefix' => '0991234567',
    'landline' => '0211123456',
    'foreign country code' => '+27821234567',
    'not a number' => 'not a phone',
]);

it('renders a number the way Zambians read it', function () {
    $phone = ZambianPhone::tryParse('+260977123456');

    expect($phone->national())->toBe('0977 123 456')
        ->and($phone->masked())->toBe('0977 ••• 456')
        ->and((string) $phone)->toBe('+260977123456');
});
