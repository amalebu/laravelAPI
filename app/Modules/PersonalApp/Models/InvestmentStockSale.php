<?php

namespace App\Modules\PersonalApp\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentStockSale extends BaseModel
{
    protected $table = 'investment_stock_sales';

    protected $fillable = [
        'user_id',
        'stock_id',
        'stock_lot_id',
        'sold_at',
        'sold_lots',
        'buy_avg_price',
        'sell_price',
        'sell_fee',
        'gross_proceeds',
        'net_proceeds',
        'realized_pl',
        'realized_pl_percent',
        'added_to_cash',
        'notes',
        'consumed_lots',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'stock_id' => 'integer',
            'stock_lot_id' => 'integer',
            'sold_at' => 'date:Y-m-d',
            'sold_lots' => 'integer',
            'buy_avg_price' => 'float',
            'sell_price' => 'float',
            'sell_fee' => 'float',
            'gross_proceeds' => 'float',
            'net_proceeds' => 'float',
            'realized_pl' => 'float',
            'realized_pl_percent' => 'float',
            'added_to_cash' => 'boolean',
            'consumed_lots' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(InvestmentStock::class, 'stock_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InvestmentStockLot::class, 'stock_lot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
