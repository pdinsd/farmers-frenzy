<?php

namespace App\Http\Requests;

use App\Games\HayLink\MachineConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ResetRequest extends FormRequest
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
            'deposit' => ['required', 'numeric', 'decimal:0,2', 'min:1', 'max:'.$this->maxDepositDollars()],
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
            'deposit.min' => 'Deposit at least $1.00.',
            'deposit.max' => 'Deposits are limited to $'.number_format($this->maxDepositDollars(), 2).'.',
            'deposit.decimal' => 'Enter a dollar amount with at most two decimal places.',
        ];
    }

    /**
     * The largest deposit the attendant allows, in dollars.
     */
    public function maxDepositDollars(): float
    {
        return app(MachineConfig::class)->settings()['max_deposit_cents'] / 100;
    }

    /**
     * The deposit in cents.
     */
    public function depositCents(): int
    {
        return (int) round((float) $this->input('deposit') * 100);
    }
}
