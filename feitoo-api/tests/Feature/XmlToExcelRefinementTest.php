<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class XmlToExcelRefinementTest extends TestCase
{
    public function test_public_configuration_exposes_only_actual_technical_limits(): void
    {
        config(['feitoo.uploads.max_files' => 17, 'feitoo.uploads.max_file_size_kb' => 2048,
            'feitoo.processing.ttl_minutes' => 45]);
        $this->getJson('/api/tools/xml-to-excel/config')->assertOk()->assertJsonPath('data.max_files', 17)
            ->assertJsonPath('data.max_file_size_kb', 2048)->assertJsonPath('data.ttl_minutes', 45)
            ->assertJsonMissingPath('data.stripe')->assertJsonMissingPath('data.secret_key');
    }

    public function test_synthetic_benchmark_runs_without_database_or_external_worker(): void
    {
        $this->assertSame(0, Artisan::call('feitoo:benchmark-xml-to-excel', ['--documents' => 2, '--items' => 3]));
        $metrics = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $metrics['documents']);
        $this->assertSame(6, $metrics['items']);
        $this->assertGreaterThan(0, $metrics['file_size_bytes']);
        $this->assertGreaterThan(0, $metrics['peak_memory_mb']);
        $this->assertArrayNotHasKey('synthetic_output', $metrics);
    }

    public function test_benchmark_keeps_xml_guards_and_does_not_print_xml_when_rejected(): void
    {
        $this->assertSame(1, Artisan::call('feitoo:benchmark-xml-to-excel', ['--documents' => 1, '--items' => 990]));
        $output = Artisan::output();
        $this->assertStringContainsString('"failed":true', $output);
        $this->assertStringNotContainsString('<NFe', $output);
        $this->assertStringNotContainsString('Indústria', $output);
    }

    public function test_benchmark_rejects_invalid_parameters(): void
    {
        $this->artisan('feitoo:benchmark-xml-to-excel', ['--documents' => 501])->assertFailed();
        $this->artisan('feitoo:benchmark-xml-to-excel', ['--items' => 0])->assertFailed();
    }

    public function test_benchmark_is_unavailable_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('feitoo:benchmark-xml-to-excel')->assertFailed();
    }
}
