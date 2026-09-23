<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two writers at once (a check, an API call, someone in the admin) must wait for
 * each other instead of failing with "database is locked" straight away.
 */
class SqliteConcurrencyTest extends TestCase
{
    public function test_sqlite_waits_for_a_busy_database_instead_of_failing_at_once(): void
    {
        $this->assertSame(5000, (int) DB::connection('sqlite')->selectOne('pragma busy_timeout')->timeout);
    }

    public function test_the_journal_mode_is_left_alone_unless_an_installation_opts_in(): void
    {
        // WAL is unsafe on network file systems that some shared hosts use, so it is opt in.
        $this->assertNull(config('database.connections.sqlite.journal_mode'));
    }
}
