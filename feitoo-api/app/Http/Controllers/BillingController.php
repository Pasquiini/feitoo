<?php

namespace App\Http\Controllers;

use App\Enums\ProcessingTool;
use App\Enums\PurchaseStatus;
use App\Http\Resources\PurchaseResource;
use App\Models\Processing;
use App\Models\Purchase;
use App\Services\EntitlementService;
use App\Services\PurchaseService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function usage(Request $request, EntitlementService $entitlements)
    {
        $input = $request->validate(['tool' => ['sometimes', Rule::enum(ProcessingTool::class)]]);

        return response()->json(['data' => $entitlements->usage($request->user(), tool: ProcessingTool::from($input['tool'] ?? ProcessingTool::XmlToExcel->value))]);
    }

    public function purchase(Request $request, Processing $processing, PurchaseService $service)
    {
        $result = $service->checkout($processing, $request->user());

        return response()->json(['data' => ['purchase' => (new PurchaseResource($result['purchase']))->resolve(), 'checkout_url' => $result['checkout_url']]]);
    }

    public function show(Processing $processing)
    {
        $purchase = Purchase::query()->where('processing_id', $processing->id)->firstOrFail();
        if (in_array($purchase->status, [PurchaseStatus::Pending, PurchaseStatus::Failed], true) && ! $purchase->expires_at->isFuture()) {
            Purchase::query()->whereKey($purchase->id)->whereIn('status', [PurchaseStatus::Pending->value, PurchaseStatus::Failed->value])
                ->where('expires_at', '<=', now())->update(['status' => PurchaseStatus::Expired->value, 'checkout_url' => null]);
            $purchase->refresh();
        }

        return new PurchaseResource($purchase);
    }

    public function cancel(Request $request, Processing $processing, PurchaseService $service)
    {
        return new PurchaseResource($service->cancel($processing, $request->user()));
    }

    public function purchases(Request $request)
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);

        return PurchaseResource::collection(Purchase::query()->where('user_id', $request->user()->id)->with('processing:id,uuid,tool,status,expires_at')
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(10));
    }
}
