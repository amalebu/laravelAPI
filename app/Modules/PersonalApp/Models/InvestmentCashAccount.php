<?php

namespace App\Modules\PersonalApp\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentCashAccount extends BaseModel
{
    protected $table = 'investment_cash_accounts';

    protected $fillable = [
        'user_id',
        'balance',
        'total_topup',
        'total_withdrawn',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'balance' => 'float',
            'total_topup' => 'float',
            'total_withdrawn' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
