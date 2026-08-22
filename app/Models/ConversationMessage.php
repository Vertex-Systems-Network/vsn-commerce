<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the ConversationMessage class and its project responsibilities. */
class ConversationMessage extends Model
{
    public $timestamps=false;
    protected $fillable=['public_id','conversation_id','sender_user_id','reply_to_id','body','client_id','created_at'];
    /** Handles casts for the conversation message workflow. */
    protected function casts():array{return ['created_at'=>'datetime'];}
    /** Handles booted for the conversation message workflow. */
    protected static function booted():void{static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Conversation messages are immutable.'));static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Conversation messages are immutable.'));}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles conversation for the conversation message workflow. */
    public function conversation():BelongsTo{return $this->belongsTo(Conversation::class);}
    /** Handles sender for the conversation message workflow. */
    public function sender():BelongsTo{return $this->belongsTo(User::class,'sender_user_id');}
    /** Handles reply to for the conversation message workflow. */
    public function replyTo():BelongsTo{return $this->belongsTo(self::class,'reply_to_id');}
    /** Handles attachments for the conversation message workflow. */
    public function attachments():HasMany{return $this->hasMany(MessageAttachment::class);}
}
