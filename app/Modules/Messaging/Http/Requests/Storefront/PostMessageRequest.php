<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Requests\Storefront;

use App\Modules\Messaging\Models\Message;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A message and whatever the sender attached to it.
 *
 * Authorisation is the policy's, not this class's: the route model binding
 * gives us the thread and `reply` is what decides. Doing it here as well
 * would be a second copy of the rule that stops a stranger writing into a
 * conversation.
 *
 * The length cap is generous rather than tight. People describe a fault in a
 * gearbox at length and being cut off mid-sentence is worse than a long row
 * in a table; two thousand characters is about a screen and a half on a
 * phone.
 */
class PostMessageRequest extends FormRequest
{
    /** How many files may ride on one message. */
    public const MAX_ATTACHMENTS = 5;

    /** Ten megabytes, in kilobytes — a photo from a cheap Android phone. */
    private const MAX_ATTACHMENT_KB = 10240;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:2000'],

            'attachments' => ['sometimes', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => [
                'file',
                'max:'.self::MAX_ATTACHMENT_KB,
                'mimetypes:'.implode(',', Message::ACCEPTED_MIME_TYPES),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Write something before sending.',
            'attachments.max' => 'You can attach up to '.self::MAX_ATTACHMENTS.' files to one message.',
            'attachments.*.mimetypes' => 'Attach a photo or a PDF.',
        ];
    }
}
