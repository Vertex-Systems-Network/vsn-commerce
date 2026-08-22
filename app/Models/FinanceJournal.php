<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the FinanceJournal class and its project responsibilities. */
class FinanceJournal extends Model
{
    protected $fillable=['public_id','type','reference_type','reference_id','idempotency_key','currency','status','posted_at','metadata'];
    /** Handles casts for the finance journal workflow. */
    protected function casts():array{return ['posted_at'=>'datetime','metadata'=>'array'];}
    /** Handles booted for the finance journal workflow. */
    protected static function booted():void{static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Posted finance journals are immutable.'));static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Posted finance journals are immutable.'));}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles entries for the finance journal workflow. */
    public function entries():HasMany{return $this->hasMany(FinanceEntry::class);}
}
