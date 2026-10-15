<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fiscal\Nfe\NfeParser;
use App\Enums\ProcessingTool;
use App\Models\Processing;
use App\Models\User;
use App\Services\DanfeGenerator;
use App\Services\FiscalProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DanfeProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('processing');
        Storage::fake('local');
        Queue::fake();
        $this->actingAs(User::factory()->create(), 'web');
        config(['feitoo.processing.queue_connection' => 'database']);
    }

    private function xml(int $number = 123, int $items = 2): string
    {
        // Synthetic homologation XML: structurally consistent, not digitally signed.
        $xml = file_get_contents(base_path('tests/Fixtures/Fiscal/nfe-proc.xml'));
        $old = '35261000000000000100550010000001231000001234';
        $base = '3526100000000000010055001'.str_pad((string) $number, 9, '0', STR_PAD_LEFT).'100000123';
        $sum = 0;
        for ($n = 42, $w = 2; $n >= 0; $n--, $w = $w === 9 ? 2 : $w + 1) {
            $sum += (int) $base[$n] * $w;
        }
        $digit = 11 - $sum % 11;
        $key = $base.($digit >= 10 ? 0 : $digit);
        $xml = str_replace([$old, '<cDV>4</cDV>', '<nNF>123</nNF>'], [$key, '<cDV>'.$key[43].'</cDV>', '<nNF>'.$number.'</nNF>'], $xml);
        $xml = str_replace('</infNFe></NFe>', '</infNFe><Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignedInfo><Reference URI="#NFe'.$key.'"><DigestValue>RmljdGljaW8=</DigestValue></Reference></SignedInfo></Signature></NFe>', $xml);
        $xml = str_replace('<pag>', '<transp><modFrete>9</modFrete></transp><pag>', $xml);
        if ($items > 2) {
            preg_match('/<det nItem="1">.*?<\/det>/s', $xml, $match);
            $extra = '';
            for ($i = 3; $i <= $items; $i++) {
                $extra .= str_replace('nItem="1"', 'nItem="'.$i.'"', $match[0]);
            }
            $xml = str_replace('<total>', $extra.'<total>', $xml);
        }

        return $xml;
    }

    private function batch(array $xmls): Processing
    {
        $id = $this->postJson('/api/processings', ['tool' => 'xml-to-pdf'])->assertCreated()->json('data.id');
        $uploads = [];
        foreach ($xmls as $index => $xml) {
            $uploads[] = UploadedFile::fake()->createWithContent('nota-'.$index.'.xml', $xml);
        }
        $this->post('/api/processings/'.$id.'/files', ['files' => $uploads], ['Accept' => 'application/json'])->assertOk();
        $this->postJson('/api/processings/'.$id.'/start')->assertStatus(202);

        return Processing::where('uuid', $id)->firstOrFail();
    }

    public function test_real_pdf_zip_partial_failures_duplicates_and_idempotent_usage(): void
    {
        $valid = $this->xml();
        $missing = preg_replace('/<protNFe.*?<\/protNFe>/s', '', $valid);
        $batch = $this->batch([$valid, $valid, $missing, str_replace('<mod>55</mod>', '<mod>65</mod>', $valid), $this->xml(124)]);
        $this->assertSame(5, $batch->usage_files_count);
        app(FiscalProcessingService::class)->process($batch->uuid);
        $batch->refresh();
        $this->assertSame('completed', $batch->status->value);
        $this->assertSame(2, $batch->successful_files_count);
        $this->assertSame(3, $batch->failed_files_count);
        $this->assertSame(2, $batch->usage_files_count);
        $files = $this->getJson('/api/processings/'.$batch->uuid.'/files')->assertOk()->json('data');
        $this->assertSame('duplicate_document', $files[1]['error_code']);
        $this->assertSame('invalid_protocol', $files[2]['error_code']);
        $this->assertSame('unsupported_document', $files[3]['error_code']);
        $this->assertStringContainsString('não consultado', $files[0]['document']['notice']);
        $pdf = $this->get('/api/processings/'.$batch->uuid.'/pdf/'.$files[0]['id'])->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->streamedContent());
        $this->get('/api/processings/'.$batch->uuid.'/pdf/'.$files[2]['id'])->assertNotFound();
        $zip = $this->get('/api/processings/'.$batch->uuid.'/result')->assertOk()->streamedContent();
        $this->assertStringStartsWith('PK', $zip);
        $output = $batch->output()->firstOrFail();
        $archive = new \ZipArchive;
        $archive->open(Storage::disk('processing')->path($output->storage_path));
        $this->assertSame(3, $archive->numFiles);
        $this->assertSame(DanfeGenerator::NOTICE, $archive->getFromName('LEIA-ME.txt'));
        $archive->close();
        app(FiscalProcessingService::class)->process($batch->uuid);
        $this->assertSame(2, $batch->fresh()->usage_files_count);
        $this->assertSame(3, $batch->files()->where('kind', 'output')->count());
        $this->postJson('/api/processings/'.$batch->uuid.'/start')->assertConflict();
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/processings/'.$batch->uuid.'/files')->assertNotFound();
        $this->getJson('/api/processings/'.$batch->uuid.'/pdf/'.$files[0]['id'])->assertNotFound();
        $this->getJson('/api/processings/'.$batch->uuid.'/result')->assertNotFound();
    }

    public function test_protocol_key_digest_environment_and_status_must_be_consistent(): void
    {
        $xml = $this->xml();
        $batch = $this->batch([
            str_replace('<cStat>100</cStat>', '<cStat>110</cStat>', $xml),
            str_replace('<digVal>RmljdGljaW8=</digVal>', '<digVal>YWJj</digVal>', $xml),
            str_replace('<infProt><tpAmb>2</tpAmb>', '<infProt><tpAmb>1</tpAmb>', $xml),
            preg_replace('/<chNFe>.*?<\/chNFe>/', '<chNFe>'.str_repeat('1', 44).'</chNFe>', $xml),
        ]);
        app(FiscalProcessingService::class)->process($batch->uuid);
        $this->assertSame('failed', $batch->fresh()->status->value);
        $this->assertSame(0, $batch->fresh()->usage_files_count);
        $this->assertSame(0, $batch->files()->where('kind', 'output')->count());
    }

    public function test_malformed_xxe_and_oversized_uploads_are_rejected(): void
    {
        $id = $this->postJson('/api/processings', ['tool' => ProcessingTool::XmlToPdf->value])->json('data.id');
        foreach (['<NFe>', file_get_contents(base_path('tests/Fixtures/Fiscal/hostile-xxe.xml'))] as $xml) {
            $this->post('/api/processings/'.$id.'/files', ['files' => [UploadedFile::fake()->createWithContent('bad.xml', $xml)]], ['Accept' => 'application/json'])->assertStatus(422);
        }
        config(['feitoo.uploads.max_file_size_kb' => 1]);
        $this->post('/api/processings/'.$id.'/files', ['files' => [UploadedFile::fake()->createWithContent('large.xml', $this->xml())]], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_renderer_handles_many_products_and_generates_multiple_pages(): void
    {
        $xml = $this->xml(items: 90);
        $generator = app(DanfeGenerator::class);
        $generator->validate($xml, app(NfeParser::class)->parse($xml));
        $pdf = $generator->render($xml);
        $this->assertStringStartsWith('%PDF-', $pdf);
        preg_match_all('/\/Type\s*\/Page\b/', $pdf, $pages);
        $this->assertGreaterThan(1, count($pages[0]));
    }

    public function test_expiry_and_deletion_block_pdf_and_remove_private_outputs(): void
    {
        $batch = $this->batch([$this->xml()]);
        app(FiscalProcessingService::class)->process($batch->uuid);
        $input = $batch->files()->where('kind', 'input')->firstOrFail();
        $batch->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/api/processings/'.$batch->uuid.'/pdf/'.$input->uuid)->assertStatus(410);
        $this->deleteJson('/api/processings/'.$batch->uuid.'/files')->assertOk();
        $this->assertSame([], Storage::disk('processing')->allFiles('processings/'.$batch->uuid));
    }

    public function test_render_failure_does_not_charge_or_block_other_documents(): void
    {
        $batch = $this->batch([$this->xml(), $this->xml(124)]);
        $mock = \Mockery::mock(DanfeGenerator::class)->makePartial();
        $mock->shouldReceive('render')->once()->andThrow(new \RuntimeException('Sensitive renderer detail'));
        $mock->shouldReceive('render')->once()->andReturnUsing(fn (string $xml) => (new DanfeGenerator)->render($xml));
        $this->app->instance(DanfeGenerator::class, $mock);
        app(FiscalProcessingService::class)->process($batch->uuid);
        $this->assertSame('completed', $batch->fresh()->status->value);
        $this->assertSame(1, $batch->fresh()->usage_files_count);
        $this->getJson('/api/processings/'.$batch->uuid.'/files')->assertJsonPath('data.0.error_code', 'pdf_error');
    }

    public function test_deleting_active_batch_releases_reservation(): void
    {
        $batch = $this->batch([$this->xml()]);
        $this->assertSame(1, $batch->usage_files_count);
        $this->deleteJson('/api/processings/'.$batch->uuid.'/files')->assertOk();
        $this->assertSame(0, $batch->fresh()->usage_files_count);
        app(FiscalProcessingService::class)->process($batch->uuid);
        $this->assertSame(0, $batch->files()->where('kind', 'output')->count());
    }

    public function test_config_has_no_advance_payment_and_foreign_file_ids_are_rejected(): void
    {
        $this->getJson('/api/tools/xml-to-pdf/config')->assertOk()->assertJsonPath('data.packages', [])->assertJsonPath('data.checkout_available', false);
        $first = $this->batch([$this->xml()]);
        $second = $this->batch([$this->xml(124)]);
        app(FiscalProcessingService::class)->process($first->uuid);
        app(FiscalProcessingService::class)->process($second->uuid);
        $foreign = $second->files()->where('kind', 'input')->firstOrFail();
        $this->getJson('/api/processings/'.$first->uuid.'/pdf/'.$foreign->uuid)->assertNotFound();
    }

    public function test_production_protocol_150_and_single_item_render_with_notice(): void
    {
        $xml = str_replace(['<tpAmb>2</tpAmb>', '<cStat>100</cStat>'], ['<tpAmb>1</tpAmb>', '<cStat>150</cStat>'], $this->xml());
        $xml = preg_replace('/<det nItem="2">.*?<\/det>/s', '', $xml);
        $batch = $this->batch([$xml]);
        app(FiscalProcessingService::class)->process($batch->uuid);
        $this->assertSame('completed', $batch->fresh()->status->value);
        $output = $batch->files()->where('extension', 'pdf')->firstOrFail();
        $pdf = Storage::disk('processing')->get($output->storage_path);
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $text = '';
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);
            $text .= $decoded === false ? $stream : $decoded;
        }
        $this->assertStringContainsString('Status atual na SEFAZ', $text);
    }
}
