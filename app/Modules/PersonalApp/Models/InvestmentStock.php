<?php

namespace App\Modules\PersonalApp\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestmentStock extends BaseModel
{
    protected $table = 'investment_stocks';

    protected $fillable = [
        'user_id',
        'ticker',
        'yahoo_symbol',
        'company_name',
        'last_price',
        'previous_close',
        'price_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'last_price' => 'float',
            'previous_close' => 'float',
            'price_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(InvestmentStockLot::class, 'stock_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(InvestmentStockSale::class, 'stock_id');
    }
}
