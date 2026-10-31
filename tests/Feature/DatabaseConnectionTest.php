<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    public function test_configured_mysql_connection_can_execute_a_query(): void
    {
        $this->assertSame('mysql', config('database.default'));

        $result = DB::select('SELECT 1 AS connection_ok, VERSION() AS server_version');

        $this->assertSame(1, (int) $result[0]->connection_ok);
        $this->assertNotEmpty($result[0]->server_version);
    }
}
