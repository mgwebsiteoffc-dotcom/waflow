@extends('layouts.app')
@section('title','Orders')
@push('head')<style>
.diag-list{margin:10px 0 0;padding-left:18px;color:#6f6e80;font-size:12px;line-height:1.9}
.diag-list code{background:#f4f2fb;border-radius:5px;padding:1px 6px;font-size:11px}
.diag-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px}
.webhook-meta{font-size:11px;color:#9998a5;margin-top:4px}
.webhook-meta b{color:#5d5c70;font-weight:700}
</style>@endpush
@section('content')
<div class="topbar"><div><div class="eyebrow">Workspace / Orders</div><h1 class="page-title">Order verification</h1><p class="subtitle">Review COD confirmation and explainable risk signals received from Shopify.</p></div><div class="top-actions"><form method="POST" action="{{ route('orders.sync') }}" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Syncing…'">@csrf<button class="secondary">⟳ Sync recent orders</button></form><a class="secondary" href="{{ route('webhooks.index') }}" style="text-decoration:none">Webhook events</a><span class="datepill">{{ $orders->total() }} tracked orders</span></div></div>

@if(!empty($syncError))
<div class="panel" style="margin-bottom:16px;border-color:#f5dcdb;background:#fff7f6">
 <div class="panelhead"><div><div class="paneltitle" style="color:#bf6664">Shopify blocked order access — protected customer data approval required</div><div class="panelhint">The last order sync failed. This is a Shopify-side permission gate, not a connection failure.</div></div><span class="badge b-red">Action required</span></div>
 <ul class="diag-list">
  <li><strong>1.</strong> Open <strong>Partner Dashboard → your app → App setup → Protected customer data</strong>, confirm the data types and purposes (orders, customers, checkouts, phone numbers) and save.</li>
  <li><strong>2.</strong> Submit the app for protected-customer-data approval. Until Shopify approves it, live stores cannot read the Order object via API or receive order webhook fields.</li>
  <li><strong>3.</strong> To test immediately without waiting, install on a <strong>Shopify development store</strong>, then redeploy: <code>shopify app config link</code> and <code>shopify app deploy</code>.</li>
  <li><strong>4.</strong> After approval, reinstall the app so the access token carries the approved scopes, then run <strong>Sync recent orders</strong> again.</li>
 </ul>
 <div class="notice" style="margin-bottom:0;background:#fff0ef;border-color:#f5dcdb"><span style="color:#bf6664">!</span><p style="color:#7a5554">Reference: <a href="https://shopify.dev/docs/apps/launch/protected-customer-data" target="_blank" rel="noopener">shopify.dev/docs/apps/launch/protected-customer-data</a> · Raw error: {{ $syncError }}</p></div>
</div>
@endif

@if($orders->total() === 0 && ! request('q'))
<div class="panel" style="margin-bottom:16px;border-color:#f0e4ff;background:linear-gradient(135deg,#f8f5ff,#fff)">
 <div class="panelhead"><div><div class="paneltitle">Why are no orders showing?</div><div class="panelhint">Orders appear here only after Shopify delivers an <b>orders/create</b> webhook and the queue worker processes it.</div></div></div>
 @if($webhookCount === 0)
  <div class="notice" style="margin-top:0"><span>ⓘ</span><p><strong>No Shopify webhooks have ever reached this app.</strong> This usually means the app is not fully installed or the Shopify app configuration (TOML webhook subscriptions) has not been deployed.</p></div>
  <ul class="diag-list">
   <li>Install the app from your Shopify admin and confirm the store shows <b>Connected</b> in <a href="{{ route('integrations.index') }}">Integrations</a>.</li>
   <li>Deploy the Shopify app configuration (<code>shopify app config link</code> then <code>shopify app deploy</code>) so the <code>orders/create</code> webhook subscription is registered.</li>
   <li>Keep <code>php artisan queue:work</code> running — webhooks are processed asynchronously.</li>
   <li>Confirm <strong>protected customer data</strong> is approved in Partner Dashboard (App setup → Protected customer data) — without it Shopify blocks Order access with a "not approved to access the Order object" error.</li>
   <li>Use <b>Sync recent orders</b> above to backfill existing orders immediately, or place a test order and reload.</li>
  </ul>
 @elseif(! $lastOrderWebhookAt)
  <div class="notice" style="margin-top:0"><span>ⓘ</span><p><strong>{{ number_format($webhookCount) }} webhook(s) received, but none of them are order events.</strong> Events are arriving, so the connection works — check that the <code>orders/create</code> topic is included in the deployed Shopify app configuration.</p></div>
  <ul class="diag-list">
   <li>Last event received: <b>{{ $lastWebhookAt?->diffForHumans() }}</b></li>
   <li>Verify <code>orders/create</code> is declared in <code>shopify.app.toml</code> and redeploy with the Shopify CLI.</li>
   <li>Check the <a href="{{ route('automations.health') }}">automation health page</a> for webhook processing errors.</li>
  </ul>
 @else
  <div class="notice" style="margin-top:0"><span>ⓘ</span><p><strong>Order webhooks are arriving (last one {{ $lastOrderWebhookAt->diffForHumans() }}) but no verification rows were stored.</strong> Check the queue worker and webhook processing errors on the health page.</p></div>
  <ul class="diag-list">
   <li>Ensure <code>php artisan queue:work</code> is running and not failing jobs.</li>
   <li>Open <a href="{{ route('automations.health') }}">Automation health</a> to see stuck runs or webhook errors.</li>
   <li>Use <b>Sync recent orders</b> above to pull these orders through the GraphQL API without waiting for the queue.</li>
  </ul>
 @endif
 <div class="diag-actions"><a class="primary" href="{{ route('integrations.index') }}" style="text-decoration:none">Check integrations</a><a class="secondary" href="{{ route('automations.health') }}" style="text-decoration:none">Automation health</a></div>
</div>
@endif

<div class="panel"><div class="panelhead"><div><div class="paneltitle">Shopify orders</div><div class="panelhint">The system does not automatically cancel orders.@if($lastWebhookAt)<div class="webhook-meta">Last Shopify event: <b>{{ $lastWebhookAt->diffForHumans() }}</b> · {{ number_format($webhookCount) }} webhook(s) total</div>@endif</div></div><form method="GET" class="workflow-toolbar"><input class="field" name="q" placeholder="Search order or customer" value="{{ request('q') }}"><button class="secondary">Search</button></form></div>
<div class="tablewrap"><table class="orders"><thead><tr><th>ORDER</th><th>CUSTOMER</th><th>PAYMENT</th><th>VALUE</th><th>VERIFICATION</th><th>RISK REASONS</th><th>UPDATED</th><th>ACTION</th></tr></thead><tbody>@forelse($orders as $o)<tr><td class="ordid">{{ $o->order_name?:'#'.$o->shopify_order_id }}</td><td>{{ $o->customer_name?:'Customer' }}</td><td>{{ $o->payment_method?:'—' }}</td><td>{{ $o->order_total!==null?number_format((float)$o->order_total,2).' '.($o->currency??''):'—' }}</td><td><span class="badge {{ $o->status==='confirmed'?'b-green':($o->status==='review'?'b-red':($o->status==='pending'?'b-amber':'b-gray')) }}">{{ ucfirst(str_replace('_',' ',$o->status)) }}</span></td><td>{{ implode(' · ',$o->risk_reasons??[])?:'No review signals' }}</td><td>{{ $o->updated_at->diffForHumans() }}</td><td>@if(in_array($o->status,['pending','review','reminded']))<div class="formrow"><form method="POST" action="{{ route('orders.decide',$o) }}">@csrf<input type="hidden" name="decision" value="confirmed"><button class="tableaction">Confirm</button></form><form method="POST" action="{{ route('orders.decide',$o) }}">@csrf<input type="hidden" name="decision" value="review"><button class="tableaction">Review</button></form></div>@else—@endif</td></tr>@empty<tr><td colspan="8" class="empty-state">@if(request('q'))No orders match &ldquo;{{ request('q') }}&rdquo;.@else Orders will appear here when Shopify sends order-created webhooks.@endif</td></tr>@endforelse</tbody></table></div>{{ $orders->links() }}</div>
@endsection
