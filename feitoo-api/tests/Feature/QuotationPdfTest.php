<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Quotations\NormalizedQuotationLogo;
use App\Domain\Quotations\Quotation;
use App\Services\Quotations\QuotationPdfException;
use App\Services\Quotations\QuotationPdfRenderer;
use App\Support\Quotations\QuotationDemoFixtures;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuotationPdfTest extends TestCase
{
    private string $testStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testStorage = base_path('storage/framework/testing/quotation-pdf-'.bin2hex(random_bytes(8)));
        $this->app->useStoragePath($this->testStorage);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->testStorage);
        parent::tearDown();
    }

    public static function scenarios(): iterable
    {
        foreach (array_keys(QuotationDemoFixtures::scenarios()) as $name) {
            foreach (['classic', 'modern'] as $template) {
                yield $name.'-'.$template => [$name, $template];
            }
        }
    }

    #[DataProvider('scenarios')]
    public function test_real_renderer_generates_complete_private_pdfs(string $name, string $template): void
    {
        DB::shouldReceive('connection')->never();
        $scenario = QuotationDemoFixtures::scenarios()[$name];
        $data = array_replace($scenario['data'], ['template_id' => $template]);
        $logo = $scenario['logo'] ? NormalizedQuotationLogo::fromBytes(QuotationDemoFixtures::logo($scenario['logo'])) : null;
        $before = glob(storage_path('app/private/quotation-temporary/*')) ?: [];
        $pdf = app(QuotationPdfRenderer::class)->render(Quotation::fromArray($data), $logo);
        $this->assertStringStartsWith('%PDF-1.', $pdf->bytes);
        $this->assertMatchesRegularExpression('/startxref\s+\d+\s+%%EOF\s*$/', substr($pdf->bytes, -2048));
        $this->assertSame(hash('sha256', $pdf->bytes), $pdf->sha256);
        $this->assertLessThanOrEqual(10485760, strlen($pdf->bytes));
        $this->assertGreaterThanOrEqual(1, $pdf->pages);
        if (in_array($name, ['hundred-items', 'long-text'], true)) {
            $this->assertGreaterThan(1, $pdf->pages);
        }
        $this->assertStringNotContainsString('/JavaScript', $pdf->bytes);
        $this->assertStringNotContainsString('/OpenAction', $pdf->bytes);
        if ($logo !== null) {
            $this->assertStringContainsString('/Subtype /Image', $pdf->bytes);
        }
        $this->assertSame($before, glob(storage_path('app/private/quotation-temporary/*')) ?: []);
    }

    public function test_page_limit_failure_also_cleans_workspace(): void
    {
        $data = QuotationDemoFixtures::simple();
        $data['items'] = array_fill(0, 100, ['description' => str_repeat('descrição longa ', 60), 'quantity' => '1', 'unit_price_cents' => 100]);
        $before = glob(storage_path('app/private/quotation-temporary/*')) ?: [];
        try {
            app(QuotationPdfRenderer::class)->render(Quotation::fromArray($data));
            $this->fail('Documento acima do limite de páginas não pode ser truncado ou aceito.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Não foi possível gerar o PDF do orçamento com segurança.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame($before, glob(storage_path('app/private/quotation-temporary/*')) ?: []);
    }

    public function test_demo_refuses_production_and_unknown_scenarios(): void
    {
        $this->artisan('feitoo:quotation-pdf-demo', ['--scenario' => ['../../data']])->assertFailed();
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('feitoo:quotation-pdf-demo')->assertFailed();
    }

    public function test_demo_generates_only_private_fixtures_without_querying_database(): void
    {
        DB::shouldReceive('connection')->never();
        $this->artisan('feitoo:quotation-pdf-demo', ['--scenario' => ['simple']])->assertSuccessful();
        $runs = glob(storage_path('app/private/quotation-validation/runs/*')) ?: [];
        $this->assertCount(1, $runs);
        $this->assertCount(2, glob($runs[0].'/*.pdf'));
        $manifest = json_decode(file_get_contents($runs[0].'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($manifest['synthetic_only']);
        $this->assertSame(['classic', 'modern'], array_column($manifest['files'], 'template'));
    }

    public function test_missing_font_glyph_is_rejected_instead_of_silently_disappearing(): void
    {
        $data = QuotationDemoFixtures::simple();
        $data['notes'] = "Símbolo não suportado: \u{1F9D1}";
        try {
            app(QuotationPdfRenderer::class)->render(Quotation::fromArray($data));
            $this->fail('Um glifo ausente precisa produzir erro explícito.');
        } catch (QuotationPdfException $exception) {
            $this->assertSame('unsupported_character', $exception->reason);
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame([], glob(storage_path('app/private/quotation-temporary/*')) ?: []);
    }
}
