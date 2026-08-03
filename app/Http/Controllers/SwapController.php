<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SwapService;

class SwapController extends Controller
{
    public function __construct(private SwapService $swapService) {}

    public function execute(Request $request)
    {
        $request->validate([
            'source_wallet_id' => 'required|integer',
            'dest_wallet_id' => 'required|integer',
            'amount_kobo' => 'required|integer|min:1' // Must be positive integer
        ]);

        try {
            $result = $this->swapService->executeSwap(
                $request->user()->id,
                $request->source_wallet_id,
                $request->dest_wallet_id,
                (string) $request->amount_kobo // Pass as string for BCMath
            );

            return response()->json([
                'message' => 'Swap executed successfully',
                'data' => $result
            ]);
        } catch (\RuntimeException $e) {
            // Catch domain exceptions (e.g., Insufficient funds)
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            // Catch lock timeouts, DB constraints, etc
            return response()->json(['message' => 'Conflict processing request. Try again.'], 409);
        }
    }
}
