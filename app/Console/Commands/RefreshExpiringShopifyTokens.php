<?php
namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\ShopifyAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshExpiringShopifyTokens extends Command
{
    protected $signature = 'whatify:refresh-shopify-tokens';
    protected $description = 'Rotate expiring Shopify offline access and refresh tokens';

    public function handle(ShopifyAdmin $admin): int
    {
        $failed = 0;
        Shop::query()->where('installed', true)->whereNotNull('access_token')
            ->where(function ($query) {
                $query->whereNull('access_token_expires_at')
                    ->orWhere('access_token_expires_at', '<=', now()->addMinutes(20));
            })->orderBy('id')->chunkById(100, function ($shops) use ($admin, &$failed) {
                foreach ($shops as $shop) {
                    try {
                        $admin->refreshIfNeeded($shop, 1200);
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::error('Scheduled Shopify token rotation failed.', ['shop_id' => $shop->id, 'message' => $e->getMessage()]);
                        $this->error("Shop {$shop->shop_domain}: token refresh failed; re-authorization may be required.");
                    }
                }
            });
        $this->info('Shopify token rotation scan complete; failures: ' . $failed . '.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
