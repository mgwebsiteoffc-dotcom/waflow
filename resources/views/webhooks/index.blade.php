@extends('layouts.app')
@section('title','Webhook events')
@section('content')
<div class="topbar"><div><div class="eyebrow">Manage / Webhook events</div><h1 class="page-title">Shopify webhook events</h1><p class="subtitle">Every event Shopify delivered to <code>/api/webhooks/shopify</code> — use this to confirm whether syncing is reaching the app.</p></div>
<span class="datepill">{{ number_format($totals['all']) }} total · {{ number_format($totals['errors']) }} failed · {{ number_format($totals['pending']) }} pending</span></div>

@if($totals['all'] === 0)
<div class="panel" style="border-color:#f0e4ff;background:linear-gradient(135deg,#f8f5ff,#fff)">
 <div class="paneltitle">No webhooks have ever arrived</div>
 <p class="confighint" style="font-size:12px;line-height:1.8;margin-top:8px">
  Shopify is not delivering events to this server yet. Current status on this server:
 </p>
 <ul class="diag-list" style="font-size:12px">
  <li>{!! $readiness['credentials'] ? '<span style="color:#1ca66a">✓</span>' : '<span style="color:#bf6664">✗</span>' !!} Shopify API credentials configured in the server environment (<code>SHOPIFY_API_KEY</code> / <code>SHOPIFY_API_SECRET</code>){!! $readiness['credentials'] ? '' : ' — add them to <code>.env</code> and run <code>php artisan config:clear</code>' !!}</li>
  <li>{!! $readiness['store_installed'] ? '<span style="color:#1ca66a">✓</span>' : '<span style="color:#bf6664">✗</span>' !!} Store connected with a stored offline token{!! $readiness['store_installed'] ? '' : ' — run the Shopify connect flow on the Integrations page' !!}</li>
  <li><span style="color:#888">○</span> TOML webhook subscriptions deployed — cannot be detected from here; verify with the steps below</li>
  <li><span style="color:#888">○</span> Public HTTPS endpoint reachable: <code>{{ $readiness['endpoint'] }}</code></li>
 </ul>
 <p class="confighint" style="font-size:12px;line-height:1.8;margin-top:10px">On the Shopify side you must:</p>
 <ol style="margin:8px 0 0;padding-left:20px;font-size:12px;color:#5d5c70;line-height:2">
  <li>The real client ID is already committed in <code>shopify.app.toml</code> and <code>shopify.app.whatify-flow.toml</code> (both files also list the webhook subscriptions).</li>
  <li>From the project folder run <code>shopify app config link</code> then <code>shopify app deploy</code> so the subscriptions (orders/create, fulfillments/create, checkouts, uninstall, compliance) are registered.</li>
  <li>Send a test delivery with <code>shopify app webhook trigger --topic orders/create</code> and reload this page — a row should appear within seconds. Alternatively place a test order on the store.</li>
  <li>If the test never arrives, check Partner Dashboard → your app → Configuration → Webhooks, and confirm this server is publicly reachable over HTTPS at <code>{{ $readiness['endpoint'] }}</code> (requests are signature-verified, so unsigned hits are rejected).</li>
  <li>Keep <code>php artisan queue:work</code> running — delivered events sit in "Queued / waiting for worker" until it processes them.</li>
 </ol>
 <div class="formrow" style="margin-top:14px"><a class="primary" href="{{ route('integrations.index') }}" style="text-decoration:none">Open integrations</a><a class="secondary" href="{{ route('orders.index') }}" style="text-decoration:none">Orders &amp; manual sync</a></div>
</div>
@else
<div class="panel"><div class="panelhead"><div><div class="paneltitle">Latest delivered events</div><div class="panelhint">Processed events have their raw payload cleared; failed events keep the error for 7 days of retention.</div></div></div>
<div class="tablewrap"><table class="orders"><thead><tr><th>RECEIVED</th><th>TOPIC</th><th>SHOPIFY WEBHOOK ID</th><th>STATE</th><th>ERROR</th></tr></thead><tbody>
@forelse($events as $event)
<tr>
 <td>{{ $event->created_at->diffForHumans() }}</td>
 <td><span class="pill blue">{{ str_replace('_',' ', $event->topic) }}</span></td>
 <td class="ordid">{{ $event->webhook_id }}</td>
 <td>
  @if($event->processed_at)<span class="badge b-green">Processed</span>
  @elseif($event->processing_error)<span class="badge b-red">Failed</span>
  @else<span class="badge b-amber">Queued / waiting for worker</span>@endif
 </td>
 <td style="max-width:340px;white-space:normal;color:#a05b59">{{ $event->processing_error ?: '—' }}</td>
</tr>
@empty<tr><td colspan="5" class="empty-state">No webhook events match.</td></tr>@endforelse
</tbody></table></div>{{ $events->links() }}</div>
@endif
@endsection
