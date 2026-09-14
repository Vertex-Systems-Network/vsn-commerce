<?php

namespace App\Support;

/** Normalizes post-authentication redirects to same-origin application paths only. */
final class SafeRedirect
{
    /** Returns a safe local path or a validated local fallback. */
    public static function localPath(?string $value, string $fallback = '/dashboard'): string
    {
        $safeFallback = self::validatedLocalPath($fallback) ?? '/dashboard';

        return self::validatedLocalPath($value) ?? $safeFallback;
    }

    /** Returns a normalized local path when the candidate cannot cross the origin boundary. */
    private static function validatedLocalPath(?string $value): ?string
    {
        $candidate = trim((string) $value);

        if (
            $candidate === ''
            || ! str_starts_with($candidate, '/')
            || str_starts_with($candidate, '//')
            || str_contains($candidate, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1
        ) {
            return null;
        }

        $parts = parse_url($candidate);
        if (
            $parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return $candidate;
    }
}
