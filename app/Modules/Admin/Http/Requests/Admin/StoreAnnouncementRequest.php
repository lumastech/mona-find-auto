<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests\Admin;

use App\Modules\Admin\Enums\AnnouncementAudience;
use App\Modules\Admin\Enums\AnnouncementLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Scheduling a banner.
 *
 * `ends_at` is optional but must be after `starts_at` when given — a window
 * that closes before it opens is a banner nobody ever sees, and the mistake
 * is invisible on the screen that created it.
 */
class StoreAnnouncementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'body' => ['required', 'string', 'min:5', 'max:1000'],
            'level' => ['required', Rule::enum(AnnouncementLevel::class)],
            'audience' => ['required', Rule::enum(AnnouncementAudience::class)],
            'link_url' => ['nullable', 'url', 'max:255'],
            'link_label' => ['nullable', 'required_with:link_url', 'string', 'max:60'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
