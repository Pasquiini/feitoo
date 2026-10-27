<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationGeneration extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['snapshot_path', 'snapshot_checksum', 'logo_path', 'logo_checksum', 'attempt_token'];

    protected function casts(): array
    {
        return ['attempt' => 'integer', 'draft_revision' => 'integer', 'lease_expires_at' => 'immutable_datetime',
            'pending_expires_at' => 'immutable_datetime', 'purged_at' => 'immutable_datetime'];
    }
}
