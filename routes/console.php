<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('whatify:prune')->dailyAt('03:20');
Schedule::command('whatify:refresh-shopify-tokens')->everyThirtyMinutes()->withoutOverlapping();
// Heartbeat lets the Automation health page detect a stopped scheduler.
Schedule::command('whatify:health-heartbeat')->everyMinute()->withoutOverlapping();
// Safety net: re-dispatch recovery for open checkouts whose delayed job was lost.
Schedule::command('whatify:recover-stalled-checkouts')->everyFifteenMinutes()->withoutOverlapping();
