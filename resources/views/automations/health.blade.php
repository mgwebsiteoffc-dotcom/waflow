@extends('layouts.app')
@section('title','Automation health')
@push('head')<style>
.health-summary{display:flex;gap:14px;flex-wrap:wrap;margin:18px 0}
.health-chip{background:#fff;border:1px solid var(--line);border-radius:14px;padding:15px 22px;min-width:150px}
.health-chip .num{font-size:26px;font-weight:800;line-height:1.1}
.health-chip .lbl{font-size:11px;color:#8b8a99;font-weight:700;letter-spacing:.5px;text-transform:uppercase;margin-top:3px}
.health-chip.ok .num{color:#1c925f}.health-chip.warn .num{color:#a77d14}.health-chip.error .num{color:#bf6664}
.check-row{display:flex;gap:14px;align-items:flex-start;padding:15px 4px;border-bottom:1px solid var(--line)}
.check-row:last-child{border-bottom:0}
.check-icon{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:13px;flex-shrink:0;color:#fff}
.check-icon.ok{background:#1c925f}.check-icon.warn{background:#d9a52c}.check-icon.error{background:#cf6b68}
.check-body{flex:1}.check-title{font-weight:700;font-size:13px}.check-detail{color:#77768a;font-size:12px;line-height:1.6;margin-top:3px}
.check-fix{flex-shrink:0;align-self:center}
.outcome-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-top:14px}
.outcome-card{border:1px solid var(--line);border-radius:12px;padding:14px;background:#faf9fd}
.outcome-card .count{font-size:22px;font-weight:800}.outcome-card.ok .count{color:#1c925f}.outcome-card.warn .count{color:#a77d14}.outcome-card.error .count{color:#bf6664}
.outcome-card .status{font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#9a99a8}.outcome-card .why{font-size:11px;color:#77768a;margin-top:4px;line-height:1.5}
</style>@endpush
@section('content')
<div class="topbar">
 <div>
  <div class="eyebrow"><a href="{{ route('automations.index') }}" style="color:#9291a1;text-decoration:none">Automations</a> / Health</div>
  <h1 class="page-title">Automation health &amp; diagnostics</h1>
  <p class="subtitle">Everything that decides whether your WhatsApp automations actually send — connections, consent, templates, queue and failures.</p>
 </div>
 <div class="top-actions"><a class="secondary" href="{{ route('automations.presets') }}" style="text-decoration:none">Predefined templates</a><a class="primary" href="{{ route('executions.index') }}" style="text-decoration:none">Run history</a></div>
</div>

<div class="health-summary">
 <div class="health-chip ok"><div class="num">{{ $health['counts']['ok'] }}</div><div class="lbl">Passing</div></div>
 <div class="health-chip warn"><div class="num">{{ $health['counts']['warn'] }}</div><div class="lbl">Warnings</div></div>
 <div class="health-chip error"><div class="num">{{ $health['counts']['error'] }}</div><div class="lbl">Blocking issues</div></div>
 <div class="health-chip"><div class="num">{{ $health['counts']['waiting_replies'] }}</div><div class="lbl">Runs waiting on customer reply</div></div>
</div>

<div class="panel">
 <div class="panelhead"><div><div class="paneltitle">Checklist</div><div class="panelhint">Blocking issues stop messages from sending; warnings mean some automations or customers are skipped.</div></div></div>
 @foreach($health['checks'] as $check)
  <div class="check-row">
   <div class="check-icon {{ $check['level'] }}">{{ $check['level']==='ok' ? '✓' : ($check['level']==='warn' ? '!' : '×') }}</div>
   <div class="check-body">
    <div class="check-title">{{ $check['title'] }}</div>
    @if($check['detail'])<div class="check-detail">{{ $check['detail'] }}</div>@endif
   </div>
   @if(!empty($check['fix']))<div class="check-fix"><a class="tableaction" href="{{ route($check['fix']['route']) }}">{{ $check['fix']['label'] }} →</a></div>@endif
  </div>
 @endforeach
</div>

<div class="panel" style="margin-top:16px">
 <div class="panelhead"><div><div class="paneltitle">WhatsApp messages — last 7 days</div><div class="panelhint">Why messages were sent, skipped or failed across every automation.</div></div></div>
 @if(count($health['outcomes']) === 0)
  <div class="empty-state">No WhatsApp messages logged in the last 7 days. New runs appear here once Shopify events trigger an automation.</div>
 @else
  <div class="outcome-grid">
   @foreach($health['outcomes'] as $outcome)
    <div class="outcome-card {{ $outcome['tone'] }}">
     <div class="status">{{ str_replace('_',' ', $outcome['status']) }}</div>
     <div class="count">{{ number_format($outcome['count']) }}</div>
     <div class="why">{{ $outcome['label'] }}</div>
    </div>
   @endforeach
  </div>
 @endif
</div>
@endsection
