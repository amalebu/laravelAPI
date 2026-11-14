<?php

namespace App\Modules\PersonalApp\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LinkPhoneRequest extends FormRequest
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
            'pin' => ['required', 'string', 'digits:6'],
            'pin_confirmation' => ['nullable', 'string', 'digits:6', 'same:pin'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'Nomor ponsel wajib diisi.',
            'phone.regex' => 'Format nomor ponsel tidak valid.',
            'pin.required' => 'PIN 6 digit wajib diisi.',
            'pin.digits' => 'PIN harus terdiri dari 6 angka.',
            'pin_confirmation.same' => 'Konfirmasi PIN tidak cocok dengan PIN yang dimasukkan.',
        ];
    }
}
