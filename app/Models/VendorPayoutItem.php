<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the VendorPayoutItem class and its project responsibilities. */
class VendorPayoutItem extends Model
{
    protected $fillable=['vendor_payout_id','vendor_settlement_id','amount_minor'];
    /** Handles casts for the vendor payout item workflow. */
    protected function casts():array{return ['amount_minor'=>'integer'];}
    /** Handles payout for the vendor payout item workflow. */
    public function payout():BelongsTo{return $this->belongsTo(VendorPayout::class,'vendor_payout_id');}
    /** Updates tlement. */
    public function settlement():BelongsTo{return $this->belongsTo(VendorSettlement::class,'vendor_settlement_id');}
}
