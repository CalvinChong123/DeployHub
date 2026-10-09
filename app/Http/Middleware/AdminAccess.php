<?php

namespace App\Http\Middleware;

use App\Services\Access;
use Closure;
use Illuminate\Http\Request;

class AdminAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(app(Access::class)->admin($request->user(), $request->user()->workspace()->id), 403);

        return $next($request);
    }
}
