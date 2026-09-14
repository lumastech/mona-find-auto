<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installs database triggers that reject UPDATE and DELETE on a table, so the
 * append-only guarantee holds even for statements that bypass Eloquent.
 *
 * Call from a migration: `AppendOnlyTable::protect('audit_logs');`
 */
final class AppendOnlyTable
{
    public static function protect(string $table): void
    {
        $connection = Schema::getConnection();
        $message = "Table [{$table}] is append-only.";

        match ($connection->getDriverName()) {
            'mysql', 'mariadb' => self::protectMysql($table, $message),
            'sqlite' => self::protectSqlite($table, $message),
            'pgsql' => self::protectPostgres($table, $message),
            default => null,
        };
    }

    public static function unprotect(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();

        foreach (['update', 'delete'] as $operation) {
            $trigger = self::triggerName($table, $operation);

            match ($driver) {
                'mysql', 'mariadb' => DB::connection()->getPdo()->exec("DROP TRIGGER IF EXISTS {$trigger}"),
                'sqlite' => DB::statement("DROP TRIGGER IF EXISTS {$trigger}"),
                'pgsql' => DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}"),
                default => null,
            };
        }

        if (in_array($driver, ['pgsql'], true)) {
            DB::statement('DROP FUNCTION IF EXISTS '.self::functionName($table).'() CASCADE');
        }
    }

    private static function protectMysql(string $table, string $message): void
    {
        /*
         * MySQL will not accept CREATE TRIGGER through a prepared statement,
         * so these go straight to the driver rather than through
         * DB::statement().
         */
        $pdo = DB::connection()->getPdo();

        foreach (['update', 'delete'] as $operation) {
            $trigger = self::triggerName($table, $operation);

            $pdo->exec("DROP TRIGGER IF EXISTS {$trigger}");
            $pdo->exec(<<<SQL
                CREATE TRIGGER {$trigger}
                BEFORE {$operation} ON {$table}
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'
            SQL);
        }
    }

    private static function protectSqlite(string $table, string $message): void
    {
        foreach (['update', 'delete'] as $operation) {
            $trigger = self::triggerName($table, $operation);

            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            DB::statement(<<<SQL
                CREATE TRIGGER {$trigger}
                BEFORE {$operation} ON {$table}
                BEGIN
                    SELECT RAISE(ABORT, '{$message}');
                END
            SQL);
        }
    }

    private static function protectPostgres(string $table, string $message): void
    {
        $function = self::functionName($table);

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '{$message}';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        foreach (['update', 'delete'] as $operation) {
            $trigger = self::triggerName($table, $operation);

            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
            DB::statement("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} FOR EACH ROW EXECUTE FUNCTION {$function}()");
        }
    }

    private static function triggerName(string $table, string $operation): string
    {
        return "{$table}_no_{$operation}";
    }

    private static function functionName(string $table): string
    {
        return "{$table}_append_only";
    }
}
