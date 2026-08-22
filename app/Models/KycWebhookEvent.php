<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
/** Defines the KycWebhookEvent class and its project responsibilities. */
class KycWebhookEvent extends Model
{
    protected $fillable=['provider','provider_event_id','payload_sha256','status','payload','error','received_at','processed_at'];
    /** Handles casts for the kyc webhook event workflow. */
    protected function casts():array{return ['payload'=>'array','received_at'=>'datetime','processed_at'=>'datetime'];}
}
