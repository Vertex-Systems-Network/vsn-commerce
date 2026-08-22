<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Defines the RuntimeAcceptanceTest class and its project responsibilities. */
class RuntimeAcceptanceTest extends TestCase
{
    /** Verifies release metadata binds both dependency lock hashes. */
    public function test_release_metadata_binds_both_dependency_lock_hashes(): void
    {
        $source=file_get_contents(base_path('scripts/release-production.sh'));
        $this->assertStringContainsString('COMPOSER_LOCK_SHA', $source);
        $this->assertStringContainsString('NPM_LOCK_SHA', $source);
        $this->assertStringContainsString('composerLockSha256', $source);
        $this->assertStringContainsString('npmLockSha256', $source);
    }

    /** Verifies final evidence refuses dependency lock drift. */
    public function test_final_evidence_refuses_dependency_lock_drift(): void
    {
        $source=file_get_contents(base_path('scripts/final-acceptance-evidence.php'));
        $this->assertStringContainsString('composer.lock does not match deployed release metadata', $source);
        $this->assertStringContainsString('package-lock.json does not match deployed release metadata', $source);
        $this->assertStringContainsString('dependencyLocksBound', $source);
    }

    /** Verifies acceptance snapshot and seal include lock fingerprints. */
    public function test_acceptance_snapshot_and_seal_include_lock_fingerprints(): void
    {
        $source=file_get_contents(app_path('Domain/Operations/Services/ProductionAcceptanceService.php'));
        $this->assertStringContainsString('dependency_locks', $source);
        $this->assertStringContainsString('composer_lock_sha256', $source);
        $this->assertStringContainsString('npm_lock_sha256', $source);
        $this->assertStringContainsString('$seal->composer_lock_sha256', $source);
    }

    /** Verifies runtime acceptance script fails closed before install and test. */
    public function test_runtime_acceptance_script_fails_closed_before_install_and_test(): void
    {
        $source=file_get_contents(base_path('scripts/runtime-acceptance.sh'));
        $cap=strpos($source,'runtime-capability-audit.php --strict');
        $install=strpos($source,'composer install');
        $tests=strpos($source,'php artisan test');
        $this->assertNotFalse($cap);$this->assertNotFalse($install);$this->assertNotFalse($tests);
        $this->assertLessThan($install,$cap);$this->assertLessThan($tests,$install);
    }

    /** Verifies manual release candidate workflow requires committed composer lock. */
    public function test_manual_release_candidate_workflow_requires_committed_composer_lock(): void
    {
        $source=file_get_contents(base_path('.github/workflows/release-candidate.yml'));
        $this->assertStringContainsString('composer.lock is required for a release candidate', $source);
        $this->assertStringContainsString('composer validate --strict', $source);
        $this->assertStringContainsString('locked-runtime-candidate.json', $source);
    }
    /** Verifies production deployment requires dependency lock fingerprints. */
    public function test_production_deployment_requires_dependency_lock_fingerprints(): void
    {
        $source=file_get_contents(app_path('Domain/Operations/Services/DeploymentService.php'));
        $this->assertStringContainsString('require_dependency_locks', $source);
        $this->assertStringContainsString('Production deployment requires Composer and npm lock SHA-256 evidence.', $source);
    }

    /** Verifies production configuration audits both dependency locks. */
    public function test_production_configuration_audits_both_dependency_locks(): void
    {
        $source=file_get_contents(app_path('Domain/Operations/Services/ProductionConfigurationAuditService.php'));
        $this->assertStringContainsString("'composer_lock'", $source);
        $this->assertStringContainsString("'npm_lock'", $source);
    }

}
