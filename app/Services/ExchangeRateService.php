<?php

namespace App\Services;

use App\Jobs\FetchExchangeRateJob;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;

class ExchangeRateService
{
    public function getRate(string $from, string $to): string
    {
        $cacheKey = "rates:{$from}:{$to}";
        $timestampKey = "rates:{$from}:{$to}:timestamp";
        $rate = Redis::get($cacheKey);
        $timestamp = Redis::get($timestampKey);
        // If we have no rate at all, we MUST fetch synchronously
        if (!$rate) {
            return $this->fetchAndCacheRate($from, $to);
        }
        // If the rate is older than 60 seconds, it's "stale".
        if (time() - $timestamp > 60) {
            Redis::set($timestampKey, time());
            FetchExchangeRateJob::dispatch($from, $to);
        }
        return $rate;
    }


    public function fetchAndCacheRate(string $from, string $to): string
    {
        // getting real rates from the public open API
        $response = Http::get("https://open.er-api.com/v6/latest/{$from}");
        if (!$response->successful()) {
            throw new \RuntimeException("Failed to fetch exchange rates.");
        }
        $rateFloat = $response->json("rates.{$to}"); // get the specific rate for the target currency CNY
        if (!$rateFloat) {
            throw new \RuntimeException("Currency {$to} not supported by the provider.");
        }
        $rate = (string) $rateFloat; // the API returns a float, which is convert to string for BCMath
        // cache the fresh rate and timestamp
        Redis::set("rates:{$from}:{$to}", $rate);
        Redis::set("rates:{$from}:{$to}:timestamp", time());
        return $rate;
    }
}
