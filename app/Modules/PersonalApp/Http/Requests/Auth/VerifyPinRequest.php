<?php

namespace App\Modules\PersonalApp\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'digits:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.required' => 'PIN 6 digit wajib diisi.',
            'pin.digits' => 'PIN harus terdiri dari 6 angka.',
        ];
    }
}
