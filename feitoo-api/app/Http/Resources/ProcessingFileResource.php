<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ProcessingTool;
use App\Services\DanfeGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcessingFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'original_name' => $this->original_name,
            'status' => $this->status->value,
            'size' => $this->size,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'error_code' => $this->error_code?->value,
            'error_message' => $this->error_message,
            'organization' => $this->when($this->processing->tool === ProcessingTool::XmlOrganizer && $this->removed_at === null && $this->processing->expires_at?->isFuture(), function () {
                $row = $this->processing->metadata['organizer_files'][$this->uuid] ?? null;
                if ($row) {
                    unset($row['checksum'], $row['preservable']);
                }

                return $row;
            }),
            'document' => $this->when($this->processing->tool === ProcessingTool::XmlToPdf && $this->removed_at === null && $this->processing->expires_at?->isFuture(), function () {
                $result = $this->result;

                return $result ? [
                    'access_key' => $result->access_key, 'number' => $result->number, 'series' => $result->series,
                    'issuer_name' => $result->issuer_name, 'recipient_name' => $result->recipient_name,
                    'total_value' => $result->total_value, 'notice' => DanfeGenerator::NOTICE,
                ] : null;
            }),
        ];
    }
}
