<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the DailyCheckin class and its project responsibilities. */
class DailyCheckin extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','checkin_date','streak_day','base_reward_coins','bonus_reward_coins','base_transaction_id','bonus_transaction_id'];
    /** Handles casts for the daily checkin workflow. */
    protected function casts(): array { return ['checkin_date'=>'date','streak_day'=>'integer','base_reward_coins'=>'integer','bonus_reward_coins'=>'integer']; }
    /** Handles user for the daily checkin workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
