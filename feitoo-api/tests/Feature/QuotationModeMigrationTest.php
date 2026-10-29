<?php

namespace Tests\Feature;

use App\Models\QuotationCreditOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuotationModeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_migration_classifies_existing_sessions_and_preserves_financial_rows(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $user = User::factory()->create();
        foreach (['test', 'live'] as $mode) {
            $order = QuotationCreditOrder::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id,
                'idempotency_key' => (string) Str::uuid(), 'package_code' => 'quotation_5', 'amount' => 990, 'currency' => 'brl',
                'credits' => 5, 'price_id' => 'price_fixture', 'session_id' => 'cs_'.$mode.'_fixture', 'expires_at' => now()->addHour()]);
            DB::table('quotation_credit_grants')->insert(['user_id' => $user->id, 'order_id' => $order->id, 'granted' => 5,
                'reserved' => 1, 'consumed' => 2, 'revoked' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $before = DB::table('quotation_credit_grants')->orderBy('id')->get()->toArray();
        $migration = require database_path('migrations/2026_10_28_000001_add_stripe_mode_to_quotation_credit_orders.php');
        $migration->down(); // Reconstruct the prior schema only in the isolated in-memory database.
        $migration->up();
        $this->assertDatabaseHas('quotation_credit_orders', ['session_id' => 'cs_live_fixture', 'stripe_mode' => 'live']);
        $this->assertDatabaseHas('quotation_credit_orders', ['session_id' => 'cs_test_fixture', 'stripe_mode' => 'test']);
        $this->assertEquals($before, DB::table('quotation_credit_grants')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('quotation_credit_orders', 2);
    }
}
