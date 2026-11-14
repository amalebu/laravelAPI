<?php

namespace App\Modules\PersonalApp\Http\Requests\Habit;

use Illuminate\Foundation\Http\FormRequest;

class LogHabitProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logged_date' => ['nullable', 'date_format:Y-m-d'],
            'mode' => ['nullable', 'string', 'in:increment,set'],
            'value' => ['required', 'numeric', 'min:-100000', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'logged_date.date_format' => 'Format tanggal log harus YYYY-MM-DD.',
            'mode.in' => 'Mode pencatatan harus increment atau set.',
            'value.required' => 'Nilai progres wajib diisi.',
            'value.numeric' => 'Nilai progres harus berupa angka.',
        ];
    }
}
