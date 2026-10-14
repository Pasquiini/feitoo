<?php

declare(strict_types=1);

namespace App\Support\Xml;

use RuntimeException;

final class InvalidXml extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('XML inválido ou excedendo os limites de segurança.');
    }
}
