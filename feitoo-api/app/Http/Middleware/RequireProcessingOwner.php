<?php

namespace App\Http\Middleware;

use App\Models\Processing;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireProcessingOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $processing = $request->route('processing');
        abort_unless($processing instanceof Processing && $processing->user_id !== null
            && (int) $processing->user_id === (int) $request->user()->id, 404);

        return $next($request);
    }
}
