<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the NotificationPreference class and its project responsibilities. */
class NotificationPreference extends Model
{
    protected $fillable=['user_id','category','channel','enabled'];
    /** Handles casts for the notification preference workflow. */
    protected function casts():array{return ['enabled'=>'boolean'];}
    /** Handles user for the notification preference workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
}
