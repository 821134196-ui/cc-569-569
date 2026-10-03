<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 营业日已封账（并发封账时只有一方不会收到此异常）。
 */
class AlreadyClosedException extends HttpException
{
    public function __construct(string $date)
    {
        parent::__construct(409, "营业日 {$date} 已封账，本次封账请求被拒绝");
    }
}
