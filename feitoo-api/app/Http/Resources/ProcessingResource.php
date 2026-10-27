<?php

namespace App\Http\Resources;

use App\Enums\ProcessingStatus;
use App\Enums\ProcessingTool;
use App\Models\QuotationGeneration;
use App\Services\ProcessingPreviewService;
use App\Services\ProductsFromXmlResultService;
use App\Services\XmlOrganizerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcessingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->tool === ProcessingTool::QuotationGenerator) {
            $generation = QuotationGeneration::where('processing_id', $this->id)->first();
            $expired = $this->status === ProcessingStatus::Expired || ! $this->expires_at?->isFuture();
            $output = ! $expired && $this->status === ProcessingStatus::Completed ? $this->output()->first() : null;

            return ['id' => $this->uuid, 'tool' => $this->tool->value, 'status' => $expired ? 'expired' : $this->status->value,
                'created_at' => $this->created_at->toIso8601String(), 'completed_at' => $this->completed_at?->toIso8601String(),
                'expires_at' => $this->expires_at?->toIso8601String(),
                'quotation' => ['draft_uuid' => $generation?->draft_uuid, 'revision' => $generation?->draft_revision,
                    'attempt' => $generation?->attempt, 'reservation_date' => $generation?->reservation_date,
                    'pending_expires_at' => $generation?->pending_expires_at?->toIso8601String(),
                    'source' => $generation?->source ?? 'free', 'consumption_status' => $generation?->consumption_status,
                    'error_code' => $generation?->error_code,
                    'retryable' => ! $expired && $this->status === ProcessingStatus::Failed && $generation?->consumption_status === 'released'
                        && in_array($generation?->error_code, ['render_failed', 'execution_timeout', 'execution_failed', 'storage_unavailable', 'worker_abandoned'], true)],
                'result' => ['available' => $output !== null, 'file_name' => $output?->original_name,
                    'size' => $output?->size, 'error_message' => $this->status === ProcessingStatus::Failed
                        ? 'Não foi possível gerar este orçamento. Nenhuma unidade foi consumida.' : null]];
        }
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
            'organizer' => $this->when($this->tool === ProcessingTool::XmlOrganizer, fn () => [
                'options' => $this->metadata['organizer_options'] ?? XmlOrganizerService::DEFAULT_OPTIONS,
                'summary' => $output ? app(XmlOrganizerService::class)->summary($this->resource) : null,
            ]),
            'analyzer' => $this->when($this->tool === ProcessingTool::XmlAnalyzer && $output !== null, fn () => ['summary' => $this->metadata['analyzer_summary'] ?? null, 'audit' => $this->metadata['analyzer_audit'] ?? null]),
            'preview' => $output !== null && $this->successful_files_count > 0 && ! in_array($this->tool, [ProcessingTool::DocxToPdf, ProcessingTool::XmlToPdf, ProcessingTool::XmlOrganizer, ProcessingTool::XmlAnalyzer], true)
                ? ($this->tool === ProcessingTool::ProductsFromXml
                    ? app(ProductsFromXmlResultService::class)->preview($this->resource)
                    : app(ProcessingPreviewService::class)->make($this->resource)) : null,
            'result' => [
                'available' => $output !== null,
                'file_name' => $output?->original_name,
                'size' => $output?->size,
                'error_message' => $this->tool === ProcessingTool::DocxToPdf && $this->status === ProcessingStatus::Failed ? 'Não foi possível converter os documentos. Consulte o erro de cada arquivo.' : (($this->metadata['result_error'] ?? null) === 'no_valid_documents'
                    ? ($this->tool === ProcessingTool::ProductsFromXml ? 'Não encontramos NF-es válidas nesses arquivos.' : 'Não houve documentos válidos para exportação.')
                    : (($this->metadata['result_error'] ?? null) === 'technical_capacity_exceeded'
                        ? 'O lote ultrapassou a capacidade de itens do Excel. Divida os documentos em lotes menores.'
                        : ($this->status === ProcessingStatus::Failed ? ($this->tool === ProcessingTool::XmlOrganizer ? 'Não foi possível gerar o ZIP organizado.' : ($this->tool === ProcessingTool::XmlToPdf ? 'Não foi possível gerar os DANFEs.' : 'Não foi possível gerar o Excel.')) : null))),
            ],
        ];
    }
}
