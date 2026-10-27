<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAbandonedCheckout;
use App\Models\AbandonedCheckout;
use Illuminate\Console\Command;

/**
 * Safety net for abandoned-cart recovery.
 *
 * ProcessShopifyWebhook dispatches the delayed recovery job when a checkout is
 * first stored. If that delayed job is lost (queue flushed, worker downtime,
 * restart) the checkout would stay "open" forever and no reminder would ever
 * send. This scheduled sweep re-dispatches every open checkout whose inactivity
 * window has elapsed; ProcessAbandonedCheckout itself is idempotent and
 * re-validates consent, eligibility and completion before starting workflows.
 */
class RecoverStalledCheckouts extends Command
{
    protected $signature = 'whatify:recover-stalled-checkouts {--limit=200}';

    protected $description = 'Re-dispatch abandoned-cart recovery for open checkouts whose inactivity window has elapsed';

    public function handle(): int
    {
        $delayMinutes = (int) config('services.shopify.abandoned_checkout_delay_minutes', 60);
        $cutoff = now()->subMinutes($delayMinutes);

        $checkouts = AbandonedCheckout::query()
            ->where('status', 'open')
            ->where('last_activity_at', '<=', $cutoff)
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($checkouts as $checkout) {
            ProcessAbandonedCheckout::dispatch($checkout->id);
        }

        $this->info('Re-dispatched recovery for '.$checkouts->count().' stalled open checkout(s) (delay '.$delayMinutes.' minutes).');

        return self::SUCCESS;
    }
}
