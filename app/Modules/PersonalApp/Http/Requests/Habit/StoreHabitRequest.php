<?php

namespace App\Modules\PersonalApp\Http\Requests\Habit;

use Illuminate\Foundation\Http\FormRequest;

class StoreHabitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', 'string', 'in:good,bad'],
            'icon' => ['nullable', 'string', 'max:50'],
            'unit' => ['required', 'string', 'max:40'],
            'target_value' => ['required', 'numeric', 'min:0', 'max:100000'],
            'step_value' => ['required', 'numeric', 'min:0.01', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama kebiasaan wajib diisi.',
            'type.in' => 'Tipe kebiasaan harus good (kebiasaan baik) atau bad (hilangkan kebiasaan buruk).',
            'unit.required' => 'Satuan target wajib diisi (misal: Liter, Menit, Gelas, kali).',
            'target_value.required' => 'Angka target / batas maksimal wajib diisi.',
            'step_value.required' => 'Takaran per klik wajib diisi.',
        ];
    }
}
