<?php

namespace App\Modules\PersonalApp\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleLoginRequest extends FormRequest
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
            'access_token' => ['nullable', 'string', 'required_without_all:id_token,credential,code'],
            'id_token' => ['nullable', 'string', 'required_without_all:access_token,credential,code'],
            'credential' => ['nullable', 'string', 'required_without_all:access_token,id_token,code'],
            'code' => ['nullable', 'string', 'required_without_all:access_token,id_token,credential'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'access_token.required_without_all' => 'Token otentikasi Google wajib disertakan.',
        ];
    }
}
