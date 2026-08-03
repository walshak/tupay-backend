<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SwapService
{
    public function __construct(private ExchangeRateService $rateService) {}

    /**
     * @return array<string, mixed>
     */
    public function executeSwap(int $userId, int $sourceWalletId, int $destWalletId, string $amountKobo): array
    {
        // 1. Sort locks alphabetically to guarantee deadlock prevention across servers
        $lockKeys = [
            "lock:user:{$userId}",
            "lock:wallet:{$sourceWalletId}",
            "lock:wallet:{$destWalletId}"
        ];
        sort($lockKeys);

        $locks = [];
        try {
            // Acquire all Redis locks sequentially, block for up to 5 seconds
            foreach ($lockKeys as $key) {
                $lock = Cache::lock($key, 10);
                $lock->block(5);
                $locks[] = $lock;
            }


            // 2. Open SQL Transaction with REPEATABLE READ isolation level
            DB::unprepared('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::beginTransaction();

            try {
                // 3. Pessimistic Row-Level Lock on the wallets (SELECT ... FOR UPDATE)
                $wallets = Wallet::whereIn('id', [$sourceWalletId, $destWalletId])
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $sourceWallet = $wallets[$sourceWalletId] ?? null;
                $destWallet = $wallets[$destWalletId] ?? null;

                if (!$sourceWallet || !$destWallet) {
                    throw new RuntimeException("One or both wallets not found.");
                }

                // Ensure user actually owns these wallets
                if ($sourceWallet->user_id !== $userId || $destWallet->user_id !== $userId) {
                    throw new RuntimeException("Wallet ownership validation failed.");
                }

                // Balance validation against the indexed DB attribute we built in Step 1
                if ($sourceWallet->balance < (int) $amountKobo) {
                    throw new RuntimeException("Insufficient funds.");
                }

                // 4. Math Precision & Dynamic Slippage using BCMath
                $rate = $this->rateService->getRate($sourceWallet->currency, $destWallet->currency);
                $feePercentage = $this->calculateSlippageFee($amountKobo);

                // bcmath defaults to 0 scale, we set it high for calculations
                bcscale(10);

                // Deduct fee from source amount: amount - (amount * fee)
                $feeAmount = bcmul($amountKobo, $feePercentage);
                $amountAfterFee = bcsub($amountKobo, $feeAmount);

                // Convert to destination currency: amountAfterFee * rate
                $destAmountRaw = bcmul($amountAfterFee, $rate);

                // Banker's Rounding (ROUND_HALF_EVEN) back to strict integers
                $finalDestAmountSubunit = (int) round((float) $destAmountRaw, 0, PHP_ROUND_HALF_EVEN);

                // Insert Double-Entry Records (A transaction reference binds them)
                $txRef = Str::uuid();

                // Leg 1: Debit the source wallet
                LedgerEntry::create([
                    'transaction_reference' => $txRef,
                    'wallet_id' => $sourceWallet->id,
                    'type' => 'debit',
                    'amount' => (int) $amountKobo,
                    'description' => "Swap to {$destWallet->currency}"
                ]);

                // Leg 2: Credit the destination wallet
                LedgerEntry::create([
                    'transaction_reference' => $txRef,
                    'wallet_id' => $destWallet->id,
                    'type' => 'credit',
                    'amount' => $finalDestAmountSubunit,
                    'description' => "Swap from {$sourceWallet->currency}"
                ]);

                // NOTE: If you wanted strict zero-sum double-entry, you'd also create a 
                // "Fee Wallet" and credit the $feeAmount to it, ensuring debits = credits exactly.

                DB::commit();

                return [
                    'transaction_reference' => $txRef,
                    'debited' => (int) $amountKobo,
                    'credited' => $finalDestAmountSubunit,
                    'fee_kobo' => (int) round((float) $feeAmount, 0, PHP_ROUND_HALF_EVEN)
                ];
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } finally {
            // 5. Always release Redis locks in reverse order
            foreach (array_reverse($locks) as $lock) {
                if ($lock instanceof \Illuminate\Contracts\Cache\Lock) {
                    $lock->release();
                }
            }
        }
    }

    private function calculateSlippageFee(string $amountKobo): string
    {
        // Threshold: 1,000,000 NGN = 100,000,000 kobo
        $threshold = '100000000';
        $stepSize = '50000000'; // 500,000 NGN = 50,000,000 kobo

        bcscale(10);

        if (bccomp($amountKobo, $threshold) <= 0) {
            return '0.0000'; // 0% fee if under threshold
        }

        $baseFee = '0.005'; // 0.5%

        $excess = bcsub($amountKobo, $threshold);
        $additionalSteps = bcdiv($excess, $stepSize, 0); // floor division

        // extra fee = additionalSteps * 0.001 (0.1%)
        $extraFee = bcmul($additionalSteps, '0.001');

        return bcadd($baseFee, $extraFee);
    }
}
