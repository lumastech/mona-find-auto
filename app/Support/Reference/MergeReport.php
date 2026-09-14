<?php

declare(strict_types=1);

namespace App\Support\Reference;

/**
 * What a merge actually moved.
 *
 * Kept because the number is the only way anybody finds out afterwards that a
 * merge was bigger than expected: "Toyata → Toyota, 2 listings" is a typo
 * being cleaned up, and "1,840 listings" is somebody about to make a mistake.
 * The figures go into the audit row and back onto the screen.
 */
final readonly class MergeReport
{
    /**
     * @param  array<string, int>  $moved  Rows repointed, keyed "table.column".
     * @param  array<string, int>  $dropped  Duplicate pivot rows discarded.
     */
    public function __construct(
        public string $listKey,
        public string $sourceLabel,
        public string $targetLabel,
        public array $moved,
        public array $dropped,
    ) {}

    public function rowsMoved(): int
    {
        return array_sum($this->moved);
    }

    public function rowsDropped(): int
    {
        return array_sum($this->dropped);
    }

    /**
     * @return array{list: string, from: string, into: string, moved: array<string, int>, dropped: array<string, int>, rows_moved: int, rows_dropped: int}
     */
    public function toArray(): array
    {
        return [
            'list' => $this->listKey,
            'from' => $this->sourceLabel,
            'into' => $this->targetLabel,
            'moved' => $this->moved,
            'dropped' => $this->dropped,
            'rows_moved' => $this->rowsMoved(),
            'rows_dropped' => $this->rowsDropped(),
        ];
    }
}
