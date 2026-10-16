<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProcessingFileError;
use App\Services\Documents\ConversionProcessRunner;
use App\Services\Documents\LibreOfficeDocxToPdfConverter;
use App\Services\Documents\PdfOutputValidator;
use App\Support\Documents\DocumentException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocxConverterIntegrationTest extends TestCase
{
    public function test_process_runner_enforces_timeout_without_exposing_process_output(): void
    {
        try {
            app(ConversionProcessRunner::class)->run([PHP_BINARY, '-r', 'fwrite(STDERR, "sensitive text"); sleep(5);'], base_path(), 1);
            $this->fail('Timeout not enforced');
        } catch (DocumentException $exception) {
            $this->assertSame(ProcessingFileError::ConversionTimeout, $exception->error);
            $this->assertStringNotContainsString('sensitive', (string) $exception);
        }
    }

    public function test_real_libreoffice_optional(): void
    {
        if (getenv('FEITOO_TEST_LIBREOFFICE') !== '1') {
            $this->markTestSkipped('Optional real LibreOffice test: see docs/docx-to-pdf-production.md.');
        }
        foreach (glob(base_path('tests/Fixtures/Documents/*.docx')) as $fixture) {
            $directory = 'document-integration/'.Str::uuid();
            $disk = Storage::disk('local');
            $disk->makeDirectory($directory);
            $workspace = $disk->path($directory);
            try {
                copy($fixture, $workspace.'/input.docx');
                $pdf = app(LibreOfficeDocxToPdfConverter::class)->convert($workspace.'/input.docx', $workspace);
                app(PdfOutputValidator::class)->validate($pdf);
                $this->assertGreaterThan(40, filesize($pdf));
            } finally {
                $disk->deleteDirectory($directory);
            }
        }
    }
}
