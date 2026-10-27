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
        return view('webhooks.index', compact('events', 'totals'));
    }
}
