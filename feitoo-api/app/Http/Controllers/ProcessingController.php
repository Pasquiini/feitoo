<?php

namespace App\Http\Controllers;

use App\Enums\ProcessingStatus;
use App\Http\Requests\StoreProcessingRequest;
use App\Http\Requests\UploadProcessingFilesRequest;
use App\Http\Resources\ProcessingFileResource;
use App\Http\Resources\ProcessingResource;
use App\Models\Processing;
use App\Services\EntitlementService;
use App\Services\FiscalProcessingService;
use App\Services\ProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcessingController extends Controller
{
    public function store(StoreProcessingRequest $request): JsonResponse
    {
        $processing = Processing::create([
            'tool' => $request->validated('tool'),
            'status' => ProcessingStatus::Pending,
            'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(app(EntitlementService::class)->retentionMinutes($request->user())),
        ]);

        return (new ProcessingResource($processing))
            ->response()->setStatusCode(201)->header('Cache-Control', 'no-store, private');
    }

    public function show(Processing $processing): ProcessingResource
    {
        return new ProcessingResource($processing);
    }

    public function start(Request $request, Processing $processing, FiscalProcessingService $service): JsonResponse
    {
        $validated = $request->validate(['free_files_count' => ['sometimes', 'integer', 'min:1']]);

        return (new ProcessingResource($service->start($processing, $request->user(), $validated['free_files_count'] ?? null)))->response()->setStatusCode(202);
    }

    public function files(Processing $processing): AnonymousResourceCollection
    {
        return ProcessingFileResource::collection($processing->files()->where('kind', 'input')->orderBy('id')->get());
    }

    public function result(Request $request, Processing $processing): StreamedResponse
    {
        // Open the private stream under the same lock used by purge.
        [$file, $stream] = DB::transaction(function () use ($request, $processing): array {
            $locked = Processing::query()->lockForUpdate()->findOrFail($processing->id);
            abort_unless((int) $locked->user_id === (int) $request->user()->id, 404);
            abort_if($locked->status === ProcessingStatus::Expired || $locked->expires_at?->lessThanOrEqualTo(now()), 410);
            abort_unless($locked->status === ProcessingStatus::Completed, 409);
            $file = $locked->output()->first();
            abort_unless($file && $file->expires_at?->isFuture(), 404);
            try {
                $stream = Storage::disk($file->storage_disk)->readStream($file->storage_path);
            } catch (\Throwable) {
                abort(404);
            }
            abort_unless(is_resource($stream), 404);

            return [$file, $stream];
        });

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $file->original_name, ['Content-Type' => $file->mime_type, 'Content-Length' => (string) $file->size,
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function upload(UploadProcessingFilesRequest $request, Processing $processing, ProcessingService $service): ProcessingResource
    {
        return new ProcessingResource($service->upload($processing, array_values($request->file('files')), $request->user()));
    }

    public function destroyFiles(Request $request, Processing $processing, ProcessingService $service): ProcessingResource
    {
        return new ProcessingResource($service->removeFiles($processing, owner: $request->user()));
    }
}
