<?php

namespace App\Modules\PersonalApp\Http\Requests\Investment;

use Illuminate\Foundation\Http\FormRequest;

class ClosePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lot_id' => ['nullable', 'integer', 'required_without:stock_id'],
            'stock_id' => ['nullable', 'integer', 'required_without:lot_id'],
            'sold_at' => ['nullable', 'date'],
            'sold_lots' => ['required', 'integer', 'min:1', 'max:10000000'],
            'sell_price' => ['required', 'numeric', 'min:1', 'max:100000000'],
            'sell_fee' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'add_to_cash' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'sold_lots.required' => 'Jumlah lot yang ditutup wajib diisi.',
            'sold_lots.min' => 'Minimal penutupan posisi adalah 1 Lot.',
            'sell_price.required' => 'Harga penutupan/jual per lembar wajib diisi.',
        ];
    }
}
