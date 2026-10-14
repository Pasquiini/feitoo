<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Xml;

enum FiscalXmlEnvelope: string
{
    case Nfe = 'NFe';
    case ProcessedNfe = 'nfeProc';
}
