<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function challenge(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'totp_code' => 'required|string|size:6',
            'action_payload' => 'required|array',
        ]);
        /** @var \App\Models\User $user */
        $user = $request->user();
        //verify the code
        $google2fa = new Google2FA();
        $isValid = $google2fa->verifyKey((string) $user->totp_secret, $request->totp_code);
        if (!$isValid) {
            return response()->json(['message' => 'Invalid TOTP code'], 401);
        }
        //deterministically hash the payload
        $payload = $request->action_payload;
        ksort($payload); //sort to ensure payload is in order in case user send payload in diffrent order
        $actionHash = hash('sha256', json_encode($payload) ?: '');
        //generate a secure random EAT
        $eatToken = Str::random(64);
        //store in redis with 60 sec TTL
        Redis::setex("eat:{$eatToken}", 60, $actionHash);
        return response()->json([
            'elevated_action_token' => $eatToken,
            'expires_in' => 60
        ]);
    }
}
