<?php

namespace App\Http\Controllers;

use App\Models\QuotationCreditOrder;
use App\Services\Quotations\QuotationOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuotationOrderController extends Controller
{
    public function store(Request $request, QuotationOrderService $service)
    {
        $request->validate(['return_destination' => ['sometimes', 'required', 'in:editor,account']]);
        if ($request->except('return_destination') || ! Str::isUuid($request->header('Idempotency-Key', ''))) {
            throw ValidationException::withMessages(['request' => ['Envie apenas uma chave idempotente UUID.']]);
        }

        return response()->json(['data' => $service->resource($service->checkout($request->user(), $request->header('Idempotency-Key'), $request->input('return_destination', 'editor')))], 201);
    }

    public function show(Request $request, string $uuid, QuotationOrderService $service)
    {
        $order = QuotationCreditOrder::where('user_id', $request->user()->id)->where('stripe_mode', config('monetization.stripe.mode'))->where('uuid', $uuid)->first();
        abort_unless($order, 404);

        return response()->json(['data' => $service->resource($service->refresh($order))]);
    }

    public function index(Request $request, QuotationOrderService $service)
    {
        $orders = QuotationCreditOrder::where('user_id', $request->user()->id)->where('stripe_mode', config('monetization.stripe.mode'))->latest('id')->paginate(20);

        return response()->json(['data' => $orders->getCollection()->map(fn ($o) => $service->resource($o)),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()]]);
    }
}
