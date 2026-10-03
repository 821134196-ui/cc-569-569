<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /** 用户列表（前端切换操作人用）。 */
    public function index(): JsonResponse
    {
        return response()->json(
            User::orderBy('id')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role,
            ])
        );
    }

    /** 当前请求解析出的操作人。 */
    public function me(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('actor');

        return response()->json($actor ? [
            'id' => $actor->id,
            'name' => $actor->name,
            'role' => $actor->role,
        ] : null);
    }
}
