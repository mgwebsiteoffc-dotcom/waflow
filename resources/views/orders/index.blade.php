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

@if($orders->total() === 0 && ! request('q'))
<div class="panel" style="margin-bottom:16px;border-color:#f0e4ff;background:linear-gradient(135deg,#f8f5ff,#fff)">
 <div class="panelhead"><div><div class="paneltitle">Why are no orders showing?</div><div class="panelhint">Orders appear here only after Shopify delivers an <b>orders/create</b> webhook and the queue worker processes it.</div></div></div>
 @if($webhookCount === 0)
  <div class="notice" style="margin-top:0"><span>ⓘ</span><p><strong>No Shopify webhooks have ever reached this app.</strong> This usually means the app is not fully installed or the Shopify app configuration (TOML webhook subscriptions) has not been deployed.</p></div>
  <ul class="diag-list">
   <li>Install the app from your Shopify admin and confirm the store shows <b>Connected</b> in <a href="{{ route('integrations.index') }}">Integrations</a>.</li>
   <li>Deploy the Shopify app configuration (<code>shopify app config link</code> then <code>shopify app deploy</code>) so the <code>orders/create</code> webhook subscription is registered.</li>
   <li>Keep <code>php artisan queue:work</code> running — webhooks are processed asynchronously.</li>
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
