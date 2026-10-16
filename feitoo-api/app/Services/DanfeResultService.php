<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Processing;
use App\Models\ProcessingFile;

class DanfeResultService
{
    public function savePdf(ProcessingFile $input, string $key, string $pdf): void
    {
        app(PdfResultService::class)->store($input->processing, $input->uuid.'.pdf', 'DANFE-'.$key.'.pdf', 'application/pdf', $pdf);
    }

    public function generate(Processing $processing): void
    {
        app(PdfResultService::class)->generate($processing, 'danfes.zip', 'feitoo-danfes.zip', DanfeGenerator::NOTICE);
    }
}
