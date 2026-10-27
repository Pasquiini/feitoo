<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProcessingResource;
use App\Services\Quotations\QuotationGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuotationGenerationController extends Controller
{
    public function entitlements(Request $request, QuotationGenerationService $service)
    {
        return response()->json(['data' => $service->entitlements($request->user())]);
    }

    public function store(Request $request, string $uuid, QuotationGenerationService $service)
    {
        $input = $this->input($request, 'revision');

        return (new ProcessingResource($service->start($request->user(), $uuid, $input, $request->header('Idempotency-Key'), $request->input('source'))))->response()->setStatusCode(202);
    }

    public function retry(Request $request, string $uuid, QuotationGenerationService $service)
    {
        $input = $this->input($request, 'attempt');

        return (new ProcessingResource($service->retry($request->user(), $uuid, $input, $request->header('Idempotency-Key'))))->response()->setStatusCode(202);
    }

    private function input(Request $request, string $field): int
    {
        if (array_diff(array_keys($request->all()), ($field === 'revision' ? [$field, 'source'] : [$field])) || ! Str::isUuid($request->header('Idempotency-Key', ''))) {
            throw ValidationException::withMessages(['request' => ['Use apenas a revisão/tentativa e uma chave idempotente UUID.']]);
        }
        $request->validate(['source' => ['sometimes', 'required', 'in:free,pro,credit']]);
        $data = $request->validate([$field => ['required', 'integer', 'min:1', 'max:4294967294']]);

        return (int) $data[$field];
    }
}
