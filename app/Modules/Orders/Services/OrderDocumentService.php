<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Money\Money;
use App\Support\Pdf\PdfDocument;
use Carbon\CarbonInterface;

/**
 * The two pieces of paper an order produces.
 *
 * A buyer's receipt and a seller's packing slip, and they are deliberately
 * different documents rather than one with a flag. A receipt is about money —
 * what was paid, to whom, and who answers for the VAT. A packing slip is
 * about a shelf and a parcel: what to pick, how many, and where it is going.
 * Prices do not belong on the second one at all.
 *
 * The VAT note on the receipt is not boilerplate. Product prices on MonaFind
 * are VAT-inclusive and the seller is responsible for their own output tax;
 * MonaFind invoices the seller for commission plus VAT on that commission,
 * separately. A buyer who takes this receipt to their accountant needs to
 * know the platform is not the one that taxed them.
 */
class OrderDocumentService
{
    private const MARGIN = 48.0;

    private const LINE_HEIGHT = 14.0;

    /**
     * The buyer's receipt: what they paid and what they bought.
     */
    public function receipt(Order $order): string
    {
        $order->loadMissing(['items', 'seller.city', 'seller.province', 'buyer', 'group']);

        $pdf = new PdfDocument;
        $pdf->addPage();

        $right = $pdf->width() - self::MARGIN;
        $y = $this->header($pdf, 'Receipt', $order);

        /* Who sold, and who bought. Side by side, the way an invoice reads. */
        $column = $pdf->width() / 2;

        $y += 6;
        $sellerBottom = $this->block($pdf, self::MARGIN, $y, 'Sold by', [
            $order->seller->business_name,
            $order->seller->singleLine(),
            $order->seller->phone,
            $order->seller->registration_number !== null
                ? 'Reg. '.$order->seller->registration_number
                : null,
        ]);

        $address = $order->deliveryAddress();

        $buyerBottom = $this->block($pdf, $column, $y, 'Billed to', [
            $order->buyer->name,
            $order->buyer->email,
            $address?->singleLine(),
        ]);

        $y = max($sellerBottom, $buyerBottom) + 12;

        $y = $this->itemsTable($pdf, $y, $order, withPrices: true);

        /* Totals, right-aligned so the digits line up. */
        $y += 6;
        $y = $this->totalRow($pdf, $y, 'Parts', $order->items_total_ngwee, $right);

        if ($order->delivery_fee_ngwee->isPositive()) {
            $y = $this->totalRow($pdf, $y, 'Delivery', $order->delivery_fee_ngwee, $right);
        }

        $pdf->line(self::MARGIN, $y, $right, $y);
        $y += self::LINE_HEIGHT;
        $y = $this->totalRow($pdf, $y, 'Total paid', $order->total_ngwee, $right, bold: true);

        if ($order->refunded_amount_ngwee->isPositive()) {
            $y = $this->totalRow($pdf, $y, 'Refunded', $order->refunded_amount_ngwee, $right);
        }

        $y += 24;

        $this->note($pdf, $y, [
            'All prices shown include VAT. The seller is responsible for VAT on the goods sold.',
            'MonaFindAuto acts as the marketplace and invoices the seller separately for its commission.',
            'Payment reference: '.$order->paymentReference().'.',
        ]);

        $this->footer($pdf, 'Receipt for order '.$order->number);

        return $pdf->render();
    }

    /**
     * The seller's packing slip: what to pick and where it goes.
     */
    public function packingSlip(Order $order): string
    {
        $order->loadMissing(['items', 'seller', 'buyer']);

        $pdf = new PdfDocument;
        $pdf->addPage();

        $right = $pdf->width() - self::MARGIN;
        $y = $this->header($pdf, 'Packing slip', $order);

        $y += 6;
        $y = $this->block($pdf, self::MARGIN, $y, $order->fulfilment_method->label(), $this->destinationLines($order)) + 12;

        /* No prices. A picker needs a shelf and a count, not a total. */
        $y = $this->itemsTable($pdf, $y, $order, withPrices: false);

        $y += 10;
        $pdf->text(self::MARGIN, $y, sprintf(
            'Total units: %d',
            $order->items->sum(static fn (OrderItem $item): int => $item->quantity),
        ), 10, true);

        if (filled($order->delivery_instructions)) {
            $y += self::LINE_HEIGHT * 2;
            $pdf->text(self::MARGIN, $y, 'Buyer instructions', 10, true);

            foreach ($pdf->wrap((string) $order->delivery_instructions, $right - self::MARGIN, 10) as $line) {
                $y += self::LINE_HEIGHT;
                $pdf->text(self::MARGIN, $y, $line, 10);
            }
        }

        $y += 30;
        $pdf->line(self::MARGIN, $y, self::MARGIN + 200, $y, 0.5, 0.4);
        $pdf->text(self::MARGIN, $y + 12, 'Received by (name and signature)', 8, false);

        $this->footer($pdf, 'Packing slip for order '.$order->number);

        return $pdf->render();
    }

