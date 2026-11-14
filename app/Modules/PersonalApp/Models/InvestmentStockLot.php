<?php

namespace App\Modules\PersonalApp\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentStockLot extends BaseModel
{
    protected $table = 'investment_stock_lots';

    protected $fillable = [
        'user_id',
        'stock_id',
        'opened_at',
        'initial_lots',
        'remaining_lots',
        'buy_price',
        'buy_fee',
        'deducted_from_cash',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'stock_id' => 'integer',
            'opened_at' => 'date:Y-m-d',
            'initial_lots' => 'integer',
            'remaining_lots' => 'integer',
            'buy_price' => 'float',
            'buy_fee' => 'float',
            'deducted_from_cash' => 'boolean',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(InvestmentStock::class, 'stock_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
