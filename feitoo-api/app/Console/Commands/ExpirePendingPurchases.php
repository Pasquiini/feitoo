<?php

namespace App\Console\Commands;

use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use Illuminate\Console\Command;

class ExpirePendingPurchases extends Command
{
    protected $signature = 'feitoo:expire-pending-purchases';

    protected $description = 'Expire abandoned Checkout purchases without deleting financial history';

    public function handle(): int
    {
        $count = Purchase::query()->whereIn('status', [PurchaseStatus::Pending->value, PurchaseStatus::Failed->value])
            ->where(fn ($query) => $query->whereNull('checkout_closed_at')->orWhereNull('provider_payment_intent_id')
                ->orWhere('status', PurchaseStatus::Failed->value))
            ->where('expires_at', '<=', now())->update(['status' => PurchaseStatus::Expired->value, 'checkout_url' => null]);
        $this->info('Expired purchases: '.$count);

        return self::SUCCESS;
    }
}
