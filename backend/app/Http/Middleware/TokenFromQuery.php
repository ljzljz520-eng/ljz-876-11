<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 证据截图通过 <img src>/<a href> 访问，浏览器无法附加 Authorization 头，
 * 允许使用临时 query 参数 ?token=xxx 传递 Sanctum Token。
 */
class TokenFromQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->headers->has('Authorization') && $request->query('token')) {
            $request->headers->set('Authorization', 'Bearer ' . $request->query('token'));
        }

        return $next($request);
    }
}
