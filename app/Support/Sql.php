<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

// Raw-SQL fragments that differ between MySQL (local Laragon) and PostgreSQL (Supabase).
final class Sql
{
    public static function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    // "Jan 2026"
    public static function monthLabel(string $column): string
    {
        return self::isPostgres() ? "to_char({$column}, 'Mon YYYY')" : "DATE_FORMAT({$column},'%b %Y')";
    }

    // "202601", sortable
    public static function monthKey(string $column): string
    {
        return self::isPostgres() ? "to_char({$column}, 'YYYYMM')" : "DATE_FORMAT({$column},'%Y%m')";
    }

    // JSON object from ['key' => 'column', ...]
    public static function jsonObject(array $pairs): string
    {
        $args = implode(', ', array_map(fn ($k, $col) => "'{$k}', {$col}", array_keys($pairs), $pairs));
        return self::isPostgres() ? "json_build_object({$args})" : "JSON_OBJECT({$args})";
    }

    // Case-insensitive LIKE operator (MySQL's *_ci collations already ignore case).
    public static function like(): string
    {
        return self::isPostgres() ? 'ilike' : 'like';
    }

    // A 0/1 flag column as an integer. The dump stores flags as tinyint(1); a Postgres copy may hold them as boolean,
    // where `flag = 1` is an error. `(flag)::int` reads both, so raw SQL can keep comparing to 1.
    public static function flag(string $column): string
    {
        return self::isPostgres() ? "({$column})::int" : $column;
    }

    // Integer cast of a string expression, for numeric ordering.
    public static function toInt(string $expr): string
    {
        return self::isPostgres() ? "CAST(NULLIF({$expr}, '') AS BIGINT)" : "CAST({$expr} AS UNSIGNED)";
    }
}
