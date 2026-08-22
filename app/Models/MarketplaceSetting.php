<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Defines the MarketplaceSetting class and its project responsibilities. */
class MarketplaceSetting extends Model
{
    protected $fillable=['group','key','value','updated_by'];
    /** Handles casts for the marketplace setting workflow. */
    protected function casts(): array { return ['value'=>'array']; }
}
