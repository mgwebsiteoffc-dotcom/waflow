<?php
namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopifyAdmin
{
    public function request(Shop $shop, string $query, array $variables = []): array
    {
        $accessToken = $this->freshAccessToken($shop);
        $response = Http::acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->timeout(20)
            ->post("https://{$shop->shop_domain}/admin/api/" . config('services.shopify.api_version') . '/graphql.json', [
                'query' => $query,
                'variables' => $variables,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Shopify API error (' . $response->status() . '): ' . ($response->json('errors.0.message') ?? $response->body()));
        }
        $data = $response->json();
        if (!empty($data['errors'])) {
            throw new RuntimeException($data['errors'][0]['message'] ?? 'Shopify GraphQL error');
        }
        return $data['data'] ?? [];
    }

    /** Refresh shortly before expiry. Refresh-token rotation is serialized per shop. */
    public function refreshIfNeeded(Shop $shop, int $bufferSeconds = 300): bool
    {
        return DB::transaction(function () use ($shop, $bufferSeconds) {
            $locked = Shop::query()->lockForUpdate()->findOrFail($shop->id);
            if ($locked->access_token_expires_at && $locked->access_token_expires_at->gt(now()->addSeconds($bufferSeconds))) {
                return false;
            }
            $legacyMigration = !$locked->refresh_token;
            if ($legacyMigration && (!$locked->access_token || $locked->access_token_expires_at)) {
                throw new RuntimeException('Shopify credentials cannot be refreshed. Re-authorize the app to continue.');
            }
            if (!$legacyMigration && $locked->refresh_token_expires_at && $locked->refresh_token_expires_at->lte(now())) {
                throw new RuntimeException('Shopify refresh token has expired. Re-authorize the app to continue.');
            }

            $credentials = [
                'client_id' => config('services.shopify.api_key'),
                'client_secret' => config('services.shopify.api_secret'),
            ];
            if ($legacyMigration) {
                // Migrate a still-valid, non-expiring offline token in place; Shopify retires it only on success.
                $credentials += [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                    'subject_token' => $locked->access_token,
                    'subject_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
                    'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
                    'expiring' => 1,
                ];
            } else {
                $credentials += ['grant_type' => 'refresh_token','refresh_token' => $locked->refresh_token];
            }
            $response = Http::asForm()->acceptJson()->timeout(20)
                ->post("https://{$locked->shop_domain}/admin/oauth/access_token", $credentials);

            if (!$response->successful() || !$response->json('access_token') || !$response->json('refresh_token') || !$response->json('expires_in') || !$response->json('refresh_token_expires_in')) {
                throw new RuntimeException('Shopify offline-token refresh failed (' . $response->status() . '). Re-authorize the app if the refresh token has expired or been revoked.');
            }

            $body = $response->json();
            $locked->access_token = $body['access_token'];
            $locked->refresh_token = $body['refresh_token'];
            $locked->access_token_expires_at = now()->addSeconds((int) ($body['expires_in'] ?? 0));
            $locked->refresh_token_expires_at = isset($body['refresh_token_expires_in'])
                ? now()->addSeconds((int) $body['refresh_token_expires_in'])
                : $locked->refresh_token_expires_at;
            $locked->save();
            return true;
        }, 1);
    }

    public function freshAccessToken(Shop $shop): string
    {
        $this->refreshIfNeeded($shop);
        $token = Shop::query()->findOrFail($shop->id)->access_token;
        if (!$token) {
            throw new RuntimeException('This Shopify store has no active access token.');
        }
        return $token;
    }

    public function addOrderTag(Shop $shop, string $gid, string $tag): array
    {
        return $this->request($shop, 'mutation TagsAdd($id: ID!, $tags: [String!]!) { tagsAdd(id: $id, tags: $tags) { node { id } userErrors { field message } } }', ['id' => $gid, 'tags' => [$tag]]);
    }
}
