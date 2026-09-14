<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

use App\Modules\Search\Enums\MatchTier;
use Illuminate\Support\Str;

/**
 * The words a buyer typed, and whether a given listing contains all of them.
 *
 * The index's ranking rules already put whole-query matches above partial
 * ones — that ordering is Meilisearch's job and this class does not
 * second-guess it. What this class does is *label* each result, so the card
 * can say "Partial match" and the tooltip can explain why the buyer is
 * looking at it. Meilisearch will not tell us that for free without asking
 * for match positions on every hit, and the answer is cheap to work out from
 * text we already hold.
 *
 * Tokenising mirrors Meilisearch: fold to lowercase, split on anything that
 * is not a letter or a digit, and treat the final token as a prefix — which
 * is how a search-as-you-type engine reads a half-typed last word.
 */
final readonly class SearchTokens
{
    /**
     * @param  array<int, string>  $tokens
     */
    private function __construct(public array $tokens) {}

    public static function of(?string $query): self
    {
        $normalised = Str::lower(trim((string) $query));

        $tokens = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $normalised) ?: [],
            static fn (string $token): bool => $token !== '',
        ));

        return new self($tokens);
    }

    public function isEmpty(): bool
    {
        return $this->tokens === [];
    }

    /**
     * The tier a listing earned against these words.
     *
     * With nothing typed there is nothing to be exact about — a buyer
     * browsing filters alone is shown an unqualified list, so every result is
     * Exact rather than apologising for a query that was never made.
     */
    public function tierFor(string $haystack): MatchTier
    {
        if ($this->isEmpty()) {
            return MatchTier::Exact;
        }

        return $this->matchesAll($haystack) ? MatchTier::Exact : MatchTier::Partial;
    }

    /**
     * Whether every word appears in the text, the last one as a prefix.
     */
    public function matchesAll(string $haystack): bool
    {
        $words = self::of($haystack)->tokens;

        if ($words === []) {
            return false;
        }

        $lastIndex = count($this->tokens) - 1;

        foreach ($this->tokens as $index => $token) {
            $found = $index === $lastIndex
                ? self::anyStartsWith($words, $token)
                : in_array($token, $words, true);

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $words
     */
    private static function anyStartsWith(array $words, string $token): bool
    {
        foreach ($words as $word) {
            if (str_starts_with($word, $token)) {
                return true;
            }
        }

        return false;
    }
}
