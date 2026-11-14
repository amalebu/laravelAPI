<?php

namespace App\Modules\PersonalApp\Http\Requests\Todo;

use Illuminate\Foundation\Http\FormRequest;

class StoreTodoItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul sub-checklist wajib diisi.',
            'title.max' => 'Judul sub-checklist maksimal 255 karakter.',
        ];
    }
}
