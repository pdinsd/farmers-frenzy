<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only an attendant who has entered the PIN may change machine settings.
 */
class EnsureAttendantUnlocked
{
    public const SESSION_KEY = 'hay_link.attendant_unlocked';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->session()->get(self::SESSION_KEY) === true, 403, 'The attendant panel is locked.');

        return $next($request);
    }
}
