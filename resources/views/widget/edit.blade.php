@extends('layouts.app')
@section('title','Website widget')
@push('head')<style>
.widget-wrap{display:grid;gap:16px;max-width:1080px}
.widget-wrap .panel{margin:0}
.widget-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px}
.widget-grid .full{grid-column:1/-1}
.switch{cursor:pointer;vertical-align:middle}
.snippet-box{display:flex;gap:8px;align-items:center;background:#f8f6ff;border:1px solid #eee7ff;border-radius:10px;padding:10px 12px}
.snippet-box input{flex:1;border:0;background:transparent;font-family:monospace;font-size:11px;color:#555;outline:none;width:100%}
.lead-src{font-size:9.5px;font-weight:800;padding:3px 8px;border-radius:20px;text-transform:uppercase;letter-spacing:.3px}
.lead-src.entry{background:#eaf2ff;color:#4778ba}.lead-src.exit{background:#fff1df;color:#a76a1c}
.hint-list{margin:8px 0 0;padding-left:16px;color:#8d8c9c;font-size:11px;line-height:1.7}
@media(max-width:860px){.widget-grid{grid-template-columns:1fr}}
</style>@endpush
@section('content')
<div class="topbar"><div><div class="eyebrow">Workspace / Website widget</div><h1 class="page-title">Website popups & WhatsApp button</h1><p class="subtitle">Capture visitor name and WhatsApp number, and add a floating chat button to your storefront.</p></div><span class="datepill">Leads join your WhatsApp opt-in list</span></div>

<div class="widget-wrap">

 <div class="panel">
  <div class="panelhead"><div><div class="paneltitle">1 · Add this snippet to your website</div><div class="panelhint">Paste once before <code>&lt;/body&gt;</code> in your Shopify theme (theme.liquid) or any website. All settings below apply instantly — no code change needed later.</div></div></div>
  <div class="snippet-box"><input id="snippet" readonly value="{{ $snippet }}" onclick="this.select()"><button type="button" class="secondary" id="copyBtn" onclick="copySnippet()">Copy</button></div>
  <ul class="hint-list">
   <li>Script URL for this store: <code>{{ $scriptUrl }}</code></li>
   <li>Captured contacts are stored as WhatsApp opt-ins and appear in the leads table at the bottom of this page.</li>
   <li>A visitor who closes a popup will not see it again for the frequency period you set.</li>
  </ul>
 </div>

 <form method="POST" action="{{ route('widget.update') }}">
  @csrf
  @method('PUT')
  <div class="widget-wrap">

   <div class="panel">
    <div class="panelhead"><div><div class="paneltitle">2 · Entry popup</div><div class="panelhint">Shown shortly after a visitor lands on the page</div></div>
     <label class="switch {{ $setting->entry_enabled?'on':'' }}"><input type="checkbox" name="entry_enabled" value="1" @checked($setting->entry_enabled) style="display:none" onchange="this.parentNode.classList.toggle('on',this.checked)"></label></div>
    <div class="widget-grid">
     <div><label class="configlabel">Popup title</label><input class="field" name="entry_title" maxlength="150" value="{{ old('entry_title',$setting->entry_title) }}"></div>
     <div><label class="configlabel">Button label</label><input class="field" name="entry_button_label" maxlength="60" value="{{ old('entry_button_label',$setting->entry_button_label) }}"></div>
     <div class="full"><label class="configlabel">Message</label><textarea class="field textarea" name="entry_text" maxlength="500">{{ old('entry_text',$setting->entry_text) }}</textarea></div>
     <div><label class="configlabel">Show after (seconds)</label><input class="field" type="number" min="0" max="300" name="entry_delay_seconds" value="{{ old('entry_delay_seconds',$setting->entry_delay_seconds) }}"></div>
     <div><label class="configlabel">Show again after (days, 0 = once per browser session)</label><input class="field" type="number" min="0" max="90" name="entry_frequency_days" value="{{ old('entry_frequency_days',$setting->entry_frequency_days) }}"></div>
    </div>
   </div>

   <div class="panel">
    <div class="panelhead"><div><div class="paneltitle">3 · Exit-intent popup</div><div class="panelhint">Shown when a desktop visitor moves the cursor to leave the page; on mobile, when they scroll back to the top after scrolling deep</div></div>
     <label class="switch {{ $setting->exit_enabled?'on':'' }}"><input type="checkbox" name="exit_enabled" value="1" @checked($setting->exit_enabled) style="display:none" onchange="this.parentNode.classList.toggle('on',this.checked)"></label></div>
    <div class="widget-grid">
     <div><label class="configlabel">Popup title</label><input class="field" name="exit_title" maxlength="150" value="{{ old('exit_title',$setting->exit_title) }}"></div>
     <div><label class="configlabel">Button label</label><input class="field" name="exit_button_label" maxlength="60" value="{{ old('exit_button_label',$setting->exit_button_label) }}"></div>
     <div class="full"><label class="configlabel">Message</label><textarea class="field textarea" name="exit_text" maxlength="500">{{ old('exit_text',$setting->exit_text) }}</textarea></div>
     <div><label class="configlabel">Show again after (days, 0 = once per browser session)</label><input class="field" type="number" min="0" max="90" name="exit_frequency_days" value="{{ old('exit_frequency_days',$setting->exit_frequency_days) }}"></div>
    </div>
   </div>

   <div class="panel">
    <div class="panelhead"><div><div class="paneltitle">4 · Floating WhatsApp button</div><div class="panelhint">Fixed chat button on every page where the snippet is installed. Opens WhatsApp with a pre-filled message.</div></div>
     <label class="switch {{ $setting->float_enabled?'on':'' }}"><input type="checkbox" name="float_enabled" value="1" @checked($setting->float_enabled) style="display:none" onchange="this.parentNode.classList.toggle('on',this.checked)"></label></div>
    <div class="widget-grid">
     <div><label class="configlabel">WhatsApp number with country code</label><input class="field" name="float_phone" maxlength="30" placeholder="919876543210" value="{{ old('float_phone',$setting->float_phone) }}"></div>
     <div><label class="configlabel">Pre-filled message</label><input class="field" name="float_message" maxlength="500" value="{{ old('float_message',$setting->float_message) }}"></div>
     <div><label class="configlabel">Position</label><select class="field" name="float_position"><option value="right" @selected($setting->float_position==='right')>Bottom right</option><option value="left" @selected($setting->float_position==='left')>Bottom left</option></select></div>
     <div><label class="configlabel">Button color</label><input class="field" type="color" name="float_color" value="{{ old('float_color',$setting->float_color) }}" style="height:42px;padding:4px"></div>
    </div>
   </div>

   <div><button class="primary" type="submit">Save widget settings</button></div>
  </div>
 </form>

 <div class="panel">
  <div class="panelhead"><div><div class="paneltitle">Latest captured leads</div><div class="panelhint">Name and WhatsApp number collected by the entry or exit popup · newest first</div></div><span class="badge b-blue">{{ count($leads) }} shown</span></div>
  @if($leads->isEmpty())
   <div class="empty-state">No leads yet. Enable a popup and install the snippet to start collecting contacts.</div>
  @else
   <div class="tablewrap"><table class="data-table"><thead><tr><th>Name</th><th>WhatsApp number</th><th>Source</th><th>Consent given</th></tr></thead><tbody>
   @foreach($leads as $lead)
    <tr>
     <td><strong>{{ $lead->contact_name ?? '—' }}</strong></td>
     <td>+{{ ltrim($lead->phone,'+') }}</td>
     <td><span class="lead-src {{ $lead->source==='website_exit'?'exit':'entry' }}">{{ $lead->source==='website_exit'?'Exit popup':'Entry popup' }}</span></td>
     <td>{{ $lead->consented_at?->format('d M Y, H:i') ?? '—' }}</td>
    </tr>
   @endforeach
   </tbody></table></div>
  @endif
 </div>

</div>
@endsection
@push('scripts')<script>
function copySnippet(){
 const input=document.getElementById('snippet');input.select();
 navigator.clipboard.writeText(input.value).then(()=>{const b=document.getElementById('copyBtn');const old=b.textContent;b.textContent='Copied!';setTimeout(()=>b.textContent=old,1600);}).catch(()=>{input.select();document.execCommand('copy');});
}
</script>@endpush
