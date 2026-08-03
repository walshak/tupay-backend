<?php

namespace App\Jobs;

use App\Services\ExchangeRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchExchangeRateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $from,
        public string $to
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ExchangeRateService $service): void
    {
        // This runs in the background worker
        $service->fetchAndCacheRate($this->from, $this->to);
    }
}
