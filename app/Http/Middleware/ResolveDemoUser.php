<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 本地演示用的简化身份识别：前端在请求头携带 X-User-Id，
 * 中间件把对应用户挂到请求属性上。角色校验在控制器/表单请求里做。
 */
class ResolveDemoUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->header('X-User-Id');
        if ($userId !== null) {
            $user = User::find($userId);
            if ($user !== null) {
                $request->attributes->set('actor', $user);
            }
        }

        return $next($request);
    }
}
