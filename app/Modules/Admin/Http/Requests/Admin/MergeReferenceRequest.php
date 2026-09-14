<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests\Admin;

/**
 * Folding one reference row into another.
 *
 * Ids rather than slugs: a merge is the one place two rows with confusingly
 * similar names are being handled at once, and "toyota" versus "toyota-1" is
 * exactly the pair somebody gets the wrong way round.
 */
class MergeReferenceRequest extends ReasonedRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function actionRules(): array
    {
        return [
            'source_id' => ['required', 'integer'],
            'target_id' => ['required', 'integer', 'different:source_id'],
        ];
    }

    public function sourceId(): int
    {
        return (int) $this->validated('source_id');
    }

    public function targetId(): int
    {
        return (int) $this->validated('target_id');
    }
}
