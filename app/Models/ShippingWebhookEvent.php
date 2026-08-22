<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
/** Defines the ShippingWebhookEvent class and its project responsibilities. */
class ShippingWebhookEvent extends Model
{
    protected $fillable=['provider','provider_event_id','payload_hash','signature_valid','status','payload','error','received_at','processed_at'];
    /** Handles casts for the shipping webhook event workflow. */
    protected function casts():array{return ['signature_valid'=>'boolean','payload'=>'array','received_at'=>'datetime','processed_at'=>'datetime'];}
}
