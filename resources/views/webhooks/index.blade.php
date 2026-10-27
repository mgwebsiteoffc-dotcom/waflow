@extends('layouts.app')
@section('title','Webhook events')
@section('content')
<div class="topbar"><div><div class="eyebrow">Manage / Webhook events</div><h1 class="page-title">Shopify webhook events</h1><p class="subtitle">Every event Shopify delivered to <code>/api/webhooks/shopify</code> — use this to confirm whether syncing is reaching the app.</p></div>
<span class="datepill">{{ number_format($totals['all']) }} total · {{ number_format($totals['errors']) }} failed · {{ number_format($totals['pending']) }} pending</span></div>

@if($totals['all'] === 0)
<div class="panel" style="border-color:#f0e4ff;background:linear-gradient(135deg,#f8f5ff,#fff)">
 <div class="paneltitle">No webhooks have ever arrived</div>
 <p class="confighint" style="font-size:12px;line-height:1.8;margin-top:8px">
  Shopify is not delivering events to this server yet. On the Shopify side you must:
 </p>
 <ol style="margin:8px 0 0;padding-left:20px;font-size:12px;color:#5d5c70;line-height:2">
  <li>Put the real app client ID into <code>shopify.app.toml</code> (it still contains a placeholder until you do).</li>
  <li>Run <code>shopify app config link</code> then <code>shopify app deploy</code> so webhook subscriptions are registered.</li>
  <li>Install the app on the store from a Shopify-owned surface so an offline token is stored.</li>
  <li>Confirm this server is publicly reachable over HTTPS at the configured <code>application_url</code>.</li>
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
