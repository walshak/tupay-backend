<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SwapConcurrencyTest extends TestCase
{
    public function test_concurrent_swaps_prevent_race_conditions_and_replays()
    {
        // IMPORTANT: Your local dev server (php artisan serve) must be running 
        // on port 8000 for this true parallel test to work.
        $baseUrl = 'http://127.0.0.1:8000';

        // 1. Setup Isolated Test Data dynamically so we never run out of funds!
        $google2fa = new Google2FA();
        $password = 'password123';
        
        $user = new User();
        $user->name = 'Concurrent Tester';
        $user->email = 'test_' . Str::random(8) . '@example.com';
        $user->password = bcrypt($password);
        $user->totp_secret = $google2fa->generateSecretKey();
        $user->save();

        $sourceWallet = Wallet::create(['user_id' => $user->id, 'currency' => 'NGN']);
        $destWallet = Wallet::create(['user_id' => $user->id, 'currency' => 'CNY']);

        // Fund the wallet with exactly enough for 3 swaps (150M * 3 = 450M kobo)
        LedgerEntry::create([
            'transaction_reference' => Str::uuid(),
            'wallet_id' => $sourceWallet->id,
            'type' => 'credit',
            'amount' => 450000000,
            'description' => 'E2E Test Funding'
        ]);

        // 2. Log in as our newly created isolated user
        $loginResponse = Http::post("{$baseUrl}/api/login", [
            'email' => $user->email,
            'password' => $password
        ]);

        $this->assertTrue($loginResponse->successful(), "Failed to login. Is the server running?");
        $token = $loginResponse->json('access_token');

        // 3. Generate a valid TOTP Code
        $totpCode = $google2fa->getCurrentOtp($user->totp_secret);

        $payload = [
            'source_wallet_id' => $sourceWallet->id,
            'dest_wallet_id' => $destWallet->id,
            'amount_kobo' => 150000000
        ];

        // 4. Complete the 2FA Challenge to get ONE single-use Elevated Action Token
        $challengeResponse = Http::withToken($token)
            ->post("{$baseUrl}/api/2fa/challenge", [
                'totp_code' => $totpCode,
                'action_payload' => $payload
            ]);

        $this->assertTrue($challengeResponse->successful(), "2FA Challenge failed.");
        $eatToken = $challengeResponse->json('elevated_action_token');

        // 5. FIRE 10 CONCURRENT REQUESTS!
        $responses = Http::pool(function ($pool) use ($baseUrl, $token, $eatToken, $payload) {
            $requests = [];
            for ($i = 0; $i < 10; $i++) {
                $requests[] = $pool->withToken($token)
                    ->withHeaders(['X-Elevated-Action-Token' => $eatToken])
                    ->post("{$baseUrl}/api/swap", $payload);
            }
            return $requests;
        });

        // 6. Assert the Results
        $successCount = 0;
        $failureCount = 0;

        foreach ($responses as $response) {
            if ($response->successful()) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        // We MUST have exactly 1 success and 9 failures to prove the system is watertight.
        $this->assertEquals(1, $successCount, "There should be exactly 1 successful swap.");
        $this->assertEquals(9, $failureCount, "There should be exactly 9 rejected replays.");
    }
}
