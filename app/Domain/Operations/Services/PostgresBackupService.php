<?php

namespace App\Domain\Operations\Services;

/**
 * @deprecated Use DatabaseBackupService. Kept as a compatibility alias for
 * older integrations while backups are now selected by the active DB driver.
 */
class PostgresBackupService extends DatabaseBackupService
{
}
