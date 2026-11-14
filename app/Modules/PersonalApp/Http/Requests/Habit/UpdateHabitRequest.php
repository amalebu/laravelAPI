<?php

namespace App\Modules\PersonalApp\Http\Requests\Habit;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHabitRequest extends FormRequest
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
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama kebiasaan wajib diisi.',
            'type.in' => 'Tipe kebiasaan harus good atau bad.',
            'unit.required' => 'Satuan wajib diisi.',
            'target_value.required' => 'Angka target / batas maksimal wajib diisi.',
            'step_value.required' => 'Takaran per klik wajib diisi.',
        ];
    }
}
