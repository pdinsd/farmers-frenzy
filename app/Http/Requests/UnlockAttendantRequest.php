<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UnlockAttendantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * Whether the entered PIN matches the configured attendant PIN.
     */
    public function pinMatches(): bool
    {
        return hash_equals((string) config('hay_link.attendant.pin'), (string) $this->input('pin'));
    }
}
