<?php

namespace App\Http\Requests;

use App\Games\HayLink\MachineConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlayRequest extends FormRequest
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
            'credits_per_line' => ['sometimes', 'integer', Rule::in(app(MachineConfig::class)->effective()['credits_per_line_options'])],
        ];
    }
}
