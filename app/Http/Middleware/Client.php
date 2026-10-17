<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class Client
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($request->routeIs('client.subscribe', 'client.subscribe.legacy', 'client.subscribe.app')) {
            $request->attributes->set('subscription_statistics_at', now()->timestamp);
            $result = app(\App\Services\SubscriptionProtection::class)->authenticate($request);
            if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
                return $result;
            }
            Auth::setUser($result);
            return $next($request);
        }

        $token = $request->input('token', $request->route('token'));
        if (empty($token)) {
            throw new ApiException('token is null',403);
        }
        $user = User::where('token', $token)->first();
        if (!$user) {
            throw new ApiException('token is error',403);
        }
        
        Auth::setUser($user);
        return $next($request);
    }

    public function terminate($request, $response): void
    {
        if ($request->routeIs('client.subscribe', 'client.subscribe.legacy', 'client.subscribe.app')) {
            app(\App\Services\SubscriptionStatisticsCapture::class)->record($request, $response->getStatusCode());
        }
    }
}
