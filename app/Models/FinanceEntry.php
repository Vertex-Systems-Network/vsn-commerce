<?php
namespace App\Models;
use App\Enums\FinanceDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the FinanceEntry class and its project responsibilities. */
class FinanceEntry extends Model
{
    protected $fillable=['finance_journal_id','vendor_id','account_code','direction','amount_minor','metadata'];
    /** Handles casts for the finance entry workflow. */
    protected function casts():array{return ['direction'=>FinanceDirection::class,'amount_minor'=>'integer','metadata'=>'array'];}
    /** Handles booted for the finance entry workflow. */
    protected static function booted():void{static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Finance entries are immutable.'));static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Finance entries are immutable.'));}
    /** Handles journal for the finance entry workflow. */
    public function journal():BelongsTo{return $this->belongsTo(FinanceJournal::class,'finance_journal_id');}
    /** Handles vendor for the finance entry workflow. */
    public function vendor():BelongsTo{return $this->belongsTo(Vendor::class);}
}
