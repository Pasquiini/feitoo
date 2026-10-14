<?php

namespace App\Http\Controllers;

use App\Exceptions\GoogleAccountException;
use App\Services\GoogleAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $input = $request->validate(['intent' => ['required', 'in:login,link'], 'return_to' => ['sometimes', 'in:/,/conta,/xml-para-excel,/produtos-dos-xmls']]);
        abort_unless(config('services.google.client_id') && config('services.google.client_secret'), 503, 'Google indisponível.');
        $user = Auth::guard('web')->user();
        abort_if($input['intent'] === 'link' && ! $user, 401);
        abort_if($input['intent'] === 'login' && $user, 409);
        $request->session()->put('google_auth_context', [
            'intent' => $input['intent'], 'user_id' => $input['intent'] === 'link' ? $user->id : null,
            'started_at' => now()->timestamp,
            'return_to' => $input['intent'] === 'link' ? '/conta' : ($input['return_to'] ?? '/'),
        ]);
        $redirect = Socialite::driver('google')->enablePKCE()->scopes(['openid', 'email', 'profile'])
            ->with(['prompt' => 'select_account'])->redirect();

        return response()->json(['redirect_url' => $redirect->getTargetUrl()]);
    }

    public function callback(Request $request, GoogleAccountService $accounts): RedirectResponse
    {
        $context = $request->session()->pull('google_auth_context');
        try {
            if (! is_array($context) || now()->timestamp - ($context['started_at'] ?? 0) > 600
                || ! is_string($request->query('code')) || $request->query('code') === ''
                || ! is_string($request->query('state')) || $request->query('state') === '' || strlen($request->query('state')) > 512
                || strlen($request->query('code')) > 4096 || $request->has('error')) {
                throw new GoogleAccountException('invalid_google');
            }
            $linkUser = $context['intent'] === 'link' ? Auth::guard('web')->user() : null;
            if (($context['intent'] === 'link' && (! $linkUser || $linkUser->id !== $context['user_id']))
                || ($context['intent'] === 'login' && Auth::guard('web')->check())) {
                throw new GoogleAccountException('link_conflict');
            }
            // Never stateless: Socialite verifies session state and fetches identity from Google server-side.
            $identity = Socialite::driver('google')->enablePKCE()->user();
            $user = $accounts->resolve($identity, $linkUser);
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            if ($context['intent'] === 'login' && $user->wasRecentlyCreated) {
                $request->session()->put('completed_google_signup', true);
            }

            $returnTo = in_array($context['return_to'] ?? '/', ['/', '/conta', '/xml-para-excel', '/produtos-dos-xmls'], true) ? $context['return_to'] ?? '/' : '/';

            return redirect()->away(rtrim(config('feitoo.auth.frontend_url'), '/').$returnTo);
        } catch (Throwable $exception) {
            Log::notice('Google authentication failed.', ['exception_type' => $exception::class]);
            $reason = $exception instanceof GoogleAccountException ? $exception->reason : 'invalid_google';
            $target = is_array($context) && ($context['intent'] ?? null) === 'link'
                ? '/conta?google_error=link_conflict' : '/login?google_error='.$reason
                    .(($context['return_to'] ?? null) === '/conta' ? '&returnTo=%2Fconta' : '');

            return redirect()->away(rtrim(config('feitoo.auth.frontend_url'), '/').$target);
        } finally {
            $request->session()->forget('state');
            $request->session()->forget('code_verifier');
        }
    }
}
