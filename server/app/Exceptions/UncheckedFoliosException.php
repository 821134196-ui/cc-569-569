<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 仍有未核对房单，不得封账。
 */
class UncheckedFoliosException extends HttpException
{
    public readonly array $uncheckedFolios;

    public function __construct(array $uncheckedFolios)
    {
        $this->uncheckedFolios = $uncheckedFolios;
        parent::__construct(422, '仍有 '.count($uncheckedFolios).' 份房单未核对，不得封账');
    }
}
