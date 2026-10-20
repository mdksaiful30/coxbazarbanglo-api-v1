<?php

namespace App\Http\Middleware;

use Closure;

class ActiveUser
{
    public function handle($request, Closure $next)
    {
        if (! $request->user()?->active) {
            $request->user()?->tokens()->delete();
            abort(403, 'This account is disabled.');
        }

        return $next($request);
    }
}
