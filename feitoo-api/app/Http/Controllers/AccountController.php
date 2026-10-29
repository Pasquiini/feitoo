<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\ProcessingHistoryResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function profile(UpdateProfileRequest $request): UserResource
    {
        $request->user()->update($request->safe()->only(['name']));

        return new UserResource($request->user()->fresh());
    }

    public function password(UpdatePasswordRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (! $user->password || ! Hash::check($request->validated('current_password'), $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'A senha atual não confere.']);
            }
            $user->forceFill(['password' => $request->validated('password'), 'remember_token' => Str::random(60)])->save();
        });
        $request->session()->regenerate();

        return response()->json(['message' => 'Senha atualizada.']);
    }

    public function processings(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);

        $page = $request->user()->processings()
            ->with(['output' => fn ($query) => $query->select('id', 'processing_id', 'original_name', 'expires_at')])
            ->withExists(['files as files_exist' => fn ($query) => $query->whereNull('removed_at')])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($request->input('per_page') == '3' ? 3 : 10);

        // Read only persisted totals for this owner's current page, never XML or preview data.
        $totals = DB::table('processing_file_results as results')
            ->join('processing_files as files', 'files.id', '=', 'results.processing_file_id')
            ->whereIn('files.processing_id', $page->getCollection()->modelKeys())
            ->where('files.kind', 'input')->where('files.status', 'completed')->whereNull('files.removed_at')
            ->get(['files.processing_id', 'results.total_value'])->groupBy('processing_id');
        foreach ($page as $processing) {
            $values = $totals->get($processing->id, collect())->pluck('total_value');
            $complete = $processing->successful_files_count > 0
                && $values->count() === $processing->successful_files_count && ! $values->contains(null);
            $processing->setAttribute('history_total_value', $complete
                ? (string) $values->reduce(fn (BigDecimal $sum, string $value) => $sum->plus($value), BigDecimal::zero())
                : null);
        }

        return ProcessingHistoryResource::collection($page);
    }
}
