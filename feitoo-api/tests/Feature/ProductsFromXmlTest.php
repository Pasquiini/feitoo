<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fiscal\Nfe\NfeParser;
use App\Domain\Tools\ProductsFromXml\ProductConsolidationService;
use App\Domain\Tools\ProductsFromXml\ProductItem;
use App\Domain\Tools\ProductsFromXml\ProductsExcelGenerator;
use App\Enums\ProcessingTool;
use App\Exceptions\BillingException;
use App\Jobs\ProcessFiscalFilesJob;
use App\Models\Processing;
use App\Models\Purchase;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\PrivateProcessingFileReader;
use App\Services\StripeCheckoutGateway;
use App\Services\XmlToExcelResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ProductsFromXmlTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('processing');
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner, 'web');
        config(['feitoo.rate_limits.create_per_minute' => 1000, 'feitoo.rate_limits.upload_per_minute' => 1000,
            'feitoo.rate_limits.access_per_minute' => 1000, 'feitoo.processing.queue_connection' => 'database']);
    }

    private function fixture(int $number = 1): string
    {
        $xml = file_get_contents(base_path('tests/Fixtures/Fiscal/nfe-proc.xml'));
        $key = '35261000000000000100550010000001231000001234';

        return str_replace([$key, '<nNF>123</nNF>'], [substr($key, 0, 35).str_pad((string) $number, 9, '0', STR_PAD_LEFT), '<nNF>'.$number.'</nNF>'], $xml);
    }

    private function batch(array $xmls): Processing
    {
        $id = $this->postJson('/api/processings', ['tool' => ProcessingTool::ProductsFromXml->value])->assertCreated()->json('data.id');
        foreach (array_chunk($xmls, 20) as $chunk) {
            $files = array_map(fn ($xml) => UploadedFile::fake()->createWithContent('synthetic.xml', $xml), $chunk);
            $this->postJson('/api/processings/'.$id.'/files', ['files' => $files])->assertOk();
        }

        return Processing::where('uuid', $id)->firstOrFail();
    }

    private function process(Processing $batch): void
    {
        $this->postJson('/api/processings/'.$batch->uuid.'/start')->assertStatus(202);
        app()->call([(new ProcessFiscalFilesJob($batch->uuid)), 'handle']);
        $batch->refresh();
    }

    public function test_one_and_multiple_invoices_use_existing_pipeline_and_persist_bounded_preview(): void
    {
        $batch = $this->batch([$this->fixture()]);
        $this->process($batch);
        $this->getJson('/api/processings/'.$batch->uuid)->assertOk()
            ->assertJsonPath('data.tool', 'products-from-xml')->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.preview.summary.items_count', 2)->assertJsonPath('data.preview.summary.products_count', 2)
            ->assertJsonPath('data.preview.summary.total_value', '25.00')->assertJsonCount(2, 'data.preview.products_preview');
        $this->assertSame('free', $batch->entitlement_source);
        $many = $this->batch(array_map(fn ($n) => $this->fixture($n), range(1, 10)));
        $this->process($many);
        $this->getJson('/api/processings/'.$many->uuid)->assertJsonPath('data.preview.summary.items_count', 20)
            ->assertJsonPath('data.preview.summary.products_count', 2)->assertJsonPath('data.preview.products_preview.0.invoice_count', 10)
            ->assertJsonCount(10, 'data.preview.items_preview');
        $this->getJson('/api/processings')->assertJsonPath('data.0.tool', 'products-from-xml')
            ->assertJsonPath('data.0.products_summary.items_count', 20)->assertJsonPath('data.0.total_value', '250.00');
    }

    public function test_xlsx_matches_persisted_items_and_is_generated_without_reading_xml_again(): void
    {
        $batch = $this->batch([$this->fixture()]);
        $this->process($batch);
        $output = $batch->output()->firstOrFail();
        $book = IOFactory::load(Storage::disk('processing')->path($output->storage_path));
        try {
            $this->assertSame(['Produtos', 'Itens'], $book->getSheetNames());
            $detail = $book->getSheetByName('Itens');
            $source = (new NfeParser)->parse($this->fixture());
            foreach ($source->items as $i => $item) {
                $row = $i + 2;
                $this->assertSame($item->productCode, $detail->getCell('F'.$row)->getValue());
                $this->assertSame($item->description, $detail->getCell('G'.$row)->getValue());
                $this->assertSame($item->ncm, $detail->getCell('H'.$row)->getValue());
                $this->assertSame($item->cfop, $detail->getCell('I'.$row)->getValue());
                $this->assertEquals($item->commercialQuantity, $detail->getCell('K'.$row)->getValue());
                $this->assertEquals($item->commercialUnitPrice, $detail->getCell('L'.$row)->getValue());
                $this->assertEquals($item->productTotal, $detail->getCell('M'.$row)->getValue());
            }
            $this->assertSame(DataType::TYPE_STRING, $detail->getCell('E2')->getDataType());
            $this->assertSame(DataType::TYPE_NUMERIC, $detail->getCell('C2')->getDataType());
        } finally {
            $book->disconnectWorksheets();
        }
        $output->delete();
        $this->mock(PrivateProcessingFileReader::class)->shouldNotReceive('read');
        app(XmlToExcelResultService::class)->generate($batch);
        $this->getJson('/api/processings/'.$batch->uuid)->assertJsonPath('data.result.available', true);
    }

    public function test_formula_injection_and_large_decimals_remain_explicit_text(): void
    {
        $item = new ProductItem('key', '001', '01', '2026-10-01', '=HYPERLINK("bad")', '00000000000100',
            '001234', '@SUM(1)', '09012100', '5102', 'UN', '0.25', '1.00', '9007199254740993.29');
        $path = tempnam(sys_get_temp_dir(), 'products-test-');
        try {
            (new ProductsExcelGenerator)->generate((new ProductConsolidationService)->consolidate([$item]), [$item], $path);
            $book = IOFactory::load($path);
            try {
                $this->assertSame(DataType::TYPE_STRING, $book->getSheet(0)->getCell('B2')->getDataType());
                $this->assertSame('001234', $book->getSheet(0)->getCell('A2')->getValue());
                $this->assertSame('9007199254740993.29', $book->getSheet(0)->getCell('J2')->getValue());
                $this->assertSame(DataType::TYPE_STRING, $book->getSheet(1)->getCell('D2')->getDataType());
                $this->assertSame(DataType::TYPE_NUMERIC, $book->getSheet(1)->getCell('K2')->getDataType());
            } finally {
                $book->disconnectWorksheets();
            }
        } finally {
            unlink($path);
        }
    }

    public function test_partial_failure_and_no_valid_or_unsupported_documents_do_not_generate_empty_excel(): void
    {
        $batch = $this->batch([$this->fixture(), '<bad/>']);
        $this->process($batch);
        $this->getJson('/api/processings/'.$batch->uuid)->assertJsonPath('data.successful_files_count', 1)
            ->assertJsonPath('data.failed_files_count', 1)->assertJsonPath('data.preview.summary.items_count', 2);
        $invalid = $this->batch(['<bad/>', str_replace('<mod>55</mod>', '<mod>65</mod>', $this->fixture())]);
        $this->process($invalid);
        $this->getJson('/api/processings/'.$invalid->uuid)->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.preview', null)->assertJsonPath('data.result.available', false)
            ->assertJsonPath('data.result.error_message', 'Não encontramos NF-es válidas nesses arquivos.');
        $this->assertSame(0, $invalid->output()->count());
    }

    public function test_missing_code_and_unit_price_use_safe_fallback_in_new_tool_only(): void
    {
        $xml = preg_replace('~<cProd>.*?</cProd>|<vUnCom>.*?</vUnCom>~s', '', $this->fixture());
        $batch = $this->batch([$xml]);
        $this->process($batch);
        $this->getJson('/api/processings/'.$batch->uuid)->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.preview.products_preview.0.code', null)
            ->assertJsonPath('data.preview.products_preview.0.minimum_unit_value', null);
    }

    public function test_usage_is_per_tool_free_limits_and_paid_purchase_are_reused(): void
    {
        Processing::create(['user_id' => $this->owner->id, 'tool' => 'xml-to-excel', 'status' => 'completed',
            'entitlement_source' => 'free', 'usage_date' => now('America/Sao_Paulo')->toDateString(), 'usage_files_count' => 50]);
        $free = $this->batch([$this->fixture()]);
        $this->process($free);
        $this->getJson('/api/account/usage')->assertJsonPath('data.limits.remaining_today', 0);
        $this->getJson('/api/account/usage?tool=products-from-xml')->assertJsonPath('data.limits.remaining_today', 49);
        $this->getJson('/api/account/usage?tool=unknown')->assertUnprocessable();
        $large = $this->batch(array_fill(0, 21, $this->fixture()));
        $this->postJson('/api/processings/'.$large->uuid.'/start')->assertStatus(422);
        $purchase = Purchase::create(['user_id' => $this->owner->id, 'processing_id' => $large->id,
            'package_code' => 'xml_100', 'files_limit' => 100, 'files_count' => 21, 'amount' => 490,
            'currency' => 'brl', 'status' => 'paid', 'expires_at' => now()->addHour()]);
        $this->process($large);
        $this->assertSame('purchase', $large->entitlement_source);
        $this->assertNotNull($purchase->fresh()->consumed_at);
        $this->getJson('/api/account/purchases')->assertJsonPath('data.0.tool', 'products-from-xml');
        $parameters = app(StripeCheckoutGateway::class)->parameters($purchase, $large);
        $this->assertStringContainsString('/produtos-dos-xmls?', $parameters['success_url']);
        $this->assertStringContainsString('/produtos-dos-xmls?', $parameters['cancel_url']);
        $this->assertSame(490, $parameters['line_items'][0]['price_data']['unit_amount']);
        $free->update(['usage_files_count' => 50]);
        $exhausted = $this->batch([$this->fixture()]);
        $this->postJson('/api/processings/'.$exhausted->uuid.'/start')->assertStatus(422);
    }

    public function test_pro_ownership_download_expiration_and_deletion_reuse_existing_guards(): void
    {
        Subscription::create(['user_id' => $this->owner->id, 'provider_customer_id' => 'cus_test_products',
            'provider_price_id' => 'price_products', 'status' => 'active', 'current_period_end' => now()->addMonth()]);
        $batch = $this->batch([$this->fixture()]);
        $this->assertGreaterThan(1400, now()->diffInMinutes($batch->expires_at));
        $this->process($batch);
        $this->assertSame('pro', $batch->entitlement_source);
        $this->getJson('/api/account/usage?tool=products-from-xml')->assertJsonPath('data.limits.daily', 500);
        $this->get('/api/processings/'.$batch->uuid.'/result')->assertOk();
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/processings/'.$batch->uuid)->assertNotFound();
        $this->getJson('/api/processings/'.$batch->uuid.'/result')->assertNotFound();
        $this->deleteJson('/api/processings/'.$batch->uuid.'/files')->assertNotFound();
        Auth::forgetGuards();
        $this->actingAs($this->owner, 'web');
        $batch->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/api/processings/'.$batch->uuid.'/result')->assertStatus(410);
        $this->deleteJson('/api/processings/'.$batch->uuid.'/files')->assertOk()->assertJsonPath('data.preview', null);
        $this->assertDatabaseCount('processing_file_results', 0);
        $this->assertArrayNotHasKey('products_preview', $batch->fresh()->metadata);
        $this->getJson('/api/processings')->assertJsonPath('data.0.products_summary', null);
    }

    public function test_capacity_pro_per_processing_and_daily_usage_have_the_existing_limits(): void
    {
        Subscription::create(['user_id' => $this->owner->id, 'provider_customer_id' => 'cus_capacity',
            'provider_price_id' => 'price_capacity', 'status' => 'active', 'current_period_end' => now()->addMonth()]);
        $batch = Processing::create(['user_id' => $this->owner->id, 'tool' => 'products-from-xml',
            'status' => 'pending', 'files_count' => 250, 'expires_at' => now()->addDay()]);
        $entitlements = app(EntitlementService::class);
        $entitlements->accept($batch, $this->owner);
        $this->assertSame('pro', $batch->fresh()->entitlement_source);
        $this->getJson('/api/account/usage?tool=products-from-xml')->assertJsonPath('data.limits.remaining_today', 250);
        $over = Processing::create(['user_id' => $this->owner->id, 'tool' => 'products-from-xml',
            'status' => 'pending', 'files_count' => 251, 'expires_at' => now()->addDay()]);
        try {
            $entitlements->accept($over, $this->owner);
            $this->fail('An oversized Pro processing must be rejected.');
        } catch (BillingException) {
            $this->assertNull($over->fresh()->entitlement_source);
        }
        $second = Processing::create(['user_id' => $this->owner->id, 'tool' => 'products-from-xml',
            'status' => 'pending', 'files_count' => 250, 'expires_at' => now()->addDay()]);
        $entitlements->accept($second, $this->owner);
        $this->getJson('/api/account/usage?tool=products-from-xml')->assertJsonPath('data.limits.remaining_today', 0);
        $over->update(['files_count' => 1]);
        try {
            $entitlements->accept($over, $this->owner);
            $this->fail('Pro daily allowance must be enforced.');
        } catch (BillingException) {
            $this->assertNull($over->fresh()->entitlement_source);
        }
        $this->owner->processings()->update(['usage_files_count' => 0]);
        config(['feitoo.uploads.max_processing_items' => 1]);
        $small = $this->batch([$this->fixture()]);
        $this->process($small);
        $this->getJson('/api/processings/'.$small->uuid)->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.result.available', false);
    }

    public function test_public_config_and_benchmark_keep_the_same_technical_capacity(): void
    {
        $this->getJson('/api/tools/products-from-xml/config')->assertOk()
            ->assertJsonPath('data.max_processing_files', 250)->assertJsonPath('data.max_processing_items', 4000);
        $this->getJson('/api/tools/unknown/config')->assertNotFound();
        $this->artisan('feitoo:benchmark-products-from-xml', ['--documents' => 1, '--items' => 2])->assertSuccessful();
        $this->artisan('feitoo:benchmark-products-from-xml', ['--documents' => 250, '--items' => 17])->assertFailed();
        $this->app['env'] = 'production';
        $this->artisan('feitoo:benchmark-products-from-xml', ['--documents' => 1, '--items' => 1])->assertFailed();
    }

    public function test_google_authentication_accepts_only_the_new_local_return_path(): void
    {
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
        $this->postJson('/api/auth/google/start', ['intent' => 'login', 'return_to' => '/produtos-dos-xmls'])->assertOk();
        $this->postJson('/api/auth/google/start', ['intent' => 'login', 'return_to' => 'https://external.test'])->assertUnprocessable();
    }
}
