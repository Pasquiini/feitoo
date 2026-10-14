<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fiscal\Nfe\NfeParser;
use App\Domain\Tools\XmlToExcel\XmlToExcelGenerator;
use App\Enums\ProcessingFileKind;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessFiscalFilesJob;
use App\Models\Processing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class XmlToExcelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('processing');
        $this->actingAs(User::factory()->create());
    }

    public function test_workbook_contains_notes_items_safe_types_values_and_formatting(): void
    {
        $batch = $this->batch([$this->xml()]);
        $output = $batch->output()->firstOrFail();
        $book = IOFactory::load(Storage::disk('processing')->path($output->storage_path));
        try {
            $this->assertSame(['Notas', 'Itens'], $book->getSheetNames());
            $notes = $book->getSheetByName('Notas');
            $items = $book->getSheetByName('Itens');
            $this->assertSame(2, $notes->getHighestDataRow());
            $this->assertSame(3, $items->getHighestDataRow());
            $this->assertSame('35261000000000000100550010000001231000001234', $notes->getCell('A2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $notes->getCell('A2')->getDataType());
            $this->assertSame('00000000000100', $notes->getCell('F2')->getValue());
            $this->assertSame('001', $notes->getCell('C2')->getValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $notes->getCell('AA2')->getDataType());
            $this->assertEquals(25, $notes->getCell('AA2')->getValue());
            $this->assertEquals(2, $notes->getCell('Q2')->getValue());
            $this->assertSame('01/10/2026 12:10', $notes->getCell('D2')->getFormattedValue());
            $this->assertSame('-03:00', $notes->getCell('AE2')->getValue());
            $this->assertSame('A2', $notes->getFreezePane());
            $this->assertSame('A1:AE2', $notes->getAutoFilter()->getRange());
            $this->assertTrue($notes->getStyle('A1')->getFont()->getBold());
            $this->assertStringContainsString('ç', implode(' ', $items->rangeToArray('J2:J3')[0]));
            // Windows does not report POSIX visibility reliably, including Storage::fake.
            $this->assertSame('processing', $output->storage_disk);
            $this->assertSame('private', config('filesystems.disks.processing.visibility'));
            $this->assertFalse(config('filesystems.disks.processing.serve'));
            $this->assertStringStartsWith('processings/'.$batch->uuid.'/output/', $output->storage_path);
            $this->assertSame('xlsx', $output->extension);
            $this->assertTrue($batch->expires_at->equalTo($output->expires_at));
            $this->assertSame(1, $batch->files_count);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_mixed_multiple_documents_export_only_successful_inputs_and_reexecution_is_idempotent(): void
    {
        $batch = $this->batch([$this->xml(), '<bad>', $this->xml()]);
        $output = $batch->output()->firstOrFail();
        $book = IOFactory::load(Storage::disk('processing')->path($output->storage_path));
        $this->assertSame(3, $book->getSheetByName('Notas')->getHighestDataRow());
        $this->assertSame(5, $book->getSheetByName('Itens')->getHighestDataRow());
        $book->disconnectWorksheets();
        $this->assertSame(2, $batch->successful_files_count);
        $this->assertSame(1, $batch->failed_files_count);
        app()->call([(new ProcessFiscalFilesJob($batch->uuid)), 'handle']);
        $this->assertSame(1, $batch->output()->count());
    }

    public function test_zero_valid_documents_has_safe_failure_and_no_output(): void
    {
        $batch = $this->batch(['<bad/>']);
        $this->assertSame(ProcessingStatus::Failed, $batch->status);
        $this->assertSame(0, $batch->output()->count());
        $this->getJson('/api/processings/'.$batch->uuid)->assertOk()
            ->assertJsonPath('data.result.available', false)
            ->assertJsonPath('data.result.error_message', 'Não houve documentos válidos para exportação.');
    }

    public function test_formula_injection_is_text_and_large_decimals_remain_exact(): void
    {
        $xml = str_replace('Indústria Fictícia São João', '=HYPERLINK("https://example.test")', $this->xml());
        $xml = str_replace('<vNF>25.00</vNF>', '<vNF>123456789012345.123456789</vNF>', $xml);
        $batch = $this->batch([$xml]);
        $book = IOFactory::load(Storage::disk('processing')->path($batch->output()->firstOrFail()->storage_path));
        $notes = $book->getSheetByName('Notas');
        $this->assertSame(DataType::TYPE_STRING, $notes->getCell('G2')->getDataType());
        $this->assertStringStartsWith('=HYPERLINK', $notes->getCell('G2')->getValue());
        $this->assertSame('123456789012345.123456789', $notes->getCell('AA2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $notes->getCell('AA2')->getDataType());
        foreach ($book->getWorksheetIterator() as $sheet) {
            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                $this->assertNotSame(DataType::TYPE_FORMULA, $sheet->getCell($coordinate)->getDataType());
            }
        }
        $book->disconnectWorksheets();
    }

    public function test_authenticated_download_works_without_private_path_and_other_owner_cannot_download(): void
    {
        $batch = $this->batch([$this->xml()]);
        $output = $batch->output()->firstOrFail();
        $url = '/api/processings/'.$batch->uuid;
        $response = $this->get($url.'/result')->assertOk();
        $response->assertHeader('Content-Type', $output->mime_type);
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));
        $this->assertSame(Storage::disk('processing')->get($output->storage_path), $response->streamedContent());
        $response = $this->getJson($url)->assertOk()->assertJsonPath('data.result.available', true);
        $this->assertStringNotContainsString($output->storage_path, $response->getContent());
        $this->getJson($url.'/files')->assertJsonCount(1, 'data');
        $this->actingAs(User::factory()->create());
        $this->getJson($url.'/result')->assertNotFound();
    }

    public function test_formula_prefixes_remain_literal_and_identifiers_keep_leading_zeroes(): void
    {
        foreach (['=HYPERLINK(test)', '+SUM(1)', '-SUM(1)', '@SUM(1)'] as $text) {
            $xml = str_replace('Indústria Fictícia São João', $text, $this->xml());
            $batch = $this->batch([$xml]);
            $book = IOFactory::load(Storage::disk('processing')->path($batch->output()->firstOrFail()->storage_path));
            $this->assertSame($text, $book->getSheetByName('Notas')->getCell('G2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $book->getSheetByName('Notas')->getCell('G2')->getDataType());
            $book->disconnectWorksheets();
        }
    }

    public function test_date_only_optional_fields_and_decimal_values_match_parser(): void
    {
        $xml = file_get_contents(__DIR__.'/../Fixtures/Fiscal/nfe-minimal.xml');
        $document = app(NfeParser::class)->parse($xml);
        $batch = $this->batch([$xml]);
        $book = IOFactory::load(Storage::disk('processing')->path($batch->output()->firstOrFail()->storage_path));
        $notes = $book->getSheetByName('Notas');
        $items = $book->getSheetByName('Itens');
        $this->assertSame('04/03/2012 00:00', $notes->getCell('D2')->getFormattedValue());
        $this->assertNull($notes->getCell('AE2')->getValue());
        $this->assertNull($notes->getCell('AB2')->getValue());
        $this->assertNull($notes->getCell('AA2')->getValue());
        $this->assertSame($document->items[0]->productCode, $items->getCell('I2')->getValue());
        $this->assertSame($document->items[0]->gtin, $items->getCell('K2')->getValue());
        $this->assertEquals((float) $document->items[0]->commercialQuantity, $items->getCell('P2')->getValue());
        $this->assertEquals((float) $document->items[0]->commercialUnitPrice, $items->getCell('Q2')->getValue());
        $this->assertEquals((float) $document->items[0]->productTotal, $items->getCell('R2')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_download_requires_authentication_and_ready_output(): void
    {
        $batch = $this->batch([$this->xml()]);
        $url = '/api/processings/'.$batch->uuid.'/result';
        $batch->update(['status' => ProcessingStatus::Processing]);
        $this->getJson($url)->assertConflict();
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_expired_removed_and_missing_outputs_cannot_be_downloaded(): void
    {
        $batch = $this->batch([$this->xml()]);
        $url = '/api/processings/'.$batch->uuid;
        $output = $batch->output()->firstOrFail();
        Storage::disk('processing')->delete($output->storage_path);
        $this->getJson($url.'/result')->assertNotFound();
        $batch->update(['expires_at' => now()->subSecond()]);
        $this->getJson($url.'/result')->assertStatus(410);
        $this->getJson($url)->assertJsonPath('data.result.available', false);
        $this->deleteJson($url.'/files')->assertOk();
        $this->assertSame(0, $batch->output()->count());
    }

    public function test_generation_failure_preserves_parsing_and_does_not_complete_batch(): void
    {
        $this->mock(XmlToExcelGenerator::class)->shouldReceive('generate')->andThrow(new \RuntimeException('SECRET'));
        try {
            $this->batch([$this->xml()]);
            $this->fail('Expected generation error.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fiscal processing attempt failed.', $exception->getMessage());
        }
        $batch = Processing::firstOrFail();
        $this->assertSame(ProcessingStatus::Processing, $batch->status);
        $this->assertSame(1, $batch->successful_files_count);
        $this->assertSame(0, $batch->output()->count());
        (new ProcessFiscalFilesJob($batch->uuid))->failed(null);
        $this->assertSame(ProcessingStatus::Failed, $batch->fresh()->status);
    }

    private function batch(array $xmls): Processing
    {
        $batch = Processing::create(['user_id' => auth()->id(), 'tool' => 'xml-to-excel', 'status' => 'queued',
            'expires_at' => now()->addHours(2), 'files_count' => count($xmls)]);
        foreach ($xmls as $index => $xml) {
            $path = 'processings/'.$batch->uuid.'/input/'.$index.'.xml';
            Storage::disk('processing')->put($path, $xml);
            $batch->files()->create(['kind' => ProcessingFileKind::Input, 'original_name' => 'synthetic.xml',
                'stored_name' => $index.'.xml', 'storage_disk' => 'processing', 'storage_path' => $path,
                'mime_type' => 'text/xml', 'extension' => 'xml', 'size' => strlen($xml), 'checksum' => hash('sha256', $xml),
                'expires_at' => $batch->expires_at]);
        }
        app()->call([(new ProcessFiscalFilesJob($batch->uuid)), 'handle']);

        return $batch->fresh();
    }

    private function xml(): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/Fiscal/nfe-proc.xml');
    }
}
