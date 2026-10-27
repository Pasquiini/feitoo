<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationCreditOrder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'amount' => 'integer', 'credits' => 'integer'];
    }
}
