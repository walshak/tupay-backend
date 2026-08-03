<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyElevatedActionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $eatToken = $request->header('X-Elevated-Action-Token');
        if (!is_string($eatToken)) {
            return response()->json(['message' => 'Missing or invalid X-Elevated-Action-Token header'], 401);
        }
        //atomically get and delete the token in Redis to prevent replay attacks
        //a simple Lua script to guarantee atomicity 
        // if two concurrent requests hit this, only one will successfully retrieve the value
        $lua = <<<LUA
            local val = redis.call("GET", KEYS[1])
            if val then
                redis.call("DEL", KEYS[1])
            end
            return val
        LUA;
        /** @phpstan-ignore-next-line (PHPStan expects PHPRedis signature, but we use Predis) */
        $storedHash = Redis::eval($lua, 1, "eat:{$eatToken}");
        if (!$storedHash) {
            return response()->json(['message' => 'Invalid or expired Elevated Action Token'], 401);
        }
        //remake the hash from the current request payload
        $payload = $request->all();
        ksort($payload);
        $currentHash = hash('sha256', json_encode($payload) ?: '');
        //compare the hashes to ensure the payload hasn't been tampered with
        if (!hash_equals($storedHash, $currentHash)) {
            return response()->json(['message' => 'Payload tampering detected. Action hash mismatch.'], 422);
        }
        return $next($request);
    }
}
