<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleAccountException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Google account authentication could not be completed.');
    }
}
