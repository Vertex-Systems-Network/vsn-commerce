<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Verifies dependency metadata and local demo-access documentation without booting Laravel. */
final class ReleasePackageContractTest extends TestCase
{
    /** Ensures npm dependency declarations match the root package-lock entry. */
    public function test_package_lock_root_dependencies_match_package_json(): void
    {
        $package = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/package.json'), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $root = $lock['packages'][''] ?? [];

        self::assertSame($package['dependencies'] ?? [], $root['dependencies'] ?? []);
        self::assertSame($package['devDependencies'] ?? [], $root['devDependencies'] ?? []);
    }

    /** Ensures all requested primary demo credentials are documented in the release package. */
    public function test_primary_login_credentials_are_documented(): void
    {
        $credentials = (string) file_get_contents(dirname(__DIR__, 2).'/LOGIN-CREDENTIALS.md');
        foreach (['admin@example.test', 'ops-admin@example.test', 'seller@example.test', 'customer@example.test', 'ChangeMe12345'] as $expected) {
            self::assertStringContainsString($expected, $credentials);
        }
    }


}
