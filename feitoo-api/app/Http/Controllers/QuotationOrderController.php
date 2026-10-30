<?php

namespace App\Http\Controllers;

use App\Models\QuotationCreditOrder;
use App\Services\Quotations\QuotationOrderDiagnostics;
use App\Services\Quotations\QuotationOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuotationOrderController extends Controller
{
    public function store(Request $request, QuotationOrderService $service)
    {
        QuotationOrderDiagnostics::stage('request_validate');
        $request->validate(['return_destination' => ['sometimes', 'required', 'in:editor,account']]);
        if ($request->except('return_destination') || ! Str::isUuid($request->header('Idempotency-Key', ''))) {
            throw ValidationException::withMessages(['request' => ['Envie apenas uma chave idempotente UUID.']]);
        }

        $order = $service->checkout($request->user(), $request->header('Idempotency-Key'), $request->input('return_destination', 'editor'));
        QuotationOrderDiagnostics::stage('response_serialize', $order);

        return response()->json(['data' => $service->resource($order)], 201);
    }

    public function show(Request $request, string $uuid, QuotationOrderService $service)
    {
        QuotationOrderDiagnostics::stage('order_lookup');
        $order = QuotationCreditOrder::where('user_id', $request->user()->id)->where('stripe_mode', config('monetization.stripe.mode'))->where('uuid', $uuid)->first();
        abort_unless($order, 404);

        $order = $service->refresh($order);
        QuotationOrderDiagnostics::stage('response_serialize', $order);

        return response()->json(['data' => $service->resource($order)]);
    }

    public function index(Request $request, QuotationOrderService $service)
    {
        QuotationOrderDiagnostics::stage('order_list');
        $orders = QuotationCreditOrder::where('user_id', $request->user()->id)->where('stripe_mode', config('monetization.stripe.mode'))->latest('id')->paginate(20);

        QuotationOrderDiagnostics::stage('response_serialize');

        return response()->json(['data' => $orders->getCollection()->map(fn ($o) => $service->resource($o)),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()]]);
    }
}
