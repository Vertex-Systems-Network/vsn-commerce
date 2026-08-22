<?php
namespace App\Models;

use App\Enums\AffiliateAccountStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the AffiliateAccount class and its project responsibilities. */
class AffiliateAccount extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','referral_code','status','terms_version','terms_accepted_at','suspended_at','metadata'];
    /** Handles casts for the affiliate account workflow. */
    protected function casts(): array { return ['status'=>AffiliateAccountStatus::class,'terms_accepted_at'=>'datetime','suspended_at'=>'datetime','metadata'=>'array']; }
    /** Handles user for the affiliate account workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles referrals for the affiliate account workflow. */
    public function referrals(): HasMany { return $this->hasMany(AffiliateRelationship::class, 'referral_account_id'); }
    /** Handles events for the affiliate account workflow. */
    public function events(): HasMany { return $this->hasMany(AffiliateAccountEvent::class); }
}
