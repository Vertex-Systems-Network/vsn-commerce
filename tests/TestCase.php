<?php

namespace Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Defines the TestCase class and its project responsibilities. */
abstract class TestCase extends BaseTestCase
{
    /** Updates up. */
    protected function setUp(): void
    {
        // RefreshDatabase is initialized inside Laravel's parent setUp(). Guard the
        // externally selected DB before Laravel can migrate or truncate anything.
        $this->guardExternalTestDatabase();

        parent::setUp();

        // Re-check the resolved Laravel connection after config/.env bootstrap.
        $this->guardResolvedTestDatabase();

        // Automated tests must never make accidental live provider calls.
        Http::preventStrayRequests();
    }

    /** Handles tear down for the test case workflow. */
    protected function tearDown(): void
    {
        // Carbon test time is process-global; always reset it even when an individual
        // test forgot to do so, preventing one failure from cascading into later tests.
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Handles guard external test database for the test case workflow. */
    private function guardExternalTestDatabase(): void
    {
        $connection = strtolower($this->environmentValue('DB_CONNECTION'));
        if (! in_array($connection, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true)) {
            return;
        }

        $database = strtolower($this->environmentValue('DB_DATABASE'));
        $this->assertSafeTestDatabaseName($connection, $database);
    }

    /** Handles guard resolved test database for the test case workflow. */
    private function guardResolvedTestDatabase(): void
    {
        $connection = (string) config('database.default');
        $driver = strtolower((string) config("database.connections.{$connection}.driver", $connection));
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        $database = strtolower((string) config("database.connections.{$connection}.database"));
        $this->assertSafeTestDatabaseName($driver, $database);
    }

    /** Handles assert safe test database name for the test case workflow. */
    private function assertSafeTestDatabaseName(string $driver, string $database): void
    {
        if ($database === '' || ! str_contains($database, 'test')) {
            throw new RuntimeException(
                "Refusing to run automated tests against non-test database [{$database}] on [{$driver}]. " .
                'Use a database name containing "test".'
            );
        }
    }

    /** Handles environment value for the test case workflow. */
    private function environmentValue(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        return is_string($value) ? $value : '';
    }
}
