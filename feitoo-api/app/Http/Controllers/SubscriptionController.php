<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionService;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function show(Request $request, SubscriptionService $service)
    {
        return response()->json(['data' => $service->summary($request->user())])->header('Cache-Control', 'no-store, private');
    }

    public function checkout(Request $request, SubscriptionService $service)
    {
        $request->validate(['price_id' => ['prohibited'], 'status' => ['prohibited']]);

        return response()->json(['data' => ['url' => $service->checkout($request->user())]]);
    }

    public function portal(Request $request, SubscriptionService $service)
    {
        return response()->json(['data' => ['url' => $service->portal($request->user())]]);
    }
}
