<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Database\Factories\LedgerAccountFactory;
use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Enums\LedgerAccountType;
use App\Modules\Ledger\Enums\LedgerSubject;
use App\Modules\Ledger\Exceptions\LedgerException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One account in the chart.
 *
 * A thin row over LedgerAccountCode, which is where an account's meaning
 * actually lives. Nothing decides behaviour by reading a column here: the
 * enum says what type an account is and which way its balance runs, and the
 * table exists so a journal line can carry a foreign key to something.
 *
 * @property int $id
 * @property LedgerAccountCode $code
 * @property string $name
 * @property LedgerAccountType $type
 * @property EntryDirection $normal_balance
 * @property LedgerSubject $subject
 * @property string|null $description
 * @property-read Collection<int, JournalLine> $lines
 * @property-read Collection<int, LedgerBalance> $balances
 */
class LedgerAccount extends Model
{
    /** @use HasFactory<LedgerAccountFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The chart is small and read on every posting, so it is resolved once
     * per request rather than per line.
     *
     * @var array<string, self>
     */
    private static array $cache = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => LedgerAccountCode::class,
            'type' => LedgerAccountType::class,
            'normal_balance' => EntryDirection::class,
            'subject' => LedgerSubject::class,
        ];
    }

    /**
     * The row for a code, resolved once per request.
     *
     * Throws rather than creating the account on demand. An account missing
     * from the chart means the seeder has not run, and quietly inserting one
     * would let a typo in a recipe create a real account that reconciles
     * against nothing.
     */
    public static function for(LedgerAccountCode $code): self
    {
        if (isset(self::$cache[$code->value])) {
            return self::$cache[$code->value];
        }

        $account = self::query()->where('code', $code->value)->first();

        if ($account === null) {
            throw LedgerException::unknownAccount($code->value);
        }

        return self::$cache[$code->value] = $account;
    }

    /**
     * Drop the per-request cache. The seeder calls this; so does the test
     * suite between transactions.
     */
    public static function forgetCache(): void
    {
        self::$cache = [];
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * @return HasMany<LedgerBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(LedgerBalance::class);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
