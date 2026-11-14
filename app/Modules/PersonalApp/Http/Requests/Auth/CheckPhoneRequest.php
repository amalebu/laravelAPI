<?php

namespace App\Modules\PersonalApp\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CheckPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone') && $this->phone) {
            $digits = preg_replace('/[^0-9]/', '', (string) $this->phone);
            if (str_starts_with($digits, '08')) {
                $digits = '62' . substr($digits, 1);
            } elseif (str_starts_with($digits, '8')) {
                $digits = '62' . $digits;
            } elseif (str_starts_with($digits, '0')) {
                $digits = '62' . substr($digits, 1);
            }
            $this->merge(['phone' => $digits]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^628[1-9][0-9]{6,11}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'Nomor ponsel wajib diisi.',
            'phone.min' => 'Nomor ponsel minimal 8 digit.',
            'phone.regex' => 'Format nomor ponsel Indonesia tidak valid (contoh: 81234567890).',
        ];
    }
}
