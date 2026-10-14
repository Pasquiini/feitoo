<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Fiscal\DTO\FiscalDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingFileResult extends Model
{
    protected $guarded = ['id'];

    // Fiscal summaries are internal; no model serialization in HTTP responses.
    protected $hidden = ['id', 'processing_file_id', 'access_key', 'issuer_document', 'issuer_name', 'recipient_document', 'recipient_name'];

    protected function casts(): array
    {
        return ['document_type' => FiscalDocumentType::class, 'items_count' => 'integer', 'issued_at_has_time' => 'boolean', 'items_preview' => 'array', 'products_items' => 'array'];
    }

    /** @return BelongsTo<ProcessingFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(ProcessingFile::class, 'processing_file_id');
    }
}
