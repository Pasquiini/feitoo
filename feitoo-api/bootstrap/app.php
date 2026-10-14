<?php

use App\Exceptions\BillingException;
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
        $middleware->append(PrivateApiResponse::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['anonymous_token', 'authorization', 'files', 'current_password', 'password', 'password_confirmation']);
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
            if (request()->is('api/processings*', 'api/auth/*', 'api/account/*', 'api/me', 'api/user')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }

            return $response;
        });
    })->create();
