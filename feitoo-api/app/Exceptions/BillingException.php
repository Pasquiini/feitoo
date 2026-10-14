<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingException extends HttpException
{
    public function __construct(public readonly string $reason, string $message, int $status = 422)
    {
        parent::__construct($status, $message);
    }
}
