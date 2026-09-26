<?php

namespace App\Games\HayLink;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class InsufficientCreditsException extends RuntimeException
{
    public function __construct(public readonly int $credits, public readonly int $totalBet)
    {
        parent::__construct("Insufficient credits: {$credits} available, {$totalBet} required.");
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => 'Insufficient credits for this bet.'], 422);
    }
}
