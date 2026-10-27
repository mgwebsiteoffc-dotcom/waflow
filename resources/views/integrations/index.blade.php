@extends('layouts.app')
@section('title','Integrations')
@push('head')<style>
.integration-wrap{display:grid;gap:16px;max-width:1280px}
.integration-wrap .panel{margin:0}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:5px}
.status-dot.on{background:#1ca66a}.status-dot.off{background:#b3b2c0}.status-dot.warn{background:#d9a52c}
.acc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:11px;margin-top:12px}
.tpl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(225px,1fr));gap:11px;margin-top:12px}
.info-card{border:1px solid var(--line);border-radius:12px;padding:13px 14px;background:#faf9fd;display:flex;flex-direction:column;gap:8px;min-width:0}
.info-card .ic-top{display:flex;align-items:center;gap:9px;min-width:0}
.info-card .ic-ico{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;flex-shrink:0}
.info-card .ic-ico.green{background:#e7f7ee;color:#2b9b62}.info-card .ic-ico.purple{background:#f0eaff;color:#7044e8}
.info-card .ic-name{font-weight:700;font-size:12px;word-break:break-word;line-height:1.35;min-width:0}
.info-card .ic-meta{font-size:10.5px;color:#9392a2;line-height:1.5}
.info-card .ic-foot{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-top:auto}
.cat-pill{font-size:9.5px;font-weight:700;padding:3px 8px;border-radius:20px;letter-spacing:.3px;text-transform:uppercase}
.cat-utility{background:#e7f7ee;color:#267b50}.cat-marketing{background:#fff1df;color:#a76a1c}.cat-other{background:#eef0fb;color:#4d63b8}
.tpl-preview{font-size:10.5px;color:#8d8c9c;line-height:1.55;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0}
.section-gap{margin-top:22px}
</style>@endpush
@section('content')
<div class="topbar"><div><div class="eyebrow">Manage / Integrations</div><h1 class="page-title">Connect your channels</h1><p class="subtitle">Shopify supplies order events. Whatify powers approved WhatsApp messaging.</p></div><span class="datepill">Credentials encrypted at rest</span></div>

<div class="integration-wrap">

 <div class="panel">
  <div class="panelhead"><div><div class="paneltitle">Shopify store</div><div class="panelhint">OAuth authorization and verified webhooks</div></div>
   <span class="badge {{ $shop?'b-green':'b-gray' }}"><span class="status-dot {{ $shop?'on':'off' }}"></span>{{ $shop?'Connected':'Not connected' }}</span></div>
  @if($shop)
   <div class="channel"><span class="channelico">S</span><div><strong>{{ $shop->shop_domain }}</strong><small>Scopes: {{ $shop->granted_scopes ?: 'not returned' }} · Connected {{ $shop->installed_at?->diffForHumans() }}</small></div><div class="formrow" style="margin-left:auto"><form method="POST" action="{{ route('integrations.shopify.test') }}">@csrf<button class="secondary">Test connection</button></form><form method="POST" action="{{ route('integrations.shopify.disconnect') }}">@csrf @method('DELETE')<button class="secondary" onclick="return confirm('Disconnect this Shopify store?')">Disconnect</button></form></div></div>
   <div class="notice" style="margin-bottom:0"><span>✓</span><p><strong>Webhook endpoint:</strong> {{ url('/api/webhooks/shopify') }}<br>Orders, fulfillments, uninstall and privacy events are signature-verified. If nothing syncs, open <a href="{{ route('webhooks.index') }}">Webhook events</a> — an empty list means the Shopify CLI app configuration (<code>shopify.app.toml</code>) has not been deployed, so no subscriptions exist yet.</p></div>
  @else
   <div class="notice" style="margin-bottom:0"><span>ⓘ</span><p><strong>Install from Shopify.</strong> Open Whatify Flow from the Shopify Admin or app listing to start OAuth. The store domain is supplied by Shopify; this app does not ask merchants to type it manually. Configure <code>SHOPIFY_API_KEY</code>, <code>SHOPIFY_API_SECRET</code>, and a stable public HTTPS <code>APP_URL</code> on the Laravel server.</p></div>
  @endif
 </div>

 <div class="panel">
  <div class="panelhead"><div><div class="paneltitle">Whatify WhatsApp API</div><div class="panelhint">External server API · API key is never sent back to the browser</div></div>
   <span class="badge {{ ($shop?->whatify_api_key) ? ($whatifyError ? 'b-amber' : 'b-green') : 'b-gray' }}"><span class="status-dot {{ ($shop?->whatify_api_key) ? ($whatifyError?'warn':'on') : 'off' }}"></span>{{ ($shop?->whatify_api_key) ? ($whatifyError ? 'Needs attention' : 'Connected') : 'Not connected' }}</span></div>

  @if(!$shop)
   <div class="notice" style="margin-bottom:0"><span>ⓘ</span><p>Install Shopify first so Whatify credentials can be securely scoped to this store.</p></div>
  @else
   @if($whatifyError)<div class="flash error">Could not load Whatify details: {{ $whatifyError }}</div>@endif

   @if(!$shop->whatify_api_key)
    <form method="POST" action="{{ route('integrations.whatify.connect') }}">@csrf
     <label class="configlabel" for="api_key">Whatify external API key</label>
     <div class="formrow"><input class="field" type="password" id="api_key" name="api_key" required autocomplete="new-password" placeholder="wfy_••••••••••••••••" style="max-width:450px"><button class="primary">Connect Whatify</button></div>
     <div class="confighint">The backend validates the key by calling <code>/whatsapp-accounts</code> and <code>/templates</code>, then encrypts it at rest.</div>
    </form>
   @else
    <div class="channel"><span class="channelico gray">◉</span><div><strong>{{ count($accounts) }} WhatsApp sender account(s)</strong><small>Whatify key verified · do not share your API key</small></div><form method="POST" action="{{ route('integrations.whatify.disconnect') }}" style="margin-left:auto">@csrf @method('DELETE')<button class="secondary" onclick="return confirm('Remove saved Whatify credentials?')">Disconnect</button></form></div>

    <div class="section-gap">
     <div class="paneltitle" style="font-size:13px">Sender accounts</div>
     <div class="acc-grid">
      @forelse($accounts as $account)
       <div class="info-card">
        <div class="ic-top"><span class="ic-ico green">◉</span><div class="ic-name">{{ $account['display_name']??'WhatsApp Business' }}</div></div>
        <div class="ic-meta">{{ $account['phone_number']??'—' }} · {{ ucfirst($account['status']??'connected') }}</div>
        <div class="ic-foot"><span class="badge b-green">{{ $account['quality_rating']??'Connected' }}</span></div>
       </div>
      @empty
       <div class="confighint">Whatify returned no sender accounts for this API key.</div>
      @endforelse
     </div>
    </div>

    <div class="section-gap">
     <div class="panelhead" style="margin-bottom:0">
      <div><div class="paneltitle" style="font-size:13px">Approved templates</div><div class="panelhint">Use the exact template name and placeholder order in your automations</div></div>
      <span class="badge b-blue">{{ count(array_filter($templates, fn($t)=>strtolower($t['status']??'')==='approved')) }} approved</span>
     </div>
     <div class="tpl-grid">
      @forelse($templates as $template)
       @if(strtolower($template['status']??'')==='approved')
        @php $category=strtolower($template['category']??'utility'); @endphp
        <div class="info-card">
         <div class="ic-top"><span class="ic-ico purple">◉</span><div class="ic-name">{{ $template['name']??'Unnamed template' }}</div></div>
         @if(!empty($template['body']))<p class="tpl-preview" title="{{ $template['body'] }}">{{ $template['body'] }}</p>@endif
         <div class="ic-foot">
          <span class="cat-pill cat-{{ $category==='marketing'?'marketing':($category==='utility'?'utility':'other') }}">{{ ucfirst($category) }}</span>
          <span class="ic-meta">{{ $template['language']??'' }}</span>
         </div>
        </div>
       @endif
      @empty
       <div class="confighint">No templates returned. Sync or approve templates in Whatify.</div>
      @endforelse
     </div>
    </div>

    <div class="notice"><span>ⓘ</span><p><strong>Inbound replies:</strong> Configure Whatify to POST signed reply events to <code>{{ url('/api/webhooks/whatify/inbound') }}</code>, and set <code>WHATIFY_WEBHOOK_SECRET</code>. The exact inbound payload/signature format must be confirmed with Whatify; the adapter expects an event ID, sender phone, and message or button reply.</p></div>
   @endif
  @endif
 </div>

 <div class="panel">
  <div class="panelhead"><div><div class="paneltitle">Privacy and safety</div><div class="panelhint">Sensitive order/customer fields are encrypted at rest.</div></div></div>
  <p class="confighint" style="font-size:11px;line-height:1.7;margin:0">Shopify customer data requests and redaction events are received on the verified webhook endpoint. WhatsApp sends require a recorded opt-in and currently approved template; STOP/UNSUBSCRIBE opt-outs are stored and suppress future sends. COD non-response is routed to review; automatic cancellations are disabled. Obtain and retain valid consent evidence outside this app.</p>
 </div>

 @if($shop)
 <div class="panel">
  <div class="panelhead"><div><div class="paneltitle">Customer privacy requests</div><div class="panelhint">Exports generated from Whatify Flow's stored app data after a Shopify request.</div></div></div>
  @forelse($privacyRequests as $pr)
   <div class="channel"><span class="node-symbol">▤</span><div><strong>Customer {{ $pr->shopify_customer_id }}</strong><small>Received {{ $pr->received_at->diffForHumans() }} · {{ ucfirst($pr->status) }}</small></div><a class="secondary" style="margin-left:auto;text-decoration:none" href="{{ route('privacy.export',$pr) }}">Download export</a></div>
  @empty
   <div class="confighint">No customer data requests received.</div>
  @endforelse
 </div>
 @endif

</div>
@endsection
