<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Support;

use BackedEnum;
use DateTimeInterface;
use Stringable;

/**
 * One titled table in a data-subject export.
 *
 * The Act entitles a person to their data "in a commonly used, structured,
 * machine-readable form", and to understand it. A bare dump of column names
 * satisfies the first half and fails the second, so a section carries a human
 * title and a note saying what the rows are, and the PDF renders both.
 *
 * `$rows` is a list of flat associative arrays sharing one set of keys. Keys
 * are shown to the person as column headings, so they are written the way a
 * person would say them ("Ordered on", not "created_at").
 *
 * ## Values are normalised here, not at the call site
 *
 * A source hands over whatever its models hold — a `Money`, a backed enum, a
 * Carbon instance — and this flattens each one to a scalar on the way in.
 *
 * The alternative is every one of the eight sources remembering to call
 * `->value` on each enum and `->ngwee` on each amount, and the failure mode
 * when one forgets is an export containing `{}` where somebody's order total
 * should be. Normalising once, in the type that promises to hold scalars, is
 * the difference between a rule and a habit.
 */
final readonly class PersonalDataSection
{
    /**
     * @param  array<int, array<string, scalar|null>>  $rows
     */
    private function __construct(
        public string $title,
        public array $rows,
        public ?string $note = null,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function make(string $title, array $rows, ?string $note = null): self
    {
        return new self($title, array_values(array_map(self::normaliseRow(...), $rows)), $note);
    }

    /**
     * A section holding exactly one thing — a profile, a set of preferences.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function single(string $title, array $attributes, ?string $note = null): self
    {
        return new self($title, [self::normaliseRow($attributes)], $note);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * The column headings, taken from the first row.
     *
     * @return array<int, string>
     */
    public function columns(): array
    {
        return $this->rows === [] ? [] : array_keys($this->rows[0]);
    }

    /**
     * @return array{title: string, note: string|null, rows: array<int, array<string, scalar|null>>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'note' => $this->note,
            'rows' => $this->rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, scalar|null>
     */
    private static function normaliseRow(array $row): array
    {
        return array_map(self::normalise(...), $row);
    }

    /**
     * Flatten one value to something JSON and a PDF can both render.
     *
     * `Money` is handled through Stringable rather than by naming the class:
     * this module has no business knowing about ngwee, and `Money::__toString()`
     * already renders the amount the way a person reads it.
     */
    private static function normalise(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value === null => null,
            is_scalar($value) => $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof Stringable, is_object($value) && method_exists($value, '__toString') => (string) $value,
            is_array($value) => implode(', ', array_map(
                static fn (mixed $item): string => (string) self::normalise($item),
                $value,
            )),
            default => null,
        };
    }
}
