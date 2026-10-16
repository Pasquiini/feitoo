<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\ProcessingFileError;
use RuntimeException;

final class DocumentException extends RuntimeException
{
    public function __construct(public readonly ProcessingFileError $error)
    {
        parent::__construct($error->message());
    }
}
