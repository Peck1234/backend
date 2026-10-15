<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Use after auth:sanctum. Lets only admin accounts through.
class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['detail' => 'เฉพาะผู้ดูแลระบบเท่านั้นที่ทำรายการนี้ได้'], 403);
        }

        return $next($request);
    }
}
