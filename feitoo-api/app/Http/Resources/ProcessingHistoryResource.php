<?php

namespace App\Http\Resources;

use App\Enums\ProcessingStatus;
use App\Enums\ProcessingTool;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcessingHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expired = $this->status === ProcessingStatus::Expired || ! $this->expires_at?->isFuture();
        $output = $this->output->first();

        return [
            'id' => $this->uuid, 'tool' => $this->tool->value,
            'status' => $expired ? ProcessingStatus::Expired->value : $this->status->value,
            'created_at' => $this->created_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'files_count' => $this->files_count, 'successful_files_count' => $this->successful_files_count,
            'failed_files_count' => $this->failed_files_count,
            'total_value' => $this->tool === ProcessingTool::XmlAnalyzer ? ($this->metadata['analyzer_summary']['total_value'] ?? null) : ($this->tool === ProcessingTool::ProductsFromXml
                ? ($this->metadata['products_summary']['total_value'] ?? null) : $this->history_total_value),
            'products_summary' => $this->tool === ProcessingTool::ProductsFromXml
                ? ($this->metadata['products_summary'] ?? null) : null,
            'files_deleted' => $this->files_count > 0 && ! $this->files_exist,
            'can_delete_files' => (bool) $this->files_exist,
            'result' => [
                'available' => ! $expired && $this->status === ProcessingStatus::Completed && $output?->expires_at?->isFuture() === true,
                'file_name' => ! $expired ? $output?->original_name : null,
            ],
        ];
    }
}
