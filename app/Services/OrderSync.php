<?php

namespace App\Services;

use App\Models\AbandonedCheckout;
use App\Models\OrderVerification;
use App\Models\Shop;
use App\Models\WhatsAppConsent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Ingests Shopify orders into local verification rows.
 *
 * Used by the ORDERS_CREATE webhook job and by the manual/backfill GraphQL
 * sync. Manual sync deliberately does NOT start WhatsApp workflows, so
 * historical orders never trigger messages to old customers.
 */
class OrderSync
{
    public function __construct(private ShopifyAdmin $admin) {}

    /**
     * Pull recent orders through the GraphQL Admin API and ingest them.
     *
     * @return array{synced: int, skipped: int, shop: string}
     */
    public function syncRecentOrders(Shop $shop, int $limit = 25): array
    {
        $limit = max(1, min(50, $limit));
        try {
            $data = $this->admin->request($shop, <<<'GRAPHQL'
            query RecentOrders($first: Int!) {
              orders(first: $first, sortKey: CREATED_AT, reverse: true) {
                edges { node {
                  id name createdAt checkoutToken
                  paymentGatewayNames tags
                  totalPriceSet { shopMoney { amount currencyCode } }
                  customAttributes { key value }
                  customer { id firstName lastName phone }
                } }
              }
            }
            GRAPHQL, ['first' => $limit]);
        } catch (\Throwable $e) {
            throw new RuntimeException($this->translateOrderError($e), 0, $e);
        }

        $edges = $data['orders']['edges'] ?? [];
        $synced = 0;
        foreach ($edges as $edge) {
            $order = $this->graphqlToRestShape($edge['node'] ?? []);
            if (!$order['id']) {
                continue;
            }
            $this->ingestOrder($shop, $order);
            $synced++;
        }

        return ['synced' => $synced, 'skipped' => count($edges) - $synced, 'shop' => $shop->shop_domain];
    }

    /**
     * Verify the stored Shopify credentials with a lightweight shop query.
     *
     * @return array{name: string, domain: string, email: ?string}
     */
    public function testConnection(Shop $shop): array
    {
        $data = $this->admin->request($shop, 'query { shop { name myshopifyDomain email } }');
        $shopNode = $data['shop'] ?? null;
        if (!$shopNode) {
            throw new RuntimeException('Shopify returned no shop information.');
        }
        return [
            'name' => $shopNode['name'] ?? $shop->shop_domain,
            'domain' => $shopNode['myshopifyDomain'] ?? $shop->shop_domain,
            'email' => $shopNode['email'] ?? null,
        ];
    }

