<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the AffiliateRelationship class and its project responsibilities. */
class AffiliateRelationship extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','parent_user_id','referral_account_id','joined_at','metadata'];
    /** Handles casts for the affiliate relationship workflow. */
    protected function casts(): array { return ['joined_at'=>'datetime','metadata'=>'array']; }
    /** Handles user for the affiliate relationship workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles parent for the affiliate relationship workflow. */
    public function parent(): BelongsTo { return $this->belongsTo(User::class, 'parent_user_id'); }
    /** Handles referral account for the affiliate relationship workflow. */
    public function referralAccount(): BelongsTo { return $this->belongsTo(AffiliateAccount::class, 'referral_account_id'); }
}
