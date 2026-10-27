@extends('layouts.app')
@section('title','Automation templates')
@push('head')<style>
.preset-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:16px;margin-top:18px}
.preset-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;display:flex;flex-direction:column;gap:12px}
.preset-head{display:flex;align-items:center;gap:12px}
.preset-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;font-size:18px;background:var(--lav);color:var(--purple)}
.preset-icon.green{background:#e7f8ef;color:#1c925f}.preset-icon.blue{background:#eaf2ff;color:#3f73c2}.preset-icon.purple{background:#f3edff;color:#6b44d8}
.preset-name{font-weight:800;font-size:15px}.preset-desc{color:#77768a;font-size:12px;line-height:1.65;margin:0}
.meta-pills{display:flex;gap:7px;flex-wrap:wrap}.meta-pills .pill{font-size:10px}
.setup-notes{margin:0;padding-left:16px;color:#6f6e80;font-size:11.5px;line-height:1.75}
.consent-note{background:#fff7e6;border:1px solid #f3e4b8;color:#8a6817;border-radius:10px;padding:9px 11px;font-size:11px;line-height:1.6}
.consent-note.utility{background:#eef8f2;border-color:#cdeedb;color:#267b50}
.preset-form{border-top:1px solid var(--line);padding-top:14px;display:flex;flex-direction:column;gap:9px;margin-top:auto}
.preset-form label{font-size:10px;font-weight:700;color:#888;letter-spacing:.4px;text-transform:uppercase}
.preset-form .field{width:100%}
.stepper{display:flex;gap:10px;flex-wrap:wrap;margin-top:4px}
.stepper span{background:#fff;border:1px solid var(--line);border-radius:20px;padding:7px 14px;font-size:11.5px;font-weight:700;color:#5d5c70}
.stepper b{color:var(--purple)}
</style>@endpush
@section('content')
<div class="topbar">
 <div>
  <div class="eyebrow"><a href="{{ route('automations.index') }}" style="color:#9291a1;text-decoration:none">Automations</a> / Templates</div>
  <h1 class="page-title">Predefined WhatsApp automations</h1>
  <p class="subtitle">Ready-made flows for COD verification, order confirmation, abandoned cart and delivery updates. Pick one, map it to your approved Whatify template, publish.</p>
 </div>
 <div class="top-actions"><a class="secondary" href="{{ route('automations.health') }}" style="text-decoration:none">Automation health</a><a class="primary" href="{{ route('automations.index') }}" style="text-decoration:none">Your automations</a></div>
</div>

<div class="panel" style="margin-top:16px"><div class="stepper"><span><b>1.</b> Choose a flow below</span><span><b>2.</b> Select the approved Whatify template each message should use</span><span><b>3.</b> Open it, press <b>Publish</b> — it goes live immediately</span></div>
<div class="confighint" style="padding:10px 14px 0">Marketing templates (cart recovery) require a separate marketing opt-in at checkout. Utility templates (order/COD/shipping) use the order-update opt-in. <a href="{{ route('automations.health') }}">Run the health check →</a></div></div>

<div class="preset-grid">
@foreach($presets as $preset)
 <div class="preset-card">
  <div class="preset-head">
   <div class="preset-icon {{ $preset['color'] }}">{{ $preset['icon'] }}</div>
   <div>
    <div class="preset-name">{{ $preset['name'] }}
     @if(in_array($preset['key'], $installed, true))<span class="pill green" style="margin-left:6px">Added</span>@endif
    </div>
    <div class="meta-pills" style="margin-top:5px">
     <span class="pill blue">{{ str_replace('_',' ', $preset['trigger_topic']) }}</span>
     <span class="pill {{ $preset['purpose']==='marketing' ? 'amber' : 'green' }}">{{ $preset['purpose']==='marketing' ? 'Marketing consent' : 'Utility / order consent' }}</span>
    </div>
   </div>
  </div>
  <p class="preset-desc">{{ $preset['description'] }}</p>
  <ul class="setup-notes">@foreach($preset['setup_notes'] as $note)<li>{{ $note }}</li>@endforeach</ul>
  <div class="consent-note {{ $preset['purpose']==='marketing' ? '' : 'utility' }}">⚠ {{ $preset['consent_note'] }}</div>

  <form class="preset-form" method="POST" action="{{ route('automations.from-preset') }}">
   @csrf
   <input type="hidden" name="preset_key" value="{{ $preset['key'] }}">
   <label>Automation name</label>
   <input class="field" name="name" value="{{ $preset['name'] }}" maxlength="120" required>
   @foreach($preset['required_templates'] as $required)
    <label>{{ $required['label'] }} <span style="text-transform:none;letter-spacing:0;color:#a09fb0">(approved {{ ucfirst($required['category']) }} template)</span></label>
    @php $options = $byCategory[$required['category']] ?? []; @endphp
    @if(count($options) === 0)
     <input class="field" name="templates[{{ $required['node_id'] }}]" value="{{ $required['suggested_name'] }}" placeholder="Approved template name">
     <div class="confighint" style="color:#a77d14">No approved {{ $required['category'] }} template found in Whatify — create/approve one, then map it in the builder before publishing.</div>
    @else
     <select class="field" name="templates[{{ $required['node_id'] }}]">
      @foreach($options as $template)
       <option value="{{ $template['name'] }}">{{ $template['name'] }} ({{ $template['language'] ?? 'default' }})</option>
      @endforeach
     </select>
    @endif
   @endforeach
   <button class="primary" style="margin-top:6px">Use this template →</button>
  </form>
 </div>
@endforeach
</div>
@endsection
