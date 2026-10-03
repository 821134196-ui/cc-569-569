<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * 纯日期字段（营业日归属）的存取：库中只存 'Y-m-d' 字符串，
 * 读取时返回 Carbon。避免 Laravel 默认 date cast 存成 'Y-m-d H:i:s'
 * 导致字符串等值比较（查询与 SQLite 触发器）失效。
 *
 * @implements CastsAttributes<Carbon|null, Carbon|string|null>
 */
class DateOnlyString implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toDateString();
    }
}
