<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Webhook-Signature');

        // In a real app, i will place this in  store this in .env or config('services.provider.webhook_secret')
        $secret = 'my-webhook-secret-key';

        // Hash the raw request body payload
        $computedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        if (!is_string($signature) || !hash_equals($computedSignature, $signature)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }
        return $next($request);
    }
}
