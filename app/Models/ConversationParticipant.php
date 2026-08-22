<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the ConversationParticipant class and its project responsibilities. */
class ConversationParticipant extends Model
{
    protected $fillable=['conversation_id','user_id','participant_role','joined_at','last_read_at','muted_at','archived_at'];
    /** Handles casts for the conversation participant workflow. */
    protected function casts():array{return ['joined_at'=>'datetime','last_read_at'=>'datetime','muted_at'=>'datetime','archived_at'=>'datetime'];}
    /** Handles conversation for the conversation participant workflow. */
    public function conversation():BelongsTo{return $this->belongsTo(Conversation::class);}
    /** Handles user for the conversation participant workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
}
