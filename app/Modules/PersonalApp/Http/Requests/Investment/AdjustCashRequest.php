<?php

namespace App\Modules\PersonalApp\Http\Requests\Investment;

use Illuminate\Foundation\Http\FormRequest;

class AdjustCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isAdjustment = $this->input('type') === 'adjustment';

        return [
            'type' => ['required', 'string', 'in:topup,withdraw,adjustment'],
            'amount' => [
                'required',
                'numeric',
                $isAdjustment ? 'min:-999999999999' : 'min:0',
                'max:999999999999',
            ],
            'transacted_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Jenis transaksi Cash wajib dipilih.',
            'type.in' => 'Jenis transaksi harus berupa topup, withdraw, atau adjustment.',
            'amount.required' => 'Nominal uang Cash wajib diisi.',
            'amount.min' => 'Nominal uang tidak boleh negatif.',
        ];
    }
}
