<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationDraft extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payload', 'logo_path', 'import_hash', 'import_key', 'user_id'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'immutable_datetime',
            'revision' => 'integer', 'schema_version' => 'integer'];
    }
}
