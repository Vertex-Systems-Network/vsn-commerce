<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the OrderAddress class and its project responsibilities. */
class OrderAddress extends Model
{
    use HasFactory;
    protected $fillable = ['order_id','type','label','recipient_name','phone','line1','line2','city','state','postal_code','country_code'];
    /** Handles order for the order address workflow. */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
