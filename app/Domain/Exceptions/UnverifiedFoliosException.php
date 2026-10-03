<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/** 仍有未核对房单，不得封账 —— HTTP 422 */
class UnverifiedFoliosException extends RuntimeException
{
    public function __construct(public readonly array $unverifiedFolioNos)
    {
        parent::__construct('仍有未核对房单：'.implode('、', $unverifiedFolioNos));
    }
}
