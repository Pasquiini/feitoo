<?php

namespace App\Services;

use App\Models\Processing;
use App\Models\ProcessingFileResult;
use Brick\Math\BigDecimal;

class ProcessingPreviewService
{
    public const NOTES_LIMIT = 5;

    public const ITEMS_LIMIT = 10;

    public function make(Processing $processing): array
    {
        $query = ProcessingFileResult::query()->whereIn('processing_file_id',
            $processing->files()->where('kind', 'input')->where('status', 'completed')->whereNull('removed_at')->select('id'));
        $total = BigDecimal::zero();
        $missing = 0;
        $itemsCount = 0;
        $notes = [];
        $items = [];
        $samples = [];
        foreach ($query->orderBy('id')->lazyById(100) as $note) {
            $itemsCount += $note->items_count;
            if ($note->total_value === null) {
                $missing++;
            } else {
                $total = $total->plus($note->total_value);
            }
            if (count($notes) < self::NOTES_LIMIT) {
                $notes[] = [
                    'number' => $note->number, 'series' => $note->series, 'issued_at' => $note->issued_at,
                    'issuer_name' => $note->issuer_name, 'recipient_name' => $note->recipient_name,
                    'items_count' => $note->items_count, 'total_value' => $note->total_value,
                ];
            }
            if (count($samples) < self::NOTES_LIMIT && $note->items_preview) {
                $samples[] = ['note' => $note, 'items' => array_slice($note->items_preview, 0, self::ITEMS_LIMIT)];
            }
        }
        // Round robin keeps the sample bounded and represents different persisted documents.
        for ($offset = 0; $offset < self::ITEMS_LIMIT && count($items) < self::ITEMS_LIMIT; $offset++) {
            foreach ($samples as $sample) {
                if (isset($sample['items'][$offset]) && count($items) < self::ITEMS_LIMIT) {
                    $note = $sample['note'];
                    $items[] = [...$sample['items'][$offset], 'note_number' => $note->number,
                        'series' => $note->series, 'note_reference' => hash_hmac('sha256', (string) $note->id, (string) config('app.key')),
                        'issuer_name' => $note->issuer_name];
                }
            }
        }

        return [
            'summary' => [
                'successful_documents_count' => $processing->successful_files_count,
                'failed_documents_count' => $processing->failed_files_count,
                'items_count' => $itemsCount, 'total_value' => (string) $total,
                'documents_without_total_count' => $missing,
            ],
            'notes_preview' => $notes, 'items_preview' => $items,
        ];
    }
}
