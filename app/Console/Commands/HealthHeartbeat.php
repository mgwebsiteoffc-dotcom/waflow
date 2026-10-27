<?php

namespace App\Console\Commands;

use App\Support\SetupHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class HealthHeartbeat extends Command
{
    protected $signature = 'whatify:health-heartbeat';

    protected $description = 'Record a scheduler heartbeat so the automation health page can detect a stopped scheduler';

    public function handle(): int
    {
        Cache::forever(SetupHealth::HEARTBEAT_KEY, time());
        $this->info('Scheduler heartbeat recorded at '.now()->toIso8601String());

        return self::SUCCESS;
    }
}
