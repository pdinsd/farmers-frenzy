<?php

namespace App\Http\Requests;

use App\Games\HayLink\MachineConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeDenominationRequest extends FormRequest
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
            'denomination' => ['required', 'integer', Rule::in(array_keys(app(MachineConfig::class)->effective()['denominations']))],
        ];
    }
}
