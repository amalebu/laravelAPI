<?php

namespace App\Modules\PersonalApp\Http\Requests\Auth;

use App\Modules\PersonalApp\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
        $userId = $this->user()?->id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'avatar' => ['nullable', 'string'],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:150',
                Rule::unique(User::class, 'email')->ignore($userId),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique(User::class, 'phone')->ignore($userId),
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ];
    }
}
