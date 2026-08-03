<?php

namespace App\Jobs;

use App\Models\Settlement;
use App\Models\LedgerEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessSettlementWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $payload) {}

    public function handle(): void
    {
        $reference = $this->payload['provider_reference'];
        $newStatus = $this->payload['status']; // INITIATED, COMPLETED, or FAILED
        $walletId = $this->payload['wallet_id'];
        $amount = $this->payload['amount'];

        //lets get an atomic Redis Lock to prevent duplicate concurrent webhooks
        $lock = Cache::lock("webhook_processing:{$reference}", 10);

        if (!$lock->get()) {
            //Another worker is currently processing this exact webhook. Safely abort.
            return;
        }

        try {
            DB::transaction(function () use ($reference, $newStatus, $walletId, $amount) {

                // Use lockForUpdate to prevent race conditions during DB read
                $settlement = Settlement::where('provider_reference', $reference)
                    ->lockForUpdate()
                    ->first();

                // If this is the first time we've seen this webhook
                if (!$settlement) {
                    $settlement = Settlement::create([
                        'provider_reference' => $reference,
                        'wallet_id' => $walletId,
                        'amount' => $amount,
                        'status' => $newStatus,
                    ]);
                } else {
                    // Out-of-Order Resiliency (State Machine)
                    // If it's already COMPLETED or FAILED, an INITIATED hook arriving late is useless.
                    if (in_array($settlement->status, ['COMPLETED', 'FAILED'])) {
                        return; // Idempotent exit
                    }

                    $settlement->update(['status' => $newStatus]);
                }

                // If it just became COMPLETED, we must credit the user's ledger!
                if ($newStatus === 'COMPLETED') {

                    // We must check if we already credited this to prevent double-crediting.
                    // We can check if a ledger entry already exists with this reference.
                    $exists = LedgerEntry::where('transaction_reference', $reference)->exists();

                    if (!$exists) {
                        // Double-Entry logic: 
                        // Note: For a true balanced system, we need a "System Master Wallet" (ID 1)
                        // to debit from, while crediting the user's wallet.
                        $systemWalletId = 1;

                        LedgerEntry::create([
                            'transaction_reference' => $reference,
                            'wallet_id' => $systemWalletId,
                            'type' => 'debit',
                            'amount' => $amount,
                            'description' => "Settlement Debit"
                        ]);

                        LedgerEntry::create([
                            'transaction_reference' => $reference,
                            'wallet_id' => $walletId,
                            'type' => 'credit',
                            'amount' => $amount,
                            'description' => "Settlement Credit"
                        ]);
                    }
                }
            });
        } finally {
            $lock->release();
        }
    }
}
