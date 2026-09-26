<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RunSimulationRequest extends FormRequest
{
    /**
     * The most spins one web request may simulate (roughly 15 seconds).
     */
    public const MAX_SPINS = 300_000;

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
            'spins' => ['required', 'integer', 'min:10000', 'max:'.self::MAX_SPINS],
            'denomination' => ['required', 'integer', Rule::in(array_keys(config('hay_link.denominations')))],
            'credits_per_line' => ['required', 'integer', Rule::in(config('hay_link.credits_per_line_options'))],
        ];
    }
}
