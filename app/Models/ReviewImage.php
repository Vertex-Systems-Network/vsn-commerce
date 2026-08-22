<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Defines the ReviewImage class and its project responsibilities. */
class ReviewImage extends Model
{
    protected $fillable = ['review_id','disk','path','original_name','mime_type','size_bytes','sha256','width','height','sort_order'];
    /** Handles casts for the review image workflow. */
    protected function casts(): array { return ['size_bytes'=>'integer','width'=>'integer','height'=>'integer','sort_order'=>'integer']; }
    /** Handles review for the review image workflow. */
    public function review(): BelongsTo { return $this->belongsTo(Review::class); }
    /** Handles url for the review image workflow. */
    public function url(): string { return Storage::disk($this->disk)->url($this->path); }
}
