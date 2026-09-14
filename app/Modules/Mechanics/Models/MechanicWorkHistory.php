<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Models;

use App\Modules\Mechanics\Database\Factories\MechanicWorkHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One job a mechanic has held.
 *
 * @property int $id
 * @property int $mechanic_profile_id
 * @property string $employer
 * @property string $role
 * @property string|null $description
 * @property Carbon|null $started_on
 * @property Carbon|null $ended_on
 * @property bool $is_current
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MechanicProfile $profile
 */
class MechanicWorkHistory extends Model
{
    /** @use HasFactory<MechanicWorkHistoryFactory> */
    use HasFactory;

    protected $table = 'mechanic_work_histories';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
            'is_current' => 'boolean',
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

    /**
     * "Mar 2014 — present", as the profile page shows it.
     */
    public function period(): string
    {
        $from = $this->started_on?->format('M Y');
        $to = $this->is_current ? 'present' : $this->ended_on?->format('M Y');

        return match (true) {
            $from !== null && $to !== null => $from.' — '.$to,
            $from !== null => $from,
            $to !== null => 'until '.$to,
            default => '',
        };
    }
}
