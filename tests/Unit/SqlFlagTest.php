<?php

namespace Tests\Unit;

use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Raw SQL that compares a 0/1 flag column to 1 must work on MySQL (tinyint) and on a Postgres copy that holds boolean.
class SqlFlagTest extends TestCase
{
    private function driver(string $name): void
    {
        $conn = new class($name) {
            public function __construct(private string $n) {}
            public function getDriverName(): string { return $this->n; }
        };
        DB::shouldReceive('connection')->andReturn($conn);
    }

    public function test_mysql_keeps_the_plain_column(): void
    {
        $this->driver('mysql');
        $this->assertSame('passed', Sql::flag('passed'));
    }

    public function test_postgres_casts_the_flag_to_int_so_boolean_columns_compare_to_1(): void
    {
        $this->driver('pgsql');
        $this->assertSame('(passed)::int', Sql::flag('passed'));
    }
}
