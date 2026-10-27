<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('whatify:prune')->dailyAt('03:20');
Schedule::command('whatify:refresh-shopify-tokens')->everyThirtyMinutes()->withoutOverlapping();