    /**
     * A suggested filename. Used for the download header, so it carries the
     * order number rather than an id nobody can read.
     */
    public function filename(Order $order, string $kind): string
    {
        return sprintf('%s-%s.pdf', $kind, $order->number);
    }

    /**
     * The masthead: platform, document kind, order number and dates.
     */
    private function header(PdfDocument $pdf, string $title, Order $order): float
    {
        $right = $pdf->width() - self::MARGIN;

        $pdf->text(self::MARGIN, 60, 'MonaFindAuto', 18, true);
        $pdf->text(self::MARGIN, 76, 'Vehicle parts marketplace', 9);

        $pdf->textRight($right, 60, $title, 16, true);
        $pdf->textRight($right, 78, 'Order '.$order->number, 11, true);
        $pdf->textRight($right, 92, $this->formatDate($order->paid_at ?? $order->created_at), 9);

        $pdf->line(self::MARGIN, 104, $right, 104, 1.0, 0.6);

        return 122.0;
    }

    /**
     * A labelled stack of lines. Returns the y it finished at, so the caller
     * can put the next thing below whichever of two columns ran longer.
     *
     * @param  array<int, string|null>  $lines
     */
    private function block(PdfDocument $pdf, float $x, float $y, string $heading, array $lines): float
    {
        $pdf->text($x, $y, $heading, 8, true);

        foreach (array_filter($lines) as $line) {
            $y += self::LINE_HEIGHT;
            $pdf->text($x, $y, (string) $line, 10);
        }

        return $y;
    }

    /**
     * Where the parcel is going, or where to come and get it.
     *
     * @return array<int, string|null>
     */
    private function destinationLines(Order $order): array
    {
        $address = $order->deliveryAddress();

        if ($address === null) {
            return [
                $order->buyer->name,
                'Collection from '.$order->seller->business_name,
                'Ask the buyer for order number '.$order->number.'.',
            ];
        }

        return [
            $address->recipientName,
            $address->recipientPhone,
            $address->singleLine(),
            $address->directions,
        ];
    }

    /**
     * The lines, with or without money.
     */
    private function itemsTable(PdfDocument $pdf, float $y, Order $order, bool $withPrices): float
    {
        $right = $pdf->width() - self::MARGIN;
        $quantityX = $withPrices ? $right - 190 : $right;
        $unitX = $right - 100;

        $pdf->fill(self::MARGIN, $y - 10, $right - self::MARGIN, 18);

        $pdf->text(self::MARGIN + 4, $y, 'Item', 9, true);
        $pdf->textRight($quantityX, $y, 'Qty', 9, true);

        if ($withPrices) {
            $pdf->textRight($unitX, $y, 'Unit', 9, true);
            $pdf->textRight($right, $y, 'Total', 9, true);
        }

        $y += 8;

        foreach ($order->items as $item) {
            $y += self::LINE_HEIGHT + 4;

            $pdf->text(self::MARGIN + 4, $y, $item->description(), 10);
            $pdf->textRight($quantityX, $y, (string) $item->quantity, 10);

            if ($withPrices) {
                $pdf->textRight($unitX, $y, $item->unit_price_ngwee->format(), 10);
                $pdf->textRight($right, $y, $item->total_ngwee->format(), 10);
            }

            /* The badges the platform promises on every card, on paper too. */
            $detail = collect([
                $item->sku !== null ? 'SKU '.$item->sku : null,
                $item->condition?->label(),
                $item->inspection_status?->label(),
                $item->wasQuoted() ? 'Quoted price' : null,
            ])->filter()->implode(' · ');

            if ($detail !== '') {
                $y += 11;
                $pdf->text(self::MARGIN + 4, $y, $detail, 8);
            }

            $pdf->line(self::MARGIN, $y + 6, $right, $y + 6, 0.4, 0.88);
        }

        return $y + 16;
    }

    private function totalRow(PdfDocument $pdf, float $y, string $label, Money $amount, float $right, bool $bold = false): float
    {
        $pdf->textRight($right - 100, $y, $label, $bold ? 11 : 10, $bold);
        $pdf->textRight($right, $y, $amount->format(), $bold ? 11 : 10, $bold);

        return $y + self::LINE_HEIGHT;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function note(PdfDocument $pdf, float $y, array $lines): void
    {
        $right = $pdf->width() - self::MARGIN;

        foreach ($lines as $line) {
            foreach ($pdf->wrap($line, $right - self::MARGIN, 8) as $wrapped) {
                $pdf->text(self::MARGIN, $y, $wrapped, 8);
                $y += 11;
            }
        }
    }

    private function footer(PdfDocument $pdf, string $text): void
    {
        $pdf->text(self::MARGIN, $pdf->height() - 40, $text.' · Generated '.$this->formatDate(now()), 8);
    }

    /**
     * Dates are shown in Lusaka time, because that is where everybody reading
     * these documents is standing.
     */
    private function formatDate(?CarbonInterface $date): string
    {
        return ($date ?? now())
            ->timezone((string) config('monafind.display_timezone', 'Africa/Lusaka'))
            ->format('j M Y, H:i');
    }
}
