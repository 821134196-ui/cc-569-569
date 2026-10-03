<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class Controller
{
    /** 当前操作人（由 ResolveDemoUser 中间件从 X-User-Id 解析）。 */
    protected function actor(Request $request): User
    {
        $user = $request->attributes->get('actor');
        if (! $user instanceof User) {
            throw new HttpException(401, '缺少有效的 X-User-Id 请求头');
        }

        return $user;
    }
}
