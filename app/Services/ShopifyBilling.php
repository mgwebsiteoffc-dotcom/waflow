<?php
namespace App\Services;
use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
class ShopifyBilling {
 public function __construct(private ShopifyAdmin $admin) {}

 public const CREATE_ONE_TIME = <<<'GRAPHQL'
mutation AppPurchaseOneTimeCreate($name: String!, $returnUrl: URL!, $price: MoneyInput!, $test: Boolean) {
 appPurchaseOneTimeCreate(name: $name, returnUrl: $returnUrl, price: $price, test: $test) {
  confirmationUrl
  appPurchaseOneTime { id }
  userErrors { field message }
 }
}
GRAPHQL;

 public const PURCHASES_QUERY = <<<'GRAPHQL'
query OneTimePurchases {
 currentAppInstallation {
  oneTimePurchases(first: 25) {
   nodes { id name status price { amount currencyCode } createdAt }
  }
 }
}
GRAPHQL;

 /**
  * Creates a one-time charge and returns the Shopify-hosted confirmation URL.
  * @return array{confirmation_url:string, charge_id:?string}
  */
 public function createCharge(Shop $shop, string $returnUrl): array {
  if ($this->driver() === 'local') {
   // Development simulation: no Shopify API call, confirmation is an internal callback.
   return [
    'confirmation_url' => route('billing.callback', ['simulated' => '1'], absolute: true),
    'charge_id' => 'gid://local/AppPurchaseOneTime/'.\Illuminate\Support\Str::random(16),
   ];
  }
  $variables = [
   'name' => (string) config('services.shopify.billing_name'),
   'returnUrl' => $returnUrl,
   'price' => ['amount' => (string) config('services.shopify.one_time_price'), 'currencyCode' => (string) config('services.shopify.billing_currency')],
   'test' => (bool) config('services.shopify.billing_test_mode'),
  ];
  $data = $this->admin->request($shop, self::CREATE_ONE_TIME, $variables);
  $payload = $data['appPurchaseOneTimeCreate'] ?? null;
  if (!$payload) throw new RuntimeException('Shopify did not return a billing response.');
  $errors = $payload['userErrors'] ?? [];
  if ($errors) throw new RuntimeException($errors[0]['message'] ?? 'Shopify rejected the charge request.');
  if (empty($payload['confirmationUrl'])) throw new RuntimeException('Shopify did not return a confirmation URL.');
  return [
   'confirmation_url' => (string) $payload['confirmationUrl'],
   'charge_id' => $payload['appPurchaseOneTime']['id'] ?? null,
  ];
 }

 /**
  * Queries the shop's one-time purchases. Returns true when an ACTIVE purchase exists.
  */
 public function verifyPayment(Shop $shop, bool $forceRefresh = false): bool {
  if ($this->driver() === 'local') {
   // Development simulation driver: the internal callback is the proof of "approval".
   Cache::forget('billing.active.'.$shop->id);
   $shop->update([
    'billing_status' => 'active',
    'billing_charge_id' => $shop->billing_charge_id ?? 'gid://local/AppPurchaseOneTime/'.\Illuminate\Support\Str::random(16),
    'billing_amount' => $shop->billing_amount ?? config('services.shopify.one_time_price'),
    'billing_currency' => $shop->billing_currency ?? config('services.shopify.billing_currency'),
    'billing_confirmed_at' => $shop->billing_confirmed_at ?? now(),
   ]);
   Cache::put('billing.active.'.$shop->id, true, now()->addMinutes(5));
   return true;
  }
  $cacheKey = 'billing.active.'.$shop->id;
  if (!$forceRefresh) {
   $cached = Cache::get($cacheKey);
   if ($cached !== null) return (bool) $cached;
  }
  $data = $this->admin->request($shop, self::PURCHASES_QUERY);
  $nodes = $data['currentAppInstallation']['oneTimePurchases']['nodes'] ?? [];
  $active = collect($nodes)->first(fn($p) => ($p['status'] ?? null) === 'ACTIVE');
  $paid = (bool) $active;
  Cache::put($cacheKey, $paid, now()->addMinutes(5));
  if ($paid) {
   $shop->update([
    'billing_status' => 'active',
    'billing_charge_id' => $active['id'] ?? $shop->billing_charge_id,
    'billing_amount' => $active['price']['amount'] ?? $shop->billing_amount,
    'billing_currency' => $active['price']['currencyCode'] ?? $shop->billing_currency,
    'billing_confirmed_at' => $shop->billing_confirmed_at ?? now(),
   ]);
  }
  return $paid;
 }

 private function driver(): string {
  return config('services.shopify.billing_driver') === 'local' ? 'local' : 'shopify';
 }

 public function isPaid(Shop $shop): bool {
  if ($shop->billing_status === 'active' && $shop->billing_confirmed_at) return true;
  // Stale row without confirmation: re-verify once against Shopify.
  try { return $this->verifyPayment($shop); } catch (\Throwable $e) { return false; }
 }
}
