<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Currency extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'currencies';

    protected $fillable = [
        'code', 'name', 'symbol', 'decimals',
    ];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
        ];
    }

    // Relationships
    public function priceLists(): HasMany
    {
        return $this->hasMany(PriceList::class);
    }

    // Scopes
    public function scopeCode($query, string $code)
    {
        return $query->where('code', strtoupper($code));
    }

    // Accessors/Mutators
    protected function code(): Attribute
    {
        return Attribute::make(
            get: fn ($v) => $v ? strtoupper($v) : $v,
            set: fn ($v) => $v ? strtoupper($v) : $v,
        );
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn ($v) => $v ? trim($v) : $v,
        );
    }
}
