<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function index(Request $request, string $wallet_id): \Illuminate\Http\JsonResponse
    {
        $wallet = Wallet::findOrFail($wallet_id);

        /** @var \App\Models\User $user */
        $user = $request->user();

        // Security check: Only the wallet owner can view their ledger
        if ($wallet->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized access to wallet.'], 403);
        }

        // Return fast paginated history utilizing our DB index
        $entries = LedgerEntry::where('wallet_id', $wallet->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'wallet_balance_kobo_or_fen' => $wallet->balance, // The dynamically calculated property
            'currency' => $wallet->currency,
            'ledger' => $entries
        ]);
    }
}
