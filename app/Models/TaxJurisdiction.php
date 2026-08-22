<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxJurisdiction extends Model
{
    protected $hidden = ['mysql_region_guard'];

    protected $fillable = [
        'public_id',
        'country_code',
        'region_code',
        'name',
        'status',
        'priority',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }
}
