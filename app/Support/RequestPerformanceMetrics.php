<?php

namespace App\Support;

/** Defines the RequestPerformanceMetrics class and its project responsibilities. */
class RequestPerformanceMetrics
{
    private int $queryCount = 0;
    private float $queryMs = 0.0;
    private array $fingerprints = [];

    /** Handles record for the request performance metrics workflow. */
    public function record(string $sql, float $milliseconds): void
    {
        $this->queryCount++;
        $this->queryMs += $milliseconds;
        if (count($this->fingerprints) < 250) {
            $fingerprint = hash('sha256', preg_replace('/\s+/', ' ', trim($sql)) ?: $sql);
            $this->fingerprints[$fingerprint] = ($this->fingerprints[$fingerprint] ?? 0) + 1;
        }
    }

    /** Handles query count for the request performance metrics workflow. */
    public function queryCount(): int { return $this->queryCount; }
    /** Handles query ms for the request performance metrics workflow. */
    public function queryMs(): float { return round($this->queryMs, 2); }
    /** Handles peak duplicate count for the request performance metrics workflow. */
    public function peakDuplicateCount(): int { return $this->fingerprints === [] ? 0 : max($this->fingerprints); }
    /** Handles duplicate fingerprints for the request performance metrics workflow. */
    public function duplicateFingerprints(int $minimum = 3): int
    {
        return count(array_filter($this->fingerprints, /** Inline callback for this operation. */ fn (int $count): bool => $count >= $minimum));
    }
}
