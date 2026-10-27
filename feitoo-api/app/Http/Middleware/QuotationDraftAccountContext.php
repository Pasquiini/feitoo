<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QuotationDraftAccountContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // This is an additional consistency check, never an authentication credential.
        $expected = $request->header('X-Quotation-Owner');
        abort_if($expected !== null && $expected !== (string) $request->user()->id, 401);

        return $next($request);
    }
}
