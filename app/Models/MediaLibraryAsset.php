<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Represents a reusable image stored in the VSN Ecommerce media library. */
class MediaLibraryAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id','vendor_id','uploaded_by_user_id','scope_key','disk','path','original_name','alt_text',
        'mime_type','byte_size','sha256','width','height','visibility','status','metadata',
    ];

    /** Casts persisted media metadata to application-friendly values. */
    protected function casts(): array
    {
        return ['byte_size'=>'integer','width'=>'integer','height'=>'integer','metadata'=>'array'];
    }

    /** Uses the public ULID in route-model binding instead of the internal database id. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Returns the seller that owns this asset, or null for marketplace-global media. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Returns the user who originally uploaded the asset. */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
