<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the Conversation class and its project responsibilities. */
class Conversation extends Model
{
    protected $fillable=['public_id','thread_key','kind','subject','order_id','vendor_order_id','vendor_id','created_by_user_id','assigned_user_id','status','last_message_at'];
    /** Handles casts for the conversation workflow. */
    protected function casts():array{return ['last_message_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles order for the conversation workflow. */
    public function order():BelongsTo{return $this->belongsTo(Order::class);}
    /** Handles vendor order for the conversation workflow. */
    public function vendorOrder():BelongsTo{return $this->belongsTo(VendorOrder::class);}
    /** Handles vendor for the conversation workflow. */
    public function vendor():BelongsTo{return $this->belongsTo(Vendor::class);}
    /** Handles created by for the conversation workflow. */
    public function createdBy():BelongsTo{return $this->belongsTo(User::class,'created_by_user_id');}
    /** Handles assigned user for the conversation workflow. */
    public function assignedUser():BelongsTo{return $this->belongsTo(User::class,'assigned_user_id');}
    /** Handles participants for the conversation workflow. */
    public function participants():HasMany{return $this->hasMany(ConversationParticipant::class);}
    /** Handles messages for the conversation workflow. */
    public function messages():HasMany{return $this->hasMany(ConversationMessage::class);}
}
