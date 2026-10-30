<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Purchase extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['id', 'user_id', 'processing_id', 'provider_checkout_session_id', 'provider_payment_intent_id', 'checkout_url'];

    protected static function booted(): void
    {
        static::creating(function (Purchase $purchase): void {
            $purchase->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['status' => PurchaseStatus::class, 'files_limit' => 'integer', 'files_count' => 'integer',
            'amount' => 'integer', 'paid_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'checkout_closed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function processing(): BelongsTo
    {
        return $this->belongsTo(Processing::class);
    }

    public function awaitingPayment(): bool
    {
        return $this->status === PurchaseStatus::Pending && $this->provider_payment_intent_id && $this->checkout_closed_at;
    }
}
