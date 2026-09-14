<?php

namespace Tests\Unit;

use App\Support\SafeRedirect;
use PHPUnit\Framework\TestCase;

/** Verifies post-authentication redirect targets remain same-origin local paths. */
class SafeRedirectTest extends TestCase
{
    /** Local paths, queries, and fragments remain available to normal application navigation. */
    public function test_allows_local_application_paths(): void
    {
        $this->assertSame('/dashboard', SafeRedirect::localPath('/dashboard'));
        $this->assertSame('/orders?tab=open#latest', SafeRedirect::localPath('/orders?tab=open#latest'));
    }

    /** External, scheme-relative, malformed, and control-character targets fail closed. */
    public function test_rejects_external_or_ambiguous_redirect_targets(): void
    {
        foreach ([
            null,
            '',
            'https://attacker.example/phish',
            '//attacker.example/phish',
            '/\\attacker.example/phish',
            'javascript:alert(1)',
            "/dashboard\r\nLocation: https://attacker.example",
        ] as $candidate) {
            $this->assertSame('/dashboard', SafeRedirect::localPath($candidate));
        }
    }
}
