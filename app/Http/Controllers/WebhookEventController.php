<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use Illuminate\Http\Request;

class WebhookEventController extends Controller
{
    public function index(Request $r)
    {
        $shop = $r->attributes->get('currentShop');
        $events = $shop->webhookEvents()
            ->latest()
            ->paginate(25)
            ->withQueryString();
        $totals = [
            'all' => (clone $shop->webhookEvents())->count(),
            'pending' => (clone $shop->webhookEvents())->whereNull('processed_at')->whereNull('processing_error')->count(),
            'errors' => (clone $shop->webhookEvents())->whereNotNull('processing_error')->count(),
        ];
        $readiness = [
            'credentials' => (bool) config('services.shopify.api_key') && (bool) config('services.shopify.api_secret'),
            'store_installed' => (bool) ($shop?->installed && $shop?->access_token),
            'endpoint' => rtrim((string) config('app.url'), '/').'/api/webhooks/shopify',
            'client_id' => (string) config('services.shopify.api_key'),
        ];
        return view('webhooks.index', compact('events', 'totals', 'readiness'));
    }
}
