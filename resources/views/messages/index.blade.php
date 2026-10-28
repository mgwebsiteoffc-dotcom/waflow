@extends('layouts.app')
@section('title','Message log')
@section('content')
@php
  $retryable = \App\Http\Controllers\MessageLogController::RETRYABLE;
  $deliveredStates = \App\Http\Controllers\MessageLogController::DELIVERED;
  $pillClass = fn($s) => in_array($s, ['sent','delivered','read','accepted','manual'], true) ? 'green'
      : (in_array($s, ['failed','unknown'], true) ? 'red'
      : (in_array($s, ['queued','sending'], true) ? 'amber' : 'blue'));
@endphp
<div class="topbar"><div><div class="eyebrow">Manage / Message log</div><h1 class="page-title">WhatsApp message log</h1><p class="subtitle">Every template send with its delivery status. Failed or uncertain messages can be retried or marked as manually sent.</p></div><span class="datepill">{{ number_format($counts['all']) }} total · {{ number_format($counts['needs_action']) }} need action</span></div>

<div class="panel" style="margin-bottom:14px;padding:10px 14px">
 <div class="formrow" style="gap:8px;flex-wrap:wrap">
  @foreach(['needs_action' => 'Needs action ('.$counts['needs_action'].')', 'skipped' => 'Blocked / skipped ('.$counts['skipped'].')', 'delivered' => 'Delivered ('.$counts['delivered'].')', 'all' => 'All messages ('.$counts['all'].')'] as $key => $label)
   <a class="secondary" href="{{ route('messages.index', ['status' => $key]) }}" style="text-decoration:none{{ $filter === $key ? ';background:#7044e8;color:#fff;border-color:#7044e8' : '' }}">{{ $label }}</a>
  @endforeach
 </div>
</div>

<div class="panel"><div class="tablewrap"><table class="orders"><thead><tr><th>WHEN</th><th>TEMPLATE</th><th>PHONE</th><th>STATUS</th><th>ERROR / DETAIL</th><th style="min-width:230px">MANUAL ACTIONS</th></tr></thead><tbody>
@forelse($logs as $log)
<tr>
 <td style="white-space:nowrap">{{ $log->created_at->diffForHumans() }}</td>
 <td><strong>{{ $log->template_name ?? '—' }}</strong>@if($log->node_id)<br><span class="muted" style="font-size:10px">step {{ $log->node_id }}</span>@endif</td>
 <td style="white-space:nowrap">{{ \App\Services\PhoneIdentity::mask($log->phone) }}</td>
 <td><span class="pill {{ $pillClass($log->status) }}">{{ str_replace('_', ' ', $log->status) }}</span></td>
 <td style="max-width:320px;white-space:normal;color:#a05b59;font-size:11px">{{ $log->error ?: '—' }}</td>
 <td>
  @if(in_array($log->status, $deliveredStates, true))
   <span class="muted" style="font-size:11px">No action needed</span>
  @else
   <div class="formrow" style="gap:6px;flex-wrap:wrap">
    @if(in_array($log->status, $retryable, true))
     <form method="POST" action="{{ route('messages.retry', $log) }}" onsubmit="return confirm('Re-send this WhatsApp template now?@if($log->status === 'unknown')\n\nDelivery is uncertain — the customer may already have received it, so retrying could send a duplicate.@endif')">@csrf<button class="secondary" style="padding:6px 12px">↻ Retry send</button></form>
    @endif
    <form method="POST" action="{{ route('messages.manual', $log) }}" onsubmit="return confirm('Mark this message as manually sent? It will be treated as resolved and never retried automatically.')">@csrf<button class="secondary" style="padding:6px 12px">✓ Mark manually sent</button></form>
   </div>
  @endif
 </td>
</tr>
@empty
<tr><td colspan="6" class="empty-state">
 @if($filter === 'needs_action')
  No failed or pending messages — every send is delivered or resolved.
 @else
  No messages match this filter yet.
 @endif
</td></tr>
@endforelse
</tbody></table></div>{{ $logs->links() }}</div>

<div class="panel" style="margin-top:14px"><div class="paneltitle" style="font-size:13px">What the statuses mean</div>
 <ul class="diag-list" style="font-size:11px;margin-top:8px">
  <li><strong>failed / unknown</strong> — Whatify rejected the send or could not be reached. Use <strong>Retry send</strong> after fixing the cause; for <em>unknown</em>, check Whatify first to avoid duplicate messages.</li>
  <li><strong>queued / sending</strong> — stuck because no queue worker ran. Start the worker, or retry manually here.</li>
  <li><strong>skipped not approved</strong> — the template is not approved in Whatify. Approve it, then retry.</li>
  <li><strong>skipped no opt-in / no marketing / checkout closed</strong> — policy blocks an automatic send. If you message the customer yourself, use <strong>Mark manually sent</strong> to close the item.</li>
  <li><strong>manual</strong> — a team member confirmed the message was handled outside the app.</li>
 </ul>
</div>
@endsection
