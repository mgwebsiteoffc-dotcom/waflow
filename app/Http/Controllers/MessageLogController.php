<?php

namespace App\Http\Controllers;

use App\Models\MessageLog;
use App\Services\PhoneIdentity;
use App\Services\WhatifyClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MessageLogController extends Controller
{
    /** Statuses a merchant can re-attempt from the Message log page. */
    public const RETRYABLE = ['failed', 'unknown', 'queued', 'sending', 'skipped_not_approved'];

    /** Terminal success states — no buttons shown for these. */
    public const DELIVERED = ['sent', 'delivered', 'read', 'accepted', 'manual'];

    /** Policy blocks — not retryable automatically; merchant resolves manually. */
    public const SKIPPED = ['skipped_no_opt_in', 'skipped_no_marketing', 'skipped_checkout_closed'];

    public function index(Request $r)
    {
        $shop = $r->attributes->get('currentShop');
        $filter = (string) $r->query('status', 'needs_action');
        $query = $shop->messageLogs()->latest();
        if ($filter === 'needs_action') {
            $query->whereIn('status', self::RETRYABLE);
        } elseif ($filter === 'skipped') {
            $query->whereIn('status', self::SKIPPED);
        } elseif ($filter === 'delivered') {
            $query->whereIn('status', self::DELIVERED);
        }
        $logs = $query->paginate(25)->withQueryString();
        $counts = [
            'needs_action' => (clone $shop->messageLogs())->whereIn('status', self::RETRYABLE)->count(),
            'skipped' => (clone $shop->messageLogs())->whereIn('status', self::SKIPPED)->count(),
            'delivered' => (clone $shop->messageLogs())->whereIn('status', self::DELIVERED)->count(),
            'all' => (clone $shop->messageLogs())->count(),
        ];
        return view('messages.index', compact('logs', 'filter', 'counts'));
    }

    /** Re-send a failed/uncertain message using its original request payload. */
    public function retry(Request $r, MessageLog $messageLog)
    {
        $shop = $r->attributes->get('currentShop');
        abort_unless($messageLog->shop_id === $shop->id, 404);

        $log = DB::transaction(function () use ($messageLog) {
            $locked = MessageLog::query()->lockForUpdate()->findOrFail($messageLog->id);
            if (in_array($locked->status, self::DELIVERED, true)) {
                throw new RuntimeException('This message was already delivered or resolved manually.');
            }
            if (!in_array($locked->status, self::RETRYABLE, true)) {
                throw new RuntimeException('Messages blocked by consent or checkout rules cannot be re-sent automatically. Send it yourself and use "Mark manually sent".');
            }
            $payload = $locked->request_payload;
            if (!is_array($payload) || empty($payload['phone']) || empty($payload['template_name'])) {
                throw new RuntimeException('The original send request is missing, so it cannot be retried. Recreate the automation step and run the workflow again.');
            }
            $locked->update(['status' => 'sending', 'error' => null]);
            return $locked;
        });

        try {
            $templates = (new WhatifyClient($shop))->templates();
            $template = collect($templates)->first(fn($t) => strcasecmp((string) ($t['name'] ?? ''), (string) $log->request_payload['template_name']) === 0
                && strtolower((string) ($t['status'] ?? '')) === 'approved');
            if (!$template) {
                $log->update(['status' => 'skipped_not_approved', 'error' => 'Template is not currently approved in Whatify. Approve it, then retry.']);
                return back()->with('error', 'Template "'.$log->request_payload['template_name'].'" is not approved in Whatify. Approve it first, then retry.');
            }

            $purpose = strtolower((string) ($template['category'] ?? 'utility')) === 'marketing' ? 'marketing' : 'order_update';
            $hash = $log->phone_hash ?: PhoneIdentity::hash($log->request_payload['phone'] ?? null);
            $consented = $purpose === 'marketing'
                ? $shop->whatsappMarketingConsents()->active()->where('phone_hash', $hash)->exists()
                : $shop->whatsappConsents()->active()->where('phone_hash', $hash)->exists();
            if (!$consented) {
                $log->update(['status' => 'skipped_no_'.($purpose === 'marketing' ? 'marketing' : 'opt_in'),
                    'error' => 'No active WhatsApp '.($purpose === 'marketing' ? 'marketing' : 'order-update').' consent on manual retry.']);
                return back()->with('error', 'Customer consent is not active, so WhatsApp policy blocks this send. Send it yourself and use "Mark manually sent".');
            }

            $result = (new WhatifyClient($shop))->sendTemplate($log->request_payload);
            $log->update([
                'provider_message_id' => isset($result['message_id']) ? (string) $result['message_id'] : $log->provider_message_id,
                'status' => $result['status'] ?? 'sent',
                'response_payload' => $result,
                'error' => null,
            ]);
            return back()->with('success', 'Message re-sent via Whatify (template "'.$log->template_name.'"). New status: '.($result['status'] ?? 'sent').'.');
        } catch (ConnectionException $e) {
            $log->update(['status' => 'unknown', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            return back()->with('error', 'Whatify could not be reached, so delivery is uncertain. Check Whatify before retrying again to avoid duplicate messages.');
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            return back()->with('error', 'Retry failed: '.mb_substr($e->getMessage(), 0, 300));
        }
    }

    /** Record that the merchant sent the message outside the app. */
    public function markManual(Request $r, MessageLog $messageLog)
    {
        $shop = $r->attributes->get('currentShop');
        abort_unless($messageLog->shop_id === $shop->id, 404);
        $log = DB::transaction(function () use ($messageLog) {
            $locked = MessageLog::query()->lockForUpdate()->findOrFail($messageLog->id);
            if (in_array($locked->status, self::DELIVERED, true)) {
                throw new RuntimeException('This message is already marked as delivered or manually resolved.');
            }
            $previous = is_array($locked->response_payload) ? $locked->response_payload : [];
            $locked->update([
                'status' => 'manual',
                'error' => null,
                'response_payload' => $previous + ['manual_resolution' => ['resolved_at' => now()->toIso8601String(), 'by' => 'merchant']],
            ]);
            return $locked;
        });
        return back()->with('success', 'Message "'.$log->template_name.'" marked as manually sent. It will not be retried automatically.');
    }
}
