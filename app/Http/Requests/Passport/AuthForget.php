<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class AuthForget extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) $this->merge(['email' => strtolower(trim($this->input('email')))]);
    }

    public function rules()
    {
        return [
            'email' => 'required|string|email:strict|max:255',
            'password' => 'required|string|min:8|max:1024',
            'email_code' => 'required|string|digits:6'
        ];
    }

    public function messages()
    {
        return [
            'email.required' => __('Email can not be empty'),
            'email.email' => __('Email format is incorrect'),
            'password.required' => __('Password can not be empty'),
            'password.min' => __('Password must be greater than 8 digits'),
            'email_code.required' => __('Email verification code cannot be empty'),
            'email_code.digits' => __('Incorrect email verification code')
        ];
    }
}
