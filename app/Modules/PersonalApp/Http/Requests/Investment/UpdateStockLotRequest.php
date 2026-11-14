<?php

namespace App\Modules\PersonalApp\Http\Requests\Investment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStockLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'opened_at' => ['required', 'date'],
            'lots' => ['required', 'integer', 'min:1', 'max:10000000'],
            'buy_price' => ['required', 'numeric', 'min:1', 'max:100000000'],
            'buy_fee' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'opened_at.required' => 'Tanggal pembukaan wajib diisi.',
            'lots.required' => 'Jumlah lot wajib diisi.',
            'lots.min' => 'Jumlah lot minimal adalah 1 Lot.',
            'buy_price.required' => 'Harga pembukaan wajib diisi.',
        ];
    }
}
