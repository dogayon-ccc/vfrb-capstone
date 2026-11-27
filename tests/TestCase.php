<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    // CI uses vfrb_test; the local Laragon database is vfrb_demo. Any other name (hosted data) is refused.
    protected function assertTestDatabase(): void
    {
        $this->assertContains(
            DB::connection()->getDatabaseName(),
            ['vfrb_test', 'vfrb_demo'],
            'Tests run only against a database named vfrb_test or vfrb_demo.'
        );
    }
}
