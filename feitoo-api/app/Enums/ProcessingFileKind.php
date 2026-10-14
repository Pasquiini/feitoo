<?php

namespace App\Enums;

enum ProcessingFileKind: string
{
    case Input = 'input';
    case Output = 'output';
}
