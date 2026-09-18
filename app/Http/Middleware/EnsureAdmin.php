<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * อนุญาตเฉพาะ role = admin (ใช้ต่อจาก Authenticate)
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $me = app()->bound('current_user') ? app('current_user') : null;

        if (! $me || ! $me->isAdmin()) {
            abort(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
        }

        return $next($request);
    }
}
