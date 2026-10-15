<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProcessingStatus;
use App\Enums\ProcessingTool;
use App\Models\Processing;
use App\Services\AnalyzerResultService;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class XmlAnalysisController extends Controller
{
    public function show(Request $request, Processing $processing, AnalyzerResultService $service)
    {
        [$filters, $input] = $this->parameters($request);

        return DB::transaction(function () use ($request, $processing, $service, $filters, $input) {
            $locked = $this->locked($request, $processing);
            $view = $service->view($locked, $filters);
            $allIssuers = $service->view($locked, [])['issuers'];
            $issuerSort = $input['issuer_sort'] ?? 'value';
            usort($view['issuers'], fn ($a, $b) => $issuerSort === 'count'
                ? (($b['documents_count'] <=> $a['documents_count']) ?: strcmp($a['id'], $b['id']))
                : ($this->decimalOrder($a['total_value'], $b['total_value'], 'desc') ?: strcmp($a['id'], $b['id'])));
            $sort = $input['document_sort'] ?? 'date';
            $direction = $input['direction'] ?? 'desc';
            usort($view['documents'], function ($a, $b) use ($sort, $direction) {
                $comparison = match ($sort) {
                    'value' => $this->decimalOrder($a['total_value'] ?? null, $b['total_value'] ?? null, $direction),
                    'number' => strnatcmp($a['number'] ?? '', $b['number'] ?? '') * ($direction === 'asc' ? 1 : -1),
                    'status' => strcmp($a['analysis_status'], $b['analysis_status']) * ($direction === 'asc' ? 1 : -1),
                    default => strcmp($a['issue_date'] ?? '', $b['issue_date'] ?? '') * ($direction === 'asc' ? 1 : -1),
                };

                return $comparison ?: strcmp($a['file_id'], $b['file_id']);
            });
            $perPage = (int) ($input['per_page'] ?? 25);
            foreach (['documents' => 'document_page', 'products' => 'product_page', 'issuers' => 'issuer_page', 'inconsistencies' => 'issue_page'] as $key => $pageKey) {
                $all = $view[$key];
                $last = max(1, (int) ceil(count($all) / $perPage));
                $page = min((int) ($input[$pageKey] ?? 1), $last);
                $view[$key] = ['data' => array_slice($all, ($page - 1) * $perPage, $perPage), 'total' => count($all), 'page' => $page, 'last_page' => $last, 'per_page' => $perPage];
            }
            $view['issuer_options'] = array_map(fn ($i) => ['id' => $i['id'], 'name' => $i['name'], 'document' => $i['document'], 'trusted' => $i['trusted']], $allIssuers);

            return response()->json(['data' => $view])->header('Cache-Control', 'no-store, private');
        });
    }

    public function export(Request $request, Processing $processing, AnalyzerResultService $service)
    {
        [$filters] = $this->parameters($request);
        $stream = DB::transaction(function () use ($request, $processing, $service, $filters) {
            $locked = $this->locked($request, $processing);
            $path = $service->export($locked, $filters);
            $stream = Storage::disk(config('feitoo.processing.disk'))->readStream($path);
            abort_unless(is_resource($stream), 404);

            return $stream;
        });

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 'feitoo-analise-xml.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function parameters(Request $request): array
    {
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'issuer' => ['nullable', 'string', 'max:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'document_query' => ['nullable', 'string', 'max:100'], 'product_query' => ['nullable', 'string', 'max:100'],
            'issuer_sort' => ['sometimes', Rule::in(['value', 'count'])], 'document_sort' => ['sometimes', Rule::in(['date', 'number', 'value', 'status'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'document_page' => ['sometimes', 'integer', 'min:1', 'max:1000'], 'product_page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'issuer_page' => ['sometimes', 'integer', 'min:1', 'max:1000'], 'issue_page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
        $filters = [];
        foreach (['from', 'to', 'issuer', 'document_query', 'product_query'] as $key) {
            $filters[$key] = isset($input[$key]) ? trim($input[$key]) : null;
        }

        // A lone end date is valid; cross-field validation only applies when a start exists.
        return [$filters, $input];
    }

    private function locked(Request $request, Processing $processing): Processing
    {
        $locked = Processing::query()->lockForUpdate()->findOrFail($processing->id);
        abort_unless((int) $locked->user_id === (int) $request->user()->id, 404);
        abort_if($locked->status === ProcessingStatus::Expired || ! $locked->expires_at?->isFuture(), 410);
        abort_unless($locked->tool === ProcessingTool::XmlAnalyzer && $locked->status === ProcessingStatus::Completed, 409);

        return $locked;
    }

    private function decimalOrder(?string $a, ?string $b, string $direction): int
    {
        if ($a === null || $b === null) {
            return $a === $b ? 0 : ($a === null ? 1 : -1);
        }

        return BigDecimal::of($a)->compareTo($b) * ($direction === 'asc' ? 1 : -1);
    }
}
