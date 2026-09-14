<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Models;

use App\Modules\Identity\Support\PhoneNumberCast;
use App\Modules\Mechanics\Database\Factories\MechanicReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Somebody a reviewer may ring about an applicant.
 *
 * Never public. This is a third party's phone number given to MonaFind for
 * one purpose, and the only screen that reads it is the staff console — see
 * the migration.
 *
 * @property int $id
 * @property int $mechanic_profile_id
 * @property string $name
 * @property string|null $relationship
 * @property string $phone
 * @property string|null $email
 * @property string|null $note
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MechanicProfile $profile
 */
class MechanicReference extends Model
{
    /** @use HasFactory<MechanicReferenceFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone' => PhoneNumberCast::class,
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MechanicProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(MechanicProfile::class, 'mechanic_profile_id');
    }
}
