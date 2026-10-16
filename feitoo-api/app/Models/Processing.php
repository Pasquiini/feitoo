<?php

namespace App\Models;

use App\Enums\ProcessingFileKind;
use App\Enums\ProcessingFileStatus;
use App\Enums\ProcessingStatus;
use App\Enums\ProcessingTool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Processing extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['files_count' => 0, 'total_size' => 0, 'processed_files_count' => 0, 'successful_files_count' => 0, 'failed_files_count' => 0];

    protected $hidden = ['id', 'user_id', 'metadata'];

    protected static function booted(): void
    {
        static::creating(function (Processing $processing): void {
            $processing->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ProcessingStatus::class,
            'tool' => ProcessingTool::class,
            'files_count' => 'integer',
            'processed_files_count' => 'integer',
            'successful_files_count' => 'integer',
            'failed_files_count' => 'integer',
            'total_size' => 'integer',
            'usage_files_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<ProcessingFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ProcessingFile::class);
    }

    public function output(): HasMany
    {
        return $this->files()->where('kind', ProcessingFileKind::Output->value)
            ->when($this->tool === ProcessingTool::XmlToPdf, fn ($query) => $query->where('extension', 'zip'))
            ->when($this->tool === ProcessingTool::DocxToPdf, fn ($query) => $query->where('extension', 'zip'))
            ->where('status', ProcessingFileStatus::Completed->value)->whereNull('removed_at');
    }

    public function purchase(): HasOne
    {
        return $this->hasOne(Purchase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
