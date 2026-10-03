<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/** 当前没有开放的营业日（或目标营业日已关闭，只能走调整单） —— HTTP 409 */
class NoOpenBusinessDayException extends RuntimeException
{
    public function __construct(string $message = '当前没有开放的营业日')
    {
        parent::__construct($message);
    }
}
