<?php

declare(strict_types=1);

namespace App\Support\Console;

/**
 * One measurement on the staff console's home screen.
 *
 * The counterpart to ConsoleCounter, and the split is the point. A counter is
 * work waiting — "seven listings awaiting moderation" — and clearing it is
 * somebody's job this morning. A stat is how the platform is doing —
 * "K 412,000 taken over thirty days, up a fifth" — and nobody clears it.
 *
 * Mixing the two is what turns a to-do list into a wall of numbers, so the
 * dashboard keeps them in separate sections and this type keeps them in
 * separate shapes. The module that owns the number builds these; see
 * ConsoleStatistics for why the dashboard cannot compute them itself.
 */
final readonly class ConsoleStat
{
    /**
     * @param  string  $key  Stable identifier, e.g. "finance.gmv".
     * @param  string  $label  What is being measured, in the words staff use.
     * @param  int  $value  The figure, in the unit its format names.
     * @param  int|null  $previous  The same figure over the window before,
     *                              or null where no comparison is meaningful
     *                              (a position like escrow held is a balance,
     *                              not something that happened in a window).
     * @param  ConsoleStatFormat  $format  How to render the value.
     * @param  ConsoleStatDirection  $direction  Which way is good news.
     * @param  string|null  $href  Where the full version of this lives.
     * @param  string|null  $ability  Gate checked before the stat is shown at
     *                                all; null means every staff role sees it.
     * @param  string|null  $hint  One line of context, shown under the number.
     * @param  array<int, int>|null  $spark  One value per day of the window,
     *                                       zero-filled, for the sparkline.
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $value,
        public ?int $previous = null,
        public ConsoleStatFormat $format = ConsoleStatFormat::Count,
        public ConsoleStatDirection $direction = ConsoleStatDirection::Neutral,
        public ?string $href = null,
        public ?string $ability = null,
        public ?string $hint = null,
        public ?array $spark = null,
    ) {}

    /**
     * How far the figure has moved, in hundredths of a percent.
     *
     * Null where there is nothing to compare against: either the module sent
     * no previous figure, or the previous figure was zero. Zero is not a
     * baseline — the first order the platform ever takes is not "up an
     * infinite percent", it is the first order, and the browser says so.
     *
     * The arithmetic is integer throughout. `intdiv(2a + b, 2b)` is `a / b`
     * rounded half up, which keeps a displayed percentage off the same float
     * path every amount on this platform is kept off.
     */
    public function changeInBasisPoints(): ?int
    {
        if ($this->previous === null || $this->previous === 0) {
            return null;
        }

        $delta = $this->value - $this->previous;
        $base = abs($this->previous);
        $magnitude = intdiv(abs($delta) * 20000 + $base, 2 * $base);

        return $delta < 0 ? -$magnitude : $magnitude;
    }

    /**
     * @return array{key: string, label: string, value: int, previous: int|null, change: int|null, format: string, direction: string, href: string|null, hint: string|null, spark: array<int, int>|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'previous' => $this->previous,
            'change' => $this->changeInBasisPoints(),
            'format' => $this->format->value,
            'direction' => $this->direction->value,
            'href' => $this->href,
            'hint' => $this->hint,
            'spark' => $this->spark,
        ];
    }
}
