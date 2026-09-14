<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Settings\SettingType;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One typed, admin-editable platform setting.
 *
 * Read settings through the settings() helper, which caches them; this model
 * is the write side and the admin console's edit surface.
 *
 * @property int $id
 * @property string $key
 * @property string $group
 * @property SettingType $type
 * @property string|null $value
 * @property string $label
 * @property string|null $description
 * @property bool $is_public
 */
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'is_public' => 'boolean',
        ];
    }

    /**
     * The stored string decoded into its declared type.
     */
    public function typedValue(): mixed
    {
        return $this->type->decode($this->value);
    }
}
