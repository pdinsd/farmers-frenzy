<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMachineSettingsRequest extends FormRequest
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
            'rtp_program' => ['required', 'integer', Rule::in(array_keys(config('hay_link.rtp_programs')))],
            'denominations_enabled' => ['required', 'array', 'min:1'],
            'denominations_enabled.*' => ['integer', Rule::in(array_keys(config('hay_link.denominations')))],
            'max_credits_per_line' => ['required', 'integer', Rule::in(config('hay_link.credits_per_line_options'))],
            'major_seed_dollars' => ['required', 'numeric', 'decimal:0,2', 'min:10', 'max:100000'],
            'major_cap_dollars' => ['required', 'numeric', 'decimal:0,2', 'max:1000000', 'gte:major_seed_dollars'],
            'grand_seed_dollars' => ['required', 'numeric', 'decimal:0,2', 'min:100', 'max:10000000', 'gt:major_seed_dollars'],
            'major_contribution_percent' => ['required', 'numeric', 'min:0', 'max:5'],
            'grand_contribution_percent' => ['required', 'numeric', 'min:0', 'max:5'],
            'jackpot_max_bet_boost' => ['required', 'numeric', 'min:1', 'max:4'],
            'free_games_awarded' => ['required', 'integer', 'min:1', 'max:20'],
            'grand_replaces_values' => ['required', 'boolean'],
            'max_deposit_dollars' => ['required', 'numeric', 'decimal:0,2', 'min:20', 'max:100000'],
            'autoplay_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'denominations_enabled.required' => 'Enable at least one denomination.',
            'denominations_enabled.min' => 'Enable at least one denomination.',
            'grand_seed_dollars.gt' => 'The Grand must start higher than the Major.',
            'major_cap_dollars.gte' => 'The Major cap cannot be below its reset value.',
        ];
    }

    /**
     * The validated settings in the shape the machine stores them (money in cents, rates as fractions).
     *
     * @return array<string, mixed>
     */
    public function machineSettings(): array
    {
        $validated = $this->validated();

        return [
            'rtp_program' => (int) $validated['rtp_program'],
            'denominations_enabled' => array_values(array_map('intval', $validated['denominations_enabled'])),
            'max_credits_per_line' => (int) $validated['max_credits_per_line'],
            'major_seed_cents' => (int) round($validated['major_seed_dollars'] * 100),
            'major_cap_cents' => (int) round($validated['major_cap_dollars'] * 100),
            'grand_seed_cents' => (int) round($validated['grand_seed_dollars'] * 100),
            'major_contribution' => round($validated['major_contribution_percent'] / 100, 6),
            'grand_contribution' => round($validated['grand_contribution_percent'] / 100, 6),
            'jackpot_max_bet_boost' => round((float) $validated['jackpot_max_bet_boost'], 2),
            'free_games_awarded' => (int) $validated['free_games_awarded'],
            'grand_replaces_values' => (bool) $validated['grand_replaces_values'],
            'max_deposit_cents' => (int) round($validated['max_deposit_dollars'] * 100),
            'autoplay_enabled' => (bool) $validated['autoplay_enabled'],
        ];
    }
}
