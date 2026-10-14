<?php

namespace App\Http\Resources;

use App\Enums\ProcessingStatus;
use App\Enums\ProcessingTool;
use App\Services\ProcessingPreviewService;
use App\Services\ProductsFromXmlResultService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcessingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $output = $this->status === ProcessingStatus::Completed
            && $this->expires_at?->isFuture() ? $this->output()->first() : null;

        return [
            'id' => $this->uuid,
            'tool' => $this->tool->value,
            'status' => $this->status->value,
            'files_count' => $this->files_count,
            'processed_files_count' => $this->processed_files_count,
            'successful_files_count' => $this->successful_files_count,
            'failed_files_count' => $this->failed_files_count,
            'total_size' => $this->total_size,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'preview' => $output !== null && $this->successful_files_count > 0
                ? ($this->tool === ProcessingTool::ProductsFromXml
                    ? app(ProductsFromXmlResultService::class)->preview($this->resource)
                    : app(ProcessingPreviewService::class)->make($this->resource)) : null,
            'result' => [
                'available' => $output !== null,
                'file_name' => $output?->original_name,
                'size' => $output?->size,
                'error_message' => ($this->metadata['result_error'] ?? null) === 'no_valid_documents'
                    ? ($this->tool === ProcessingTool::ProductsFromXml ? 'Não encontramos NF-es válidas nesses arquivos.' : 'Não houve documentos válidos para exportação.')
                    : (($this->metadata['result_error'] ?? null) === 'technical_capacity_exceeded'
                        ? 'O lote ultrapassou a capacidade de itens do Excel. Divida os documentos em lotes menores.'
                        : ($this->status === ProcessingStatus::Failed ? 'Não foi possível gerar o Excel.' : null)),
            ],
        ];
    }
}
