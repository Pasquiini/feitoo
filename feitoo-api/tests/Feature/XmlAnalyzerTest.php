<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Processing;
use App\Models\User;
use App\Services\FiscalProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class XmlAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('processing');
        Storage::fake('local');
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner, 'web');
        config(['feitoo.processing.queue_connection' => 'database', 'feitoo.rate_limits.create_per_minute' => 1000, 'feitoo.rate_limits.upload_per_minute' => 1000, 'feitoo.rate_limits.access_per_minute' => 1000]);
    }

    private function xml(int $number = 123, string $issuer = '12345678000195', string $date = '2026-10-01T09:10:11-03:00'): string
    {
        $xml = file_get_contents(base_path('tests/Fixtures/Fiscal/nfe-proc.xml'));
        $xml = str_replace(['00000000000100', '<nNF>123</nNF>', '2026-10-01T09:10:11-03:00', '2026-10-01T10:15:00-03:00'], [$issuer, '<nNF>'.$number.'</nNF>', $date, $date], $xml);
        // Synthetic access keys are distinct. The existing parser checks the key format/envelope,
        // not authorization or cryptographic validity; no such claim is made by the analyzer.
        $old = '352610'.$issuer.'550010000001231000001234';
        $key = '352610'.$issuer.'55001'.str_pad((string) $number, 9, '0', STR_PAD_LEFT).'1000001234';

        return str_replace($old, $key, $xml);
    }

    private function batch(array $xmls): Processing
    {
        $id = $this->postJson('/api/processings', ['tool' => 'xml-analyzer'])->assertCreated()->json('data.id');
        $uploads = [];
        foreach ($xmls as $i => $xml) {
            $uploads[] = UploadedFile::fake()->createWithContent('nota-'.$i.'.xml', $xml);
        }
        $this->post('/api/processings/'.$id.'/files', ['files' => $uploads], ['Accept' => 'application/json'])->assertOk();
        $this->postJson('/api/processings/'.$id.'/start')->assertStatus(202);
        $batch = Processing::where('uuid', $id)->firstOrFail();
        app(FiscalProcessingService::class)->process($id);

        return $batch->fresh();
    }

    private function analysisView(Processing $batch, array $query = []): array
    {
        return $this->getJson('/api/processings/'.$batch->uuid.'/analysis?'.http_build_query($query))->assertOk()->json('data');
    }

    public function test_real_analysis_months_issuers_products_units_taxes_and_excel(): void
    {
        $first = $this->xml();
        $second = $this->xml(124, date: '2026-11-01T00:10:11+14:00');
        $third = str_replace('<uCom>UN</uCom>', '<uCom>KG</uCom>', $this->xml(125, '11222333000181'));
        $batch = $this->batch([$first, $second, $third]);
        $this->assertSame('completed', $batch->status->value);
        $view = $this->analysisView($batch);
        $this->assertSame(3, $view['summary']['documents_count']);
        $this->assertSame('75.00', $view['summary']['total_value']);
        $this->assertSame('25.00', $view['summary']['average_value']);
        $this->assertSame(2, $view['summary']['issuers_count']);
        $this->assertSame(4, $view['summary']['products_count']);
        $this->assertSame(6, $view['summary']['items_count']);
        $this->assertSame('2026-11', $view['monthly'][1]['month']);
        $this->assertSame(1, $view['monthly'][1]['documents_count']);
        $this->assertSame('25.00', $view['monthly'][1]['total_value']);
        $this->assertSame('13.50', $view['taxes']['ICMS']['total']);
        $this->assertSame('0.00', $view['taxes']['IPI']['total']);
        $this->assertSame('1.20', $view['taxes']['PIS']['total']);
        $this->assertSame('5.70', $view['taxes']['COFINS']['total']);
        $this->assertSame(2, $view['issuers']['data'][0]['documents_count']);
        $this->assertSame('66.67', $view['issuers']['data'][0]['percentage']);
        $this->assertStringNotContainsString('<NFe', json_encode($view));
        $this->assertSame(3, $batch->usage_files_count);
        $response = $this->get('/api/processings/'.$batch->uuid.'/result')->assertOk();
        Storage::disk('local')->put('analysis-test.xlsx', $response->streamedContent());
        $book = IOFactory::load(Storage::disk('local')->path('analysis-test.xlsx'));
        $this->assertSame(['Resumo', 'Documentos', 'Emitentes', 'Produtos', 'Tributos', 'Inconsistências'], $book->getSheetNames());
        $this->assertSame(4, $book->getSheetByName('Documentos')->getHighestRow());
        $this->assertSame(5, $book->getSheetByName('Produtos')->getHighestRow());
        $book->disconnectWorksheets();
    }

    public function test_duplicates_conflicts_and_invalid_files_have_distinct_consumption_and_inclusion(): void
    {
        $one = $this->xml();
        $conflict = $this->xml(124);
        $different = str_replace('TESTE-001', 'ALTERADO', $conflict);
        $batch = $this->batch([$one, $one, $conflict, $different, '<broken>', '<CTe/>', str_replace('<mod>55</mod>', '<mod>65</mod>', $this->xml(126))]);
        $this->assertSame(4, $batch->successful_files_count);
        $this->assertSame(3, $batch->failed_files_count);
        $this->assertSame(4, $batch->usage_files_count);
        $view = $this->analysisView($batch);
        $this->assertSame(1, $view['summary']['documents_count']);
        $this->assertSame('25.00', $view['summary']['total_value']);
        $this->assertSame(1, $view['audit']['duplicates_ignored']);
        $this->assertSame(2, $view['audit']['conflict_files']);
        $this->assertSame(1, $view['audit']['conflicting_keys']);
        $this->assertSame(3, $view['audit']['invalid_files']);
        $this->assertSame(7, $view['documents']['total']);
        $statuses = array_column($view['documents']['data'], 'analysis_status');
        $this->assertSame(2, count(array_filter($statuses, fn ($s) => $s === 'conflict')));
        $this->assertSame(1, count(array_filter($statuses, fn ($s) => $s === 'duplicate')));
        $this->assertSame(6, count(Storage::disk('processing')->files('processings/'.$batch->uuid.'/input')));
        app(FiscalProcessingService::class)->process($batch->uuid);
        $this->assertSame(4, $batch->fresh()->usage_files_count);
        $this->assertSame(1, $batch->output()->count());
        $this->postJson('/api/processings/'.$batch->uuid.'/start')->assertConflict();
        $history = $this->getJson('/api/processings')->assertOk();
        $this->assertSame('25.00', $history->json('data.0.total_value'));
    }

    public function test_filters_pagination_and_filtered_export_use_persisted_data(): void
    {
        $batch = $this->batch([$this->xml(), $this->xml(124, date: '2026-11-03T10:00:00-03:00'), $this->xml(125, '11222333000181')]);
        $view = $this->analysisView($batch, ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame(1, $view['summary']['documents_count']);
        $this->assertSame('25.00', $view['summary']['total_value']);
        $this->assertSame(1, $this->analysisView($batch, ['to' => '2026-10-01', 'document_query' => '000000123'])['summary']['documents_count']);
        $issuer = $view['issuer_options'][0]['id'];
        $this->assertSame(2, $this->analysisView($batch, ['issuer' => $issuer])['summary']['documents_count']);
        $product = $this->analysisView($batch, ['product_query' => 'TESTE-001']);
        $this->assertSame(3, $product['summary']['documents_count']);
        $this->assertSame(2, $product['products']['total']);
        $paged = $this->analysisView($batch, ['per_page' => 1, 'document_page' => 2, 'document_sort' => 'number', 'direction' => 'asc']);
        $this->assertSame(3, $paged['documents']['last_page']);
        $this->assertSame('124', $paged['documents']['data'][0]['number']);
        $this->assertSame(1, count($paged['products']['data']));
        // Removing originals after a completed job does not force the dashboard to reparse them.
        Storage::disk('processing')->deleteDirectory('processings/'.$batch->uuid.'/input');
        $this->assertSame(3, $this->analysisView($batch)['summary']['documents_count']);
        $response = $this->get('/api/processings/'.$batch->uuid.'/analysis/export?from=2026-11-01&product_query=TESTE-001')->assertOk();
        Storage::disk('local')->put('filtered.xlsx', $response->streamedContent());
        $book = IOFactory::load(Storage::disk('local')->path('filtered.xlsx'));
        $this->assertSame(2, $book->getSheetByName('Documentos')->getHighestRow());
        $this->assertSame('124', $book->getSheetByName('Documentos')->getCell('B2')->getValue());
        $this->assertSame(2, $book->getSheetByName('Produtos')->getHighestRow());
        $book->disconnectWorksheets();
        $this->getJson('/api/processings/'.$batch->uuid.'/analysis?from=2026-11-10&to=2026-10-01')->assertStatus(422);
        $this->getJson('/api/processings/'.$batch->uuid.'/analysis?per_page=1000')->assertStatus(422);
    }

    public function test_absent_totals_taxes_invalid_dates_missing_codes_and_decimal_precision(): void
    {
        $one = str_replace(['<vNF>25.00</vNF>', '<qCom>2.0000</qCom>'], ['<vNF>9007199254740993.01</vNF>', '<qCom>0.0001</qCom>'], $this->xml());
        $two = preg_replace('/<cProd>.*?<\/cProd>/', '', $this->xml(124));
        $two = preg_replace('/<vNF>.*?<\/vNF>|<vICMS>.*?<\/vICMS>/', '', $two);
        $invalid = str_replace('2026-10-01T09:10:11-03:00', '2026-02-31T09:10:11-03:00', $this->xml(125));
        $batch = $this->batch([$one, $two, $invalid]);
        $view = $this->analysisView($batch);
        $this->assertSame(2, $view['summary']['documents_count']);
        $this->assertSame('9007199254740993.01', $view['summary']['total_value']);
        $this->assertSame('9007199254740993.01', $view['summary']['average_value']);
        $this->assertSame(1, $view['summary']['documents_without_total']);
        $this->assertSame('4.50', $view['taxes']['ICMS']['total']);
        $this->assertSame(1, $view['taxes']['ICMS']['documents_without_field']);
        $this->assertSame(1, $view['audit']['invalid_files']);
        $this->assertSame(4, $view['products']['total']);
        $this->assertContains('0.0001', array_column($view['products']['data'], 'total_quantity'));
    }

    public function test_formula_security_authorization_expiry_and_cleanup(): void
    {
        $xml = str_replace('Indústria Fictícia São João', '=HYPERLINK("https://example.test")', $this->xml());
        $batch = $this->batch([$xml, file_get_contents(base_path('tests/Fixtures/Fiscal/hostile-xxe.xml'))]);
        $this->assertSame(1, $batch->usage_files_count);
        $response = $this->get('/api/processings/'.$batch->uuid.'/result')->assertOk();
        Storage::disk('local')->put('security.xlsx', $response->streamedContent());
        $book = IOFactory::load(Storage::disk('local')->path('security.xlsx'));
        $this->assertSame('s', $book->getSheetByName('Emitentes')->getCell('A2')->getDataType());
        $this->assertStringStartsWith('=HYPERLINK', $book->getSheetByName('Emitentes')->getCell('A2')->getValue());
        $book->disconnectWorksheets();
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['/analysis', '/analysis/export', '/result', '/files'] as $suffix) {
            $this->getJson('/api/processings/'.$batch->uuid.$suffix)->assertNotFound();
        }
        Auth::forgetGuards();
        $this->actingAs($this->owner, 'web');
        $batch->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/api/processings/'.$batch->uuid.'/analysis')->assertStatus(410);
        $this->getJson('/api/processings/'.$batch->uuid.'/analysis/export')->assertStatus(410);
        Storage::disk('local')->put('analyzer-temporary/'.$batch->uuid.'/orphan.xlsx', 'private');
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        $this->assertSame([], Storage::disk('processing')->allFiles('processings/'.$batch->uuid));
        $this->assertSame([], Storage::disk('local')->allFiles('analyzer-temporary/'.$batch->uuid));
        $this->assertArrayNotHasKey('analyzer_summary', $batch->fresh()->metadata);
    }

    public function test_cents_fractional_quantities_missing_fields_and_untrusted_issuer_groups(): void
    {
        $first = str_replace(['<vNF>25.00</vNF>', '<qCom>2.0000</qCom>'], ['<vNF>0.10</vNF>', '<qCom>0.0001</qCom>'], $this->xml());
        $second = str_replace(['<vNF>25.00</vNF>', '<qCom>2.0000</qCom>'], ['<vNF>0.11</vNF>', '<qCom>0.0002</qCom>'], $this->xml(124));
        $batch = $this->batch([$first, $second]);
        $view = $this->analysisView($batch);
        $this->assertSame('0.21', $view['summary']['total_value']);
        $this->assertSame('0.11', $view['summary']['average_value']);
        $this->assertContains('0.0003', array_column($view['products']['data'], 'total_quantity'));
        $missing = preg_replace('/<total>.*?<\/total>/s', '', $this->xml());
        $missing = str_replace('12345678000195', '00000000000100', $missing);
        $other = str_replace('12345678000195', '00000000000100', preg_replace('/<total>.*?<\/total>/s', '', $this->xml(124)));
        $batch = $this->batch([$missing, $other]);
        $view = $this->analysisView($batch);
        $this->assertNull($view['summary']['total_value']);
        $this->assertNull($view['summary']['average_value']);
        $this->assertSame(0, $view['summary']['issuers_count']);
        $this->assertSame(2, $view['summary']['untrusted_issuer_groups']);
        $this->assertSame(4, $view['summary']['products_count']);
        $this->assertNull($view['taxes']['ICMS']['total']);
        $this->assertSame(2, $view['taxes']['ICMS']['documents_without_field']);
    }

    public function test_inconsistent_product_totals_are_reported_without_changing_values_and_conflicts_are_order_independent(): void
    {
        $base = $this->xml();
        $inconsistent = str_replace('<vProd>25.00</vProd>', '<vProd>99.00</vProd>', $base);
        $batch = $this->batch([$inconsistent]);
        $view = $this->analysisView($batch);
        $this->assertSame('25.00', $view['summary']['total_value']);
        $this->assertStringContainsString('vProd da nota diverge', implode(' ', array_column($view['inconsistencies']['data'], 'message')));
        $forward = $this->batch([$base, $inconsistent]);
        $reverse = $this->batch([$inconsistent, $base]);
        foreach ([$forward, $reverse] as $b) {
            $view = $this->analysisView($b);
            $this->assertSame(0, $view['summary']['documents_count']);
            $this->assertNull($view['summary']['total_value']);
            $this->assertSame(2, $view['audit']['conflict_files']);
            $this->assertSame(2, $b->usage_files_count);
        }
    }

    public function test_limits_capacity_cache_and_failed_retry_are_bounded_and_idempotent(): void
    {
        $largeText = str_replace('Produto fictício 1: café e maçã', str_repeat('X', 2050), $this->xml(124));
        $batch = $this->batch([$this->xml(), $largeText]);
        $this->assertSame(1, $batch->successful_files_count);
        $this->assertSame(1, $batch->usage_files_count);
        $this->assertSame('analysis_capacity', $batch->files()->where('kind', 'input')->orderByDesc('id')->first()->error_code->value);
        for ($i = 0; $i < 7; $i++) { $this->analysisView($batch, ['product_query' => 'query-'.$i]); }
        $files = Storage::disk('processing')->files('processings/'.$batch->uuid.'/analysis/views');
        $this->assertCount(4, array_filter($files, fn ($p) => str_ends_with($p, '.json')));
        config(['monetization.free.per_processing' => 1]);
        $id = $this->postJson('/api/processings', ['tool' => 'xml-analyzer'])->json('data.id');
        $uploads = [UploadedFile::fake()->createWithContent('a.xml', $this->xml()), UploadedFile::fake()->createWithContent('b.xml', $this->xml(124))];
        $this->post('/api/processings/'.$id.'/files', ['files' => $uploads], ['Accept' => 'application/json'])->assertOk();
        $this->postJson('/api/processings/'.$id.'/start')->assertStatus(422);
        $this->assertSame(0, Processing::where('uuid', $id)->first()->usage_files_count);
        config(['monetization.free.per_processing' => 20]);
        $this->postJson('/api/processings/'.$id.'/start')->assertStatus(202);
        $excel = \Mockery::mock(\App\Services\AnalyzerExcelGenerator::class);
        $excel->shouldReceive('generate')->once()->andThrow(new \RuntimeException('Workbook failure'));
        $this->app->instance(\App\Services\AnalyzerExcelGenerator::class, $excel);
        $this->app->forgetInstance(\App\Services\AnalyzerResultService::class);
        try { app(FiscalProcessingService::class)->process($id); $this->fail('Expected workbook failure'); }
        catch (\RuntimeException $e) { $this->assertSame('Workbook failure', $e->getMessage()); }
        $this->getJson('/api/processings/'.$id.'/analysis')->assertConflict();
        $this->assertSame([], Storage::disk('local')->allFiles('analyzer-temporary/'.$id));
        $this->app->instance(\App\Services\AnalyzerExcelGenerator::class, new \App\Services\AnalyzerExcelGenerator);
        $this->app->forgetInstance(\App\Services\AnalyzerResultService::class);
        app(FiscalProcessingService::class)->process($id);
        $this->assertSame('completed', Processing::where('uuid', $id)->first()->status->value);
        $this->assertSame(2, Processing::where('uuid', $id)->first()->usage_files_count);
        $this->assertSame(1, Processing::where('uuid', $id)->first()->output()->count());
    }

    public function test_pro_is_reused_and_input_limits_authentication_and_pending_deletion_are_preserved(): void
    {
        \App\Models\Subscription::create(['user_id' => $this->owner->id, 'provider_customer_id' => 'cus_analysis', 'provider_price_id' => 'price_pro', 'provider_subscription_id' => 'sub_analysis', 'status' => 'active', 'current_period_end' => now()->addMonth()]);
        config(['monetization.free.per_processing' => 1, 'monetization.pro.per_processing' => 2, 'monetization.pro.daily' => 2]);
        $batch = $this->batch([$this->xml(), '<broken>']);
        $this->assertSame('pro', $batch->entitlement_source);
        $this->assertSame(1, $batch->usage_files_count);
        $this->getJson('/api/account/usage?tool=xml-analyzer')->assertJsonPath('data.limits.remaining_today', 1);
        $id = $this->postJson('/api/processings', ['tool' => 'xml-analyzer'])->json('data.id');
        config(['feitoo.uploads.max_file_size_kb' => 1]);
        $this->post('/api/processings/'.$id.'/files', ['files' => [UploadedFile::fake()->createWithContent('large.xml', $this->xml())]], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/processings/'.$id.'/files', ['files' => [UploadedFile::fake()->createWithContent('file.zip', 'PKfake')]], ['Accept' => 'application/json'])->assertStatus(422);
        Auth::forgetGuards(); $this->app['session']->flush();
        $this->getJson('/api/processings/'.$batch->uuid.'/analysis')->assertUnauthorized();
        $this->getJson('/api/processings/'.$batch->uuid.'/analysis/export')->assertUnauthorized();
    }

    public function test_thousands_of_items_respect_the_capacity_without_double_counting_or_charging_the_excess(): void
    {
        $xmls = [];
        for ($document = 0; $document < 5; $document++) {
            $xml = $this->xml(123 + $document);
            preg_match('/<det nItem="1">.*?<\/det>/s', $xml, $match);
            $extra = '';
            $template = preg_replace('/<imposto>.*?<\/imposto>/s', '', $match[0]);
            for ($item = 3; $item <= 990; $item++) { $extra .= str_replace('nItem="1"', 'nItem="'.$item.'"', $template); }
            $xmls[] = str_replace('<total>', $extra.'<total>', $xml);
        }
        $batch = $this->batch($xmls);
        $this->assertSame(4, $batch->successful_files_count);
        $this->assertSame(1, $batch->failed_files_count);
        $this->assertSame(4, $batch->usage_files_count);
        $view = $this->analysisView($batch);
        $this->assertSame(3960, $view['summary']['items_count']);
        $this->assertSame(4, $view['summary']['documents_count']);
        $this->assertSame(2, $view['summary']['products_count']);
        $this->assertSame('100.00', $view['summary']['total_value']);
    }
}
