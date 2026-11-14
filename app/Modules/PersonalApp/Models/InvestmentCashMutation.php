<?php

namespace App\Modules\PersonalApp\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentCashMutation extends BaseModel
{
    protected $table = 'investment_cash_mutations';

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balance_after',
        'description',
        'reference_id',
        'transacted_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'amount' => 'float',
            'balance_after' => 'float',
            'reference_id' => 'integer',
            'transacted_at' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
