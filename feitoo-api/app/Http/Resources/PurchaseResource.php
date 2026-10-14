<?php

namespace App\Http\Resources;

use App\Enums\ProcessingStatus;
use App\Enums\PurchaseStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expired = in_array($this->status, [PurchaseStatus::Pending, PurchaseStatus::Failed], true) && ! $this->expires_at->isFuture();

        return [
            'id' => $this->uuid, 'processing_id' => $this->processing->uuid, 'tool' => $this->processing->tool->value,
            'package_code' => $this->package_code, 'files_limit' => $this->files_limit, 'files_count' => $this->files_count,
            'amount' => $this->amount, 'currency' => $this->currency,
            'status' => $expired ? PurchaseStatus::Expired->value : $this->status->value,
            'paid_at' => $this->paid_at?->toIso8601String(), 'consumed' => $this->consumed_at !== null,
            'can_resume_processing' => $this->status === PurchaseStatus::Paid && $this->consumed_at === null
                && $this->processing->status === ProcessingStatus::Pending && $this->processing->expires_at?->isFuture(),
            'expires_at' => $this->expires_at->toIso8601String(), 'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
