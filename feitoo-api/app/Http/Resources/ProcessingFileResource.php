<?php

declare(strict_types=1);

namespace App\Http\Resources;

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
        ];
    }
}
