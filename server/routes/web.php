<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

// React 单页应用（构建产物在 server/spa，由以下路由显式托管，
// 避免与 public 下物理目录冲突导致内置服务器误解析子路径）
Route::get('/app/assets/{path}', function (string $path) {
    $file = base_path('spa/assets/'.str_replace('..', '', $path));
    abort_unless(is_file($file), 404);

    return response()->file($file);
})->where('path', '.*');

Route::get('/app/{any?}', function () {
    return response()->file(base_path('spa/index.html'));
})->where('any', '.*');
