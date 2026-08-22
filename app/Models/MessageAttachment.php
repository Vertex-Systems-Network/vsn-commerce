<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the MessageAttachment class and its project responsibilities. */
class MessageAttachment extends Model
{
    public $timestamps=false;
    protected $fillable=['public_id','conversation_message_id','disk','path','original_name','mime_type','size_bytes','sha256','created_at'];
    /** Handles casts for the message attachment workflow. */
    protected function casts():array{return ['size_bytes'=>'integer','created_at'=>'datetime'];}
    /** Handles booted for the message attachment workflow. */
    protected static function booted():void{static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Message attachments are immutable.'));static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Message attachments are immutable.'));}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles message for the message attachment workflow. */
    public function message():BelongsTo{return $this->belongsTo(ConversationMessage::class,'conversation_message_id');}
}
