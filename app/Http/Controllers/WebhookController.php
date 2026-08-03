<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSettlementWebhook;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function handle(Request $request): \Illuminate\Http\JsonResponse
    {
        //let redis take charge of heavy processing in Redis Queue
        ProcessSettlementWebhook::dispatch($request->all());
        //Respond immediately to the provider to prevent timeouts/retries of the webhook
        return response()->json(['status' => 'accepted'], 202);
    }
}
