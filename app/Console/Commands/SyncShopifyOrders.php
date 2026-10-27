<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\OrderSync;
use Illuminate\Console\Command;

class SyncShopifyOrders extends Command
{
    protected $signature = 'whatify:sync-orders {--limit=25} {--shop= : Optionally limit to one myshopify.com domain}';

    protected $description = 'Backfill recent Shopify orders through the GraphQL Admin API (verification rows only; no WhatsApp messages are sent)';

    public function handle(OrderSync $sync): int
    {
        $query = Shop::where('installed', true);
        if ($domain = (string) $this->option('shop')) {
            $query->where('shop_domain', strtolower($domain));
        }

        $shops = $query->get();
        if ($shops->isEmpty()) {
            $this->warn('No installed Shopify shops to sync.');
            return self::SUCCESS;
        }

        foreach ($shops as $shop) {
            try {
                $result = $sync->syncRecentOrders($shop, (int) $this->option('limit'));
                $this->info("[{$shop->shop_domain}] Synced {$result['synced']} order(s), skipped {$result['skipped']}.");
            } catch (\Throwable $e) {
                $this->error("[{$shop->shop_domain}] Sync failed: ".$e->getMessage());
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
