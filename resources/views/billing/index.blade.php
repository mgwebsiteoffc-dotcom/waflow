@extends('layouts.app')
@section('title','Billing')
@push('head')<style>
.billing-wrap{max-width:880px;display:grid;gap:16px}
.plan-card{padding:34px 30px;text-align:center;background:linear-gradient(180deg,#fbf9ff,#fff);border:1px solid #e6dffb;border-radius:16px}
.plan-price{font:800 52px Manrope,sans-serif;letter-spacing:-1.5px;color:var(--ink);margin:14px 0 2px}
.plan-price small{font-size:18px;font-weight:700;color:#89899a;letter-spacing:0}
.plan-note{color:#89899a;font-size:12px;margin-bottom:22px}
.feature-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 22px;text-align:left;margin:22px 0 26px}
.feature-grid div{display:flex;gap:9px;align-items:flex-start;font-size:12px;color:#4c4a5e;line-height:1.45}
.feature-grid .tick{color:#2b9b62;font-weight:800;flex-shrink:0}
.status-row{display:flex;justify-content:center;margin-bottom:14px}
@media(max-width:680px){.feature-grid{grid-template-columns:1fr}}
</style>@endpush
@section('content')
<div class="topbar"><div><div class="eyebrow">Workspace / Billing</div><h1 class="page-title">Plan &amp; payment</h1><p class="subtitle">One Shopify-approved charge, handled securely inside your Shopify admin.</p></div></div>

<div class="billing-wrap">
 <div class="panel plan-card">
  <div class="status-row">
   @if($shop->billing_status === 'active' && $shop->billing_confirmed_at)
    <span class="badge b-green">● Paid · unlocked</span>
   @else
    <span class="badge b-amber">● Payment required</span>
   @endif
  </div>
  <div class="paneltitle" style="font-size:16px">Whatify Flow — lifetime access</div>
  <div class="plan-price">${{ $price }} <small>{{ $currency }} · one time</small></div>
  <div class="plan-note">No subscription. No renewals. No hidden fees — pay once and keep the app.</div>

  <div class="feature-grid">
   <div><span class="tick">✓</span> Four ready-made WhatsApp automations (COD verification, order confirmation, cart recovery, shipping updates)</div>
   <div><span class="tick">✓</span> Visual workflow builder with drag-and-drop and branching</div>
   <div><span class="tick">✓</span> Website entry &amp; exit popups and floating WhatsApp button</div>
   <div><span class="tick">✓</span> Consent-gated, approved-template messaging with STOP opt-out</div>
   <div><span class="tick">✓</span> Shopify order sync, tags and full run history</div>
   <div><span class="tick">✓</span> Health diagnostics, analytics and lead capture</div>
  </div>

  @if($localDriver)
   <div class="notice" style="margin:0 0 18px;background:#fff7e6;border-color:#f5e7bd"><span style="color:#a77b20">ⓘ</span><p style="color:#8c6814"><strong>Simulation mode is on.</strong> SHOPIFY_BILLING_DRIVER=local — clicking pay mimics the Shopify redirect and approval inside this app. No real charge and no Shopify API call happens. Switch the driver back to <code>shopify</code> for the live Billing API.</p></div>
  @elseif($testMode)
   <div class="notice" style="margin:0 0 18px"><span>ⓘ</span><p><strong>Shopify test mode is on.</strong> The charge is created with the test flag, so approving it makes no real payment.</p></div>
  @endif
  @if($shop->billing_status === 'active' && $shop->billing_confirmed_at)
   <a class="primary" href="{{ route('dashboard') }}" style="text-decoration:none;display:inline-block">Go to dashboard</a>
   <div class="plan-note" style="margin:14px 0 0">Payment confirmed{{ $shop->billing_confirmed_at ? ' on '.$shop->billing_confirmed_at->format('d M Y') : '' }}. Charge ID: {{ $shop->billing_charge_id ? \Illuminate\Support\Str::limit($shop->billing_charge_id, 40) : '—' }}</div>
  @else
   <form method="POST" action="{{ route('billing.start') }}">@csrf
    <button class="primary" type="submit" style="padding:14px 34px;font-size:13px">Pay ${{ $price }} once via Shopify</button>
   </form>
   <div class="plan-note" style="margin-top:14px">@if($localDriver)Simulation mode: approving returns you here and unlocks the app instantly, with no external payment screen.@else You will be redirected to Shopify's secure payment screen, then returned here automatically.@if($testMode) <strong style="color:#a77b20">Test mode is on — no real charge is made.</strong>@endif @endif</div>
  @endif
 </div>

 <div class="panel">
  <div class="paneltitle" style="margin-bottom:10px">How billing works</div>
  <ul class="hint-list" style="margin:0;padding-left:18px;color:#716f80;font-size:12px;line-height:1.9">
   <li>The charge is created through the official Shopify Billing API — Whatify Flow never sees your card details.</li>
   <li>Until the one-time payment is approved, the automation, orders, analytics and website-widget sections stay locked; the dashboard and integrations remain available for setup.</li>
   <li>After approving in Shopify you are sent back here and the app unlocks immediately. If the confirmation is delayed, reopen this page and start again — Shopify will not create a duplicate charge for the same approval flow.</li>
   <li>Billing applies per installed store. Uninstalling the app revokes access; reinstalling starts a new charge.</li>
  </ul>
 </div>
</div>
@endsection
