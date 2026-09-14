<?php

declare(strict_types=1);

namespace App\Support\Console;

/**
 * One number on the staff console's home screen, and where to go to clear it.
 *
 * A counter is a piece of work waiting, not a statistic: "seven listings
 * waiting on a decision" belongs here, "listings published this month" does
 * not. The distinction matters because the dashboard is a to-do list — a
 * screen mixing the two trains staff to ignore it.
 *
 * The module that owns the queue builds these; see ConsoleCounters for why
 * the dashboard cannot simply count the rows itself.
 */
final readonly class ConsoleCounter
{
    /**
     * @param  string  $key  Stable identifier, e.g. "sellers.pending".
     * @param  string  $label  What is waiting, in the words staff use.
     * @param  int  $value  How many. Zero is kept and shown greyed out.
     * @param  string  $href  The queue that clears it.
     * @param  string|null  $ability  Gate checked before the tile is shown at
     *                                all; null means every staff role sees it.
     * @param  ConsoleCounterTone  $tone  How loudly to render a non-zero count.
     * @param  string|null  $hint  One line of context, shown under the number.
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $value,
        public string $href,
        public ?string $ability = null,
        public ConsoleCounterTone $tone = ConsoleCounterTone::Neutral,
        public ?string $hint = null,
    ) {}

    /**
     * @return array{key: string, label: string, value: int, href: string, tone: string, hint: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'href' => $this->href,
            'tone' => $this->tone->value,
            'hint' => $this->hint,
        ];
    }
}
