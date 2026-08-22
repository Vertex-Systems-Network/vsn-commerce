<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the FinanceReconciliationIssue class and its project responsibilities. */
class FinanceReconciliationIssue extends Model
{
    protected $fillable=['finance_reconciliation_run_id','code','reference_type','reference_id','expected_minor','actual_minor','delta_minor','message','resolved_at','metadata'];
    /** Handles casts for the finance reconciliation issue workflow. */
    protected function casts():array{return ['expected_minor'=>'integer','actual_minor'=>'integer','delta_minor'=>'integer','resolved_at'=>'datetime','metadata'=>'array'];}
    /** Executes the finance reconciliation issue operation. */
    public function run():BelongsTo{return $this->belongsTo(FinanceReconciliationRun::class,'finance_reconciliation_run_id');}
}
