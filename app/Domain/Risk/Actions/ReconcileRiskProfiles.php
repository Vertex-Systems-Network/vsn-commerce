<?php
namespace App\Domain\Risk\Actions;
use App\Domain\Risk\Services\RiskEvaluator;
use App\Models\{User,Vendor};
/** Defines the ReconcileRiskProfiles class and its project responsibilities. */
class ReconcileRiskProfiles {
    /** Initializes the ReconcileRiskProfiles instance and its dependencies. */
    public function __construct(private readonly RiskEvaluator $risk){}
    /** Executes the reconcile risk profiles operation. */
    public function execute(int $limit=500):array{$users=0;$vendors=0;User::query()->orderByDesc('id')->limit($limit)->get()->each(/** Inline callback for this operation. */ function($u)use(&$users){$this->risk->user($u,'scheduled');$users++;});Vendor::query()->orderByDesc('id')->limit($limit)->get()->each(/** Inline callback for this operation. */ function($v)use(&$vendors){$this->risk->vendor($v,'scheduled');$vendors++;});return compact('users','vendors');}
}
