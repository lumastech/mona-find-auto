<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderDocumentService;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * The receipt and the packing slip.
 *
 * A PDF is bytes, so these assertions read the text back out of the content
 * stream — which is uncompressed precisely so that it can be. What matters is
 * that the two documents say different things: money on one, a shelf and a
 * destination on the other.
 */

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->documents = app(OrderDocumentService::class);
    $this->machine = app(OrderStateMachine::class);

    $this->buyer = User::factory()->create(['name' => 'Chanda Mwale']);
    $this->seller = Seller::factory()->create(['business_name' => 'Kabwata Motors']);

    $product = Product::factory()->ofSeller($this->seller)->create(['name' => 'Alternator']);
    $this->variant = $product->variants()->first();
    $this->variant->forceFill(['price' => 120_000, 'quantity' => 5, 'sku' => 'ALT-9001'])->save();
});

/**
 * The literal strings a PDF content stream carries, joined up.
 */
function pdfText(string $pdf): string
{
    preg_match_all('/\((.*?)\) Tj/s', $pdf, $matches);

    return implode("\n", $matches[1]);
}

function documentedOrder(FulfilmentMethod $method = FulfilmentMethod::Pickup): Order
{
    $order = Order::factory()
        ->forBuyer(test()->buyer)
        ->forSeller(test()->seller)
        ->state([
            'fulfilment_method' => $method,
            'delivery_fee_ngwee' => $method === FulfilmentMethod::Delivery ? 7_500 : 0,
            'delivery_address' => $method === FulfilmentMethod::Delivery ? [
                'recipient_name' => 'Chanda Mwale',
                'recipient_phone' => '+260977123456',
                'street' => 'Kafue Road',
                'plot_number' => '42',
                'city' => 'Lusaka',
                'province' => 'Lusaka',
                'directions' => 'Blue gate opposite the filling station.',
            ] : null,
        ])
        ->create();

    OrderItem::factory()->forVariant(test()->variant, 2)->create(['order_id' => $order->getKey()]);

    $order->forceFill([
        'items_total_ngwee' => 240_000,
        'total_ngwee' => 240_000 + $order->delivery_fee_ngwee->ngwee,
    ])->save();

    return test()->machine->markPaid($order->refresh());
}

it('produces a readable PDF', function (): void {
    $pdf = $this->documents->receipt(documentedOrder());

    expect($pdf)->toStartWith('%PDF-1.4')
        ->and($pdf)->toContain('/Type /Catalog')
        ->and($pdf)->toContain('startxref')
        ->and($pdf)->toEndWith("%%EOF\n");
});

it('puts the money, the parties and the VAT note on the buyer\'s receipt', function (): void {
    $order = documentedOrder();
    $text = pdfText($this->documents->receipt($order));

    expect($text)->toContain('Receipt')
        ->toContain($order->number)
        ->toContain('Kabwata Motors')
        ->toContain('Chanda Mwale')
        ->toContain('Alternator')
        ->toContain('ALT-9001')
        /* VAT-inclusive prices, and whose VAT it is. */
        ->toContain('include VAT')
        ->toContain('seller is responsible for VAT')
        ->toContain($order->total_ngwee->format())
        ->toContain($order->paymentReference());
});

it('shows the delivery charge as its own line when there is one', function (): void {
    $order = documentedOrder(FulfilmentMethod::Delivery);
    $text = pdfText($this->documents->receipt($order));

    expect($text)->toContain('Delivery')
        ->and($text)->toContain('Kafue Road');
});

it('keeps prices off the packing slip', function (): void {
    $order = documentedOrder(FulfilmentMethod::Delivery);
    $text = pdfText($this->documents->packingSlip($order));

    expect($text)->toContain('Packing slip')
        ->toContain($order->number)
        ->toContain('Alternator')
        ->toContain('Total units: 2')
        /* Where it is going, and how to get in. */
        ->toContain('Kafue Road')
        ->toContain('Blue gate');

    expect($text)->not->toContain($order->total_ngwee->format())
        ->and($text)->not->toContain('K 1,200.00');
});

it('tells a picker to expect the buyer at the counter on a pickup order', function (): void {
    $order = documentedOrder(FulfilmentMethod::Pickup);
    $text = pdfText($this->documents->packingSlip($order));

    expect($text)->toContain('Collect from seller')
        ->and($text)->toContain('Collection from Kabwata Motors')
        ->and($text)->toContain($order->number);
});

it('names the file after the order rather than its id', function (): void {
    $order = documentedOrder();

    expect($this->documents->filename($order, 'receipt'))->toBe('receipt-'.$order->number.'.pdf');
});
