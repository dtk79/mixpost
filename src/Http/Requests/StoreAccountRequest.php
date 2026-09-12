<?php

namespace Inovector\Mixpost\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreAccountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower($this->string('email')->trim()->toString()),
        ]);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254', 'ends_with:@peachyhq.com'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.ends_with' => 'Please use your @peachyhq.com work email.',
        ];
    }
}
