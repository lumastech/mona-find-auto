<?php

declare(strict_types=1);

namespace App\Support\Content;

/**
 * What the automatic screen did to a body of text, and what it found.
 *
 * Carried as one object rather than returned as a tuple because the text and
 * the verdict have to travel together: storing the original text with the
 * "clean" verdict is exactly the bug this class exists to make impossible.
 *
 * It reports; it does not decide. A flagged review waits for a moderator and
 * a flagged message is delivered anyway, and neither of those rules belongs
 * to a value object shared by both — Ratings maps this onto a RatingStatus,
 * Messaging reads `wasRedacted()` and moves on.
 */
final readonly class ScreenResult
{
    /**
     * @param  string|null  $text  The body as it should be stored — redacted.
     * @param  array<int, ScreenFlag>  $flags  What was found, in no particular order.
     */
    public function __construct(
        public ?string $text,
        public array $flags = [],
    ) {}

    public static function clean(?string $text): self
    {
        return new self($text);
    }

    public function isClean(): bool
    {
        return $this->flags === [];
    }

    /**
     * Whether anything was blanked out of the text.
     */
    public function wasRedacted(): bool
    {
        return $this->hasFlagMatching(static fn (ScreenFlag $flag): bool => ! $flag->requiresReview());
    }

    /**
     * Whether a person has to read this before it goes up.
     */
    public function requiresReview(): bool
    {
        return $this->hasFlagMatching(static fn (ScreenFlag $flag): bool => $flag->requiresReview());
    }

    /**
     * @param  callable(ScreenFlag): bool  $matches
     */
    private function hasFlagMatching(callable $matches): bool
    {
        foreach ($this->flags as $flag) {
            if ($matches($flag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The flags as they are stored on the row.
     *
     * @return array<int, string>
     */
    public function flagValues(): array
    {
        return array_values(array_unique(array_map(
            static fn (ScreenFlag $flag): string => $flag->value,
            $this->flags,
        )));
    }
}
