<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/** 营业日已被（其他夜审员）封账 —— HTTP 409 */
class DayAlreadyClosedException extends RuntimeException
{
    public function __construct(string $date)
    {
        parent::__construct("营业日 {$date} 已封账，本次封账未生效");
    }
}
