<?php

namespace App\Modules\PersonalApp\Http\Requests\Investment;

use Illuminate\Foundation\Http\FormRequest;

class BuyStockLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ticker' => ['required', 'string', 'max:20'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'opened_at' => ['nullable', 'date'],
            'lots' => ['required', 'integer', 'min:1', 'max:10000000'],
            'buy_price' => ['required', 'numeric', 'min:1', 'max:100000000'],
            'buy_fee' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'deduct_from_cash' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'ticker.required' => 'Kode saham (Ticker) wajib diisi (contoh: BBCA, PSAT).',
            'lots.required' => 'Jumlah lot pembelian wajib diisi.',
            'lots.min' => 'Jumlah minimal pembelian adalah 1 Lot (100 lembar).',
            'buy_price.required' => 'Harga pembukaan per lembar wajib diisi.',
            'buy_price.min' => 'Harga pembukaan per lembar minimal Rp 1.',
        ];
    }
}
