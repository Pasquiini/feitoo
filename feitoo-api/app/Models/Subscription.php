<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['contract_amount' => 'integer', 'status' => SubscriptionStatus::class, 'current_period_start' => 'datetime',
            'current_period_end' => 'datetime', 'canceled_at' => 'datetime',
            'checkout_expires_at' => 'datetime', 'cancel_at_period_end' => 'boolean'];
    }

    public function grantsPro(): bool
    {
        return $this->status === SubscriptionStatus::Active && $this->current_period_end?->isFuture();
    }
}
