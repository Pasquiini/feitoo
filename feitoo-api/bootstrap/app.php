<?php

use App\Enums\ProcessingTool;
use App\Exceptions\BillingException;
use App\Exceptions\QuotationGenerationException;
use App\Http\Middleware\PrivateApiResponse;
use App\Models\Processing;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/quotations/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/quotations/*')]);
        $middleware->append(PrivateApiResponse::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['anonymous_token', 'authorization', 'files', 'current_password', 'password', 'password_confirmation', 'payload', 'logo']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*'));
        $exceptions->report(function (Throwable $exception) {
            if (! request()->is('api/*')) {
                return null;
            }

            // Never log exception messages/traces/SQL bindings from hostile uploads.
            $processing = request()->route('processing');
            Log::error(request()->is('api/processings*') ? 'Processing request failed.' : 'Authentication request failed.', [
                'processing_uuid' => $processing instanceof Processing ? $processing->uuid : null,
                'exception_type' => $exception::class,
            ]);

            return false;
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($exception instanceof QuotationGenerationException) {
                return response()->json(['code' => $exception->reason, 'message' => $exception->getMessage()], $exception->getStatusCode());
            }
            if ($request->route('processing') instanceof Processing && $request->route('processing')->tool === ProcessingTool::QuotationGenerator && ! $exception instanceof ValidationException) {
                $status = $exception instanceof AuthenticationException ? 401 : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500);
                $code = match ($status) {
                    401 => 'authentication_required', 404 => 'generation_not_found', 410 => 'result_expired', 429 => 'rate_limited', default => 'generation_request_failed'
                };

                return response()->json(['code' => $code, 'message' => $status === 410 ? 'O PDF do orçamento expirou.' : 'Não foi possível concluir a operação do orçamento.'], $status,
                    $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []);
            }
            if ($request->is('api/quotations/credit-orders*') && ! $exception instanceof ValidationException) {
                $status = $exception instanceof AuthenticationException ? 401 : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500);
                $code = match ($status) {
                    401 => 'authentication_required', 404 => 'order_not_found', 419 => 'session_expired', 429 => 'rate_limited', default => 'order_request_failed',
                };

                return response()->json(['code' => $code, 'message' => 'Não foi possível consultar ou atualizar o pedido de créditos.'], $status,
                    $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []);
            }
            if ($request->is('api/quotations/*') && ! $exception instanceof ValidationException) {
                $status = $exception instanceof AuthenticationException ? 401 : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500);
                $code = match ($status) {
                    401 => 'authentication_required', 404 => 'draft_not_found',
                    409 => $exception->getMessage() === 'idempotency_conflict' ? 'idempotency_conflict' : 'draft_revision_conflict',
                    410 => 'draft_expired', 419 => 'session_expired', 429 => 'rate_limited', default => 'draft_request_failed',
                };

                return response()->json(['code' => $code, 'message' => 'Não foi possível concluir a operação do rascunho.'], $status,
                    $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []);
            }
            if ($exception instanceof BillingException) {
                return response()->json(['code' => $exception->reason, 'message' => $exception->getMessage()], $exception->getStatusCode());
            }
            if ($request->is('api/*') && ! $exception instanceof ValidationException) {
                $status = $exception instanceof AuthenticationException ? 401 : ($exception instanceof HttpExceptionInterface
                    ? $exception->getStatusCode() : 500);
                $messages = [401 => 'Autenticação necessária.', 404 => 'Recurso não encontrado.',
                    409 => 'Processamento não aceita novos arquivos.', 410 => 'Processamento expirado.',
                    413 => 'Corpo da requisição excede o limite do servidor.', 419 => 'Sessão expirada. Tente novamente.',
                    429 => 'Muitas requisições. Tente novamente mais tarde.',
                    422 => 'Envie ao menos um XML antes de iniciar.',
                    503 => $request->is('api/auth/*') ? 'Autenticação Google indisponível.' : 'Fila de processamento indisponível.'];

                return response()->json(['message' => $messages[$status] ?? 'Não foi possível concluir a requisição.'], $status,
                    $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []);
            }

            return null;
        });
        $exceptions->respond(function (Response $response) {
            if (request()->is('api/processings*', 'api/auth/*', 'api/account/*', 'api/me', 'api/user', 'api/quotations/*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }

            return $response;
        });
    })->create();
