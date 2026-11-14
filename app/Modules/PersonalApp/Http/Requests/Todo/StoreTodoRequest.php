<?php

namespace App\Modules\PersonalApp\Http\Requests\Todo;

use Illuminate\Foundation\Http\FormRequest;

class StoreTodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', 'string', 'in:low,medium,high'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.is_completed' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul tugas wajib diisi.',
            'title.max' => 'Judul tugas maksimal 255 karakter.',
            'priority.in' => 'Prioritas harus salah satu dari: low, medium, atau high.',
            'due_date.date_format' => 'Format tanggal jatuh tempo harus YYYY-MM-DD.',
            'due_time.date_format' => 'Format jam jatuh tempo harus HH:mm.',
            'items.*.title.required' => 'Judul item sub-checklist tidak boleh kosong.',
        ];
    }
}
