<?php

namespace App\Http\Controllers;

use App\Domain\Quotations\NormalizedQuotationLogo;
use App\Domain\Quotations\QuotationValidationException;
use App\Models\QuotationDraft;
use App\Models\User;
use App\Services\Quotations\QuotationDraftPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class QuotationDraftController extends Controller
{
    public function index(Request $request)
    {
        // Preserve the existing latest-draft contract for clients without pagination.
        if ($request->has('page')) {
            $request->validate(['page' => ['required', 'integer', 'min:1', 'max:1000000']]);
            $drafts = QuotationDraft::where('user_id', $request->user()->id)->where('expires_at', '>', now())
                ->latest('updated_at')->latest('id')->paginate(10);

            return response()->json(['data' => $drafts->getCollection()->map(fn (QuotationDraft $draft) => [
                'uuid' => $draft->uuid, 'revision' => $draft->revision,
                'number' => $draft->payload['number'] ?? '',
                'customer_name' => $draft->payload['customer']['name'] ?? '',
                'template_id' => $draft->payload['template_id'] ?? 'modern',
                'updated_at' => $draft->updated_at->toIso8601String(),
                'expires_at' => $draft->expires_at->toIso8601String(),
            ])->values(), 'meta' => ['current_page' => $drafts->currentPage(), 'last_page' => $drafts->lastPage(), 'total' => $drafts->total()]]);
        }
        $draft = QuotationDraft::where('user_id', $request->user()->id)->where('expires_at', '>', now())->latest('updated_at')->latest('id')->first();

        return response()->json(['data' => $draft ? $this->resource($draft) : null]);
    }

    public function store(Request $request)
    {
        $this->envelope($request, ['schema_version', 'payload']);
        $request->validate(['schema_version' => ['required', 'integer', 'in:1']]);
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => ['Use uma chave UUID.']]);
        }
        $payload = QuotationDraftPayload::validate($request->input('payload'));
        $hash = QuotationDraftPayload::fingerprint($payload);

        return DB::transaction(function () use ($request, $key, $payload, $hash) {
            // Serialize first imports for this user, including concurrent retries on MySQL.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $draft = QuotationDraft::where('user_id', $request->user()->id)->where('import_key', $key)->first();
            if ($draft) {
                $this->live($draft);
                if (! hash_equals($draft->import_hash, $hash)) {
                    throw new HttpException(409, 'idempotency_conflict');
                }

                return response()->json(['data' => $this->resource($draft)]);
            }
            $draft = QuotationDraft::create(['uuid' => (string) Str::uuid(), 'user_id' => $request->user()->id,
                'import_key' => $key, 'import_hash' => $hash, 'revision' => 1, 'schema_version' => 1,
                'payload' => $payload, 'expires_at' => now()->addDay()]);

            return response()->json(['data' => $this->resource($draft)], 201);
        });
    }

    public function show(Request $request, string $uuid)
    {
        return response()->json(['data' => $this->resource($this->owned($request, $uuid))]);
    }

    public function update(Request $request, string $uuid)
    {
        $this->envelope($request, ['revision', 'schema_version', 'payload']);
        $request->validate(['schema_version' => ['required', 'integer', 'in:1']]);
        $payload = QuotationDraftPayload::validate($request->input('payload'));

        return DB::transaction(function () use ($request, $uuid, $payload) {
            $draft = $this->owned($request, $uuid, true);
            $this->revision($request, $draft);
            $draft->payload = $payload;
            $this->touchDraft($draft);

            return response()->json(['data' => $this->resource($draft)]);
        });
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->envelope($request, ['revision']);

        return DB::transaction(function () use ($request, $uuid) {
            $draft = $this->owned($request, $uuid, true, false);
            $this->revision($request, $draft);
            if ($draft->logo_path) {
                Storage::disk('quotation_drafts')->delete($draft->logo_path);
            }
            $draft->delete();

            return response()->noContent();
        });
    }

    public function putLogo(Request $request, string $uuid)
    {
        $this->envelope($request, ['revision', 'logo', '_method']);
        $this->owned($request, $uuid);
        $request->validate(['logo' => ['required', 'file', 'max:2048']]);
        try {
            $logo = NormalizedQuotationLogo::fromBytes($request->file('logo')->get());
        } catch (QuotationValidationException) {
            throw ValidationException::withMessages(['logo' => ['Use PNG/JPEG válido de até 2 MiB e 4 milhões de pixels.']]);
        }

        return DB::transaction(function () use ($request, $uuid, $logo) {
            $draft = $this->owned($request, $uuid, true);
            $this->revision($request, $draft);
            $disk = Storage::disk('quotation_drafts');
            $old = $draft->logo_path;
            $path = $draft->uuid.'/'.Str::uuid().'.png';
            $disk->put($path, $logo->png);
            $draft->logo_path = $path;
            $draft->logo_checksum = hash('sha256', $logo->png);
            $this->touchDraft($draft);
            // Immutable paths: reads and replacements are serialized by the draft row lock.
            DB::afterCommit(function () use ($disk, $old) {
                if ($old) {
                    $disk->delete($old);
                }
            });

            return response()->json(['data' => $this->resource($draft)]);
        });
    }

    public function logo(Request $request, string $uuid)
    {
        return DB::transaction(function () use ($request, $uuid) {
            $draft = $this->owned($request, $uuid, true);
            abort_unless($draft->logo_path, 404);
            $png = Storage::disk('quotation_drafts')->get($draft->logo_path);

            return response($png, 200, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
        });
    }

    public function deleteLogo(Request $request, string $uuid)
    {
        $this->envelope($request, ['revision']);

        return DB::transaction(function () use ($request, $uuid) {
            $draft = $this->owned($request, $uuid, true);
            $this->revision($request, $draft);
            if ($draft->logo_path) {
                Storage::disk('quotation_drafts')->delete($draft->logo_path);
            }
            $draft->logo_path = $draft->logo_checksum = null;
            $this->touchDraft($draft);

            return response()->json(['data' => $this->resource($draft)]);
        });
    }

    private function envelope(Request $request, array $keys): void
    {
        if (array_diff(array_keys($request->all()), $keys)) {
            throw ValidationException::withMessages(['payload' => ['Campos não permitidos.']]);
        }
    }

    private function owned(Request $request, string $uuid, bool $lock = false, bool $live = true): QuotationDraft
    {
        $query = QuotationDraft::where('user_id', $request->user()->id)->where('uuid', $uuid);
        $draft = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        if ($live) {
            $this->live($draft);
        }

        return $draft;
    }

    private function live(QuotationDraft $draft): void
    {
        if ($draft->expires_at->lessThanOrEqualTo(now())) {
            throw new HttpException(410, 'draft_expired');
        }
    }

    private function revision(Request $request, QuotationDraft $draft): void
    {
        $input = $request->validate(['revision' => ['required', 'integer', 'min:1', 'max:4294967294']]);
        if ((int) $input['revision'] !== $draft->revision) {
            throw new HttpException(409, 'draft_revision_conflict');
        }
    }

    private function touchDraft(QuotationDraft $draft): void
    {
        $draft->revision++;
        $draft->expires_at = now()->addDay();
        $draft->save();
    }

    private function resource(QuotationDraft $draft): array
    {
        return ['uuid' => $draft->uuid, 'revision' => $draft->revision, 'schema_version' => $draft->schema_version,
            'payload' => $draft->payload, 'logo_checksum' => $draft->logo_checksum,
            'created_at' => $draft->created_at->toIso8601String(), 'updated_at' => $draft->updated_at->toIso8601String(),
            'expires_at' => $draft->expires_at->toIso8601String()];
    }
}