    /**
     * Probe whether Shopify has approved this app's access to the protected
     * Order object. Cached for five minutes; the lightweight shop query used
     * by testConnection passes even when Order access is blocked.
     *
     * @return array{ok: bool, error: ?string, protected?: bool, scope?: bool, auth?: bool}
     */
    public function protectedDataAccess(Shop $shop): array
    {
        return Cache::remember('shopify.protected_data.'.$shop->id, now()->addMinutes(5), function () use ($shop) {
            try {
                $this->admin->request($shop, 'query { orders(first: 1) { edges { node { id } } } }');
                return ['ok' => true, 'error' => null];
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'error' => $this->translateOrderError($e),
                    'protected' => $this->isProtectedDataError($e),
                    'scope' => $this->isMissingScopeError($e),
                    'auth' => $this->isAuthError($e),
                ];
            }
        });
    }

    public function isProtectedDataError(\Throwable $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, 'not approved to access the Order object')
            || str_contains($message, 'protected customer data');
    }

    public function isMissingScopeError(\Throwable $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, 'Required access:')
            || str_contains($message, 'access scope')
            || str_contains($message, 'Access denied for customer field');
    }

    public function isAuthError(\Throwable $e): bool
    {
        return self::classifySyncError($e->getMessage()) === 'auth';
    }

    /**
     * Single classification used by both the API layer and the Orders page panel.
     * Returns one of: scope, auth, protected, other.
     */
    public static function classifySyncError(string $message): string
    {
        if (str_contains($message, 'Required access:')
            || str_contains($message, 'access scope')
            || str_contains($message, 'Access denied for customer field')) {
            return 'scope';
        }
        if (str_contains($message, 'offline-token refresh failed')
            || str_contains($message, 'credentials cannot be refreshed')
            || str_contains($message, 'refresh token has expired')
            || str_contains($message, 'no active access token')
            || str_contains($message, 'Re-authorize')) {
            return 'auth';
        }
        if (str_contains($message, 'not approved to access the Order object')
            || str_contains($message, 'protected customer data')) {
            return 'protected';
        }
        return 'other';
    }

    public function translateOrderError(\Throwable $e): string
    {
        if ($this->isMissingScopeError($e)) {
            return $this->scopeErrorMessage($e);
        }
        if ($this->isAuthError($e)) {
            return $this->authErrorMessage($e);
        }
        if ($this->isProtectedDataError($e)) {
            return $this->protectedDataMessage($e);
        }
        return $e->getMessage();
    }

    public function authErrorMessage(\Throwable $e): string
    {
        if (!$this->isAuthError($e)) {
            return $e->getMessage();
        }
        return 'Shopify rejected the stored app credentials: the access token expired and the refresh token was revoked or is no longer valid. '
            .'Fix: open the Integrations page and run the Shopify connect flow again (or open /integrations/shopify/install?shop=your-store.myshopify.com) to store a fresh access and refresh token, then run Sync recent orders. '
            .'If more than one server or database shares this app installation, only the newest refresh token is valid — re-authorize from the live server and remove stale copies. '
            .'Re-authorize the app if the refresh token has expired or been revoked. Raw Shopify error: '.$e->getMessage();
    }

    public function scopeErrorMessage(\Throwable $e): string
    {
        if (!$this->isMissingScopeError($e)) {
            return $e->getMessage();
        }
        return 'Shopify rejected the request because the installed app is missing an API access scope. '
            .'Fix: open shopify.app.toml and make sure [access_scopes] lists read_orders, write_orders, read_customers and read_fulfillments, then run shopify app config link && shopify app deploy. '
            .'Then uninstall and reinstall the app in Shopify admin so the access token is granted with the new scope. '
            .'Customer name and phone are protected customer data: the Protected customer data declaration in Partner Dashboard must also be approved. '
            .'Reference: https://shopify.dev/docs/api/usage/access-scopes. Raw Shopify error: '.$e->getMessage();
    }

    public function protectedDataMessage(\Throwable $e): string
    {
        if (!$this->isProtectedDataError($e)) {
            return $e->getMessage();
        }
        return 'Shopify has not approved this app for protected customer data (Order object), so order sync and order webhooks are blocked. '
            .'Fix in Partner Dashboard: open the app → App setup → Protected customer data → confirm the data types and purposes (orders, customers, checkouts, phone numbers), save, then submit for approval. '
            .'For immediate testing without approval, install the app on a Shopify development store and redeploy the configuration (shopify app config link && shopify app deploy). '
            .'After approval, reinstall the app so the access token includes the approved scopes. Reference: https://shopify.dev/docs/apps/launch/protected-customer-data';
    }

    /**
     * Full non-messaging ingest for one REST-shaped order payload:
     * explicit opt-in recording, COD verification row and checkout recovery.
     */
    public function ingestOrder(Shop $shop, array $order): void
    {
        $this->upsertVerification($shop, $order);
        $this->markCheckoutRecovered($shop, $order);
    }

    private function graphqlToRestShape(array $node): array
    {
        $customer = $node['customer'] ?? [];
        $money = $node['totalPriceSet']['shopMoney'] ?? [];
        return [
            'id' => $this->numericId((string) ($node['id'] ?? '')),
            'name' => $node['name'] ?? null,
            'created_at' => $node['createdAt'] ?? null,
            'checkout_token' => $node['checkoutToken'] ?? null,
            'payment_gateway_names' => $node['paymentGatewayNames'] ?? [],
            'tags' => is_array($node['tags'] ?? null) ? implode(', ', $node['tags']) : (string) ($node['tags'] ?? ''),
            'total_price' => $money['amount'] ?? null,
            'currency' => $money['currencyCode'] ?? null,
            'note_attributes' => array_map(
                fn($attr) => ['name' => $attr['key'] ?? '', 'value' => $attr['value'] ?? ''],
                $node['customAttributes'] ?? []
            ),
            'customer' => [
                'id' => $this->numericId((string) ($customer['id'] ?? '')),
                'first_name' => $customer['firstName'] ?? '',
                'last_name' => $customer['lastName'] ?? '',
                'phone' => $customer['phone'] ?? null,
            ],
        ];
    }

    private function upsertVerification(Shop $shop, array $order): void
    {
        $orderId = $this->numericId((string) ($order['id'] ?? ''));
        if (!$orderId) {
            return;
        }
        $phone = PhoneIdentity::normalize($order['customer']['phone'] ?? $order['phone'] ?? '');
        $this->recordExplicitOptIn($shop, $order, $phone);
        $gateways = $order['payment_gateway_names'] ?? [];
        $payment = implode(', ', array_map('strval', is_array($gateways) ? $gateways : [$gateways]));
        $low = strtolower($payment);
        $isCod = str_contains($low, 'cash on delivery') || (bool) preg_match('/\bcod\b/', $low);
        $reasons = [];
        $score = 0;
        $total = (float) ($order['total_price'] ?? 0);
        $customer = $order['customer'] ?? [];
        $hash = PhoneIdentity::hash($phone);
        $hasConsent = $hash && $shop->whatsappConsents()->active()->where('phone_hash', $hash)->exists();

        if ($isCod && !$phone) { $score += 60; $reasons[] = 'No valid phone number on order'; }
        if ($isCod && $phone && !$hasConsent) $reasons[] = 'No recorded WhatsApp opt-in; no message sent';
        $threshold = (float) ($shop->settings['high_value_review_threshold'] ?? 50000);
        $highValue = $isCod && $total >= $threshold && $threshold > 0;
        if ($highValue) { $score += 25; $reasons[] = 'Order value exceeds configured review threshold'; }
        $status = !$isCod ? 'not_required' : ((!$phone || $score >= 60 || $highValue) ? 'review' : (!$hasConsent ? 'not_contacted' : 'pending'));

        OrderVerification::updateOrCreate(['shop_id' => $shop->id, 'shopify_order_id' => $orderId], [
            'shopify_customer_id' => isset($customer['id']) ? (string) $this->numericId((string) $customer['id']) : null,
            'order_name' => $order['name'] ?? null,
            'customer_name' => trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? '')) ?: null,
            'customer_phone' => $phone ?: null,
            'customer_phone_hash' => $hash,
            'payment_method' => $payment ?: 'unknown',
            'order_total' => $total,
            'currency' => $order['currency'] ?? null,
            'status' => $status,
            'risk_score' => min(100, $score),
            'risk_reasons' => $reasons,
        ]);
    }

    private function recordExplicitOptIn(Shop $shop, array $order, string $phone): void
    {
        $hash = PhoneIdentity::hash($phone);
        if (!$hash) {
            return;
        }
        $attributes = $order['note_attributes'] ?? [];
        $source = null;
        foreach ($attributes as $attribute) {
            $key = strtolower(trim((string) ($attribute['name'] ?? $attribute['key'] ?? '')));
            $value = strtolower(trim((string) ($attribute['value'] ?? '')));
            if (in_array($key, ['whatsapp_opt_in', 'whatsapp_consent', 'wa_opt_in'], true) && in_array($value, ['yes', 'true', '1', 'on', 'opted_in', 'accepted'], true)) {
                $source = 'shopify_order_note_attribute:'.$key;
                break;
            }
        }
        if (!$source) {
            $tags = array_map('trim', explode(',', strtolower((string) ($order['tags'] ?? ''))));
            if (in_array('whatsapp_opt_in', $tags, true)) $source = 'merchant_tag:whatsapp_opt_in';
        }
        if (!$source) {
            return;
        }
        $customerId = $this->numericId((string) ($order['customer']['id'] ?? ''));
        $orderId = $this->numericId((string) ($order['id'] ?? ''));
        $consent = $shop->whatsappConsents()->where('phone_hash', $hash)->first();
        $orderCreatedAt = null;
        if (!empty($order['created_at'])) {
            try { $orderCreatedAt = Carbon::parse($order['created_at']); } catch (\Throwable) { $orderCreatedAt = null; }
        }
        $mayReOptIn = $consent?->status !== 'opted_out'
            || ($consent->opted_out_at && $orderCreatedAt && $orderCreatedAt->gt($consent->opted_out_at));
        if (!$mayReOptIn) {
            return;
        }
        $values = [
            'shopify_customer_id' => $customerId ?: null,
            'phone' => $phone,
            'status' => 'opted_in',
            'source' => $source,
            'source_reference' => ($order['name'] ?? null) ?: ($orderId ? 'order:'.$orderId : null),
            'consented_at' => $consent?->status === 'opted_in' ? ($consent->consented_at ?? $orderCreatedAt ?? now()) : ($orderCreatedAt ?? now()),
            'opted_out_at' => null,
        ];
        if ($consent) $consent->update($values);
        else $shop->whatsappConsents()->create(['phone_hash' => $hash] + $values);
    }

    private function markCheckoutRecovered(Shop $shop, array $order): void
    {
        $tokenHash = CheckoutIdentity::hash((string) ($order['checkout_token'] ?? ''));
        if (!$tokenHash) {
            return;
        }
        $checkout = AbandonedCheckout::firstOrCreate(
            ['shop_id' => $shop->id, 'checkout_token_hash' => $tokenHash],
            ['status' => 'recovered', 'completed_at' => now()]
        );
        $checkout->update(['status' => 'recovered', 'completed_at' => $checkout->completed_at ?? now(), 'recovery_url' => null, 'item_summary' => null, 'customer_first_name' => null, 'phone' => null, 'abandoned_at' => null]);
        if ($checkout->creation_webhook_event_id) {
            $shop->executions()->where('webhook_event_id', $checkout->creation_webhook_event_id)->whereIn('status', ['queued', 'running', 'waiting'])->get()->each(function ($execution) use ($checkout) {
                $execution->update(['status' => 'cancelled', 'finished_at' => now(), 'last_error' => 'Checkout completed before the recovery workflow finished.', 'trigger_payload' => ['_whatify' => ['abandoned_checkout_id' => $checkout->id, 'recovered' => true]]]);
            });
        }
    }

    private function numericId(string $id): ?string
    {
        if (preg_match('/(\d+)$/', $id, $match)) return $match[1];
        return null;
    }
}
