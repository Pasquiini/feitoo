<?php

namespace App\Models;

use App\Enums\ProcessingFileError;
use App\Enums\ProcessingFileKind;
use App\Enums\ProcessingFileStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ProcessingFile extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => ProcessingFileStatus::Pending->value];

    protected $hidden = ['id', 'processing_id', 'storage_disk', 'storage_path', 'stored_name', 'original_name'];

    protected static function booted(): void
    {
        static::creating(function (ProcessingFile $file): void {
            $file->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => ProcessingFileKind::class,
            'status' => ProcessingFileStatus::class,
            'error_code' => ProcessingFileError::class,
            'processed_at' => 'immutable_datetime',
            'size' => 'integer',
            'expires_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Processing, $this> */
    public function processing(): BelongsTo
    {
        return $this->belongsTo(Processing::class);
    }

    /** @return HasOne<ProcessingFileResult, $this> */
    public function result(): HasOne
    {
        return $this->hasOne(ProcessingFileResult::class);
    }
}
