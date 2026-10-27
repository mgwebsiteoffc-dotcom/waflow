<?php
namespace App\Jobs;

use App\Models\AbandonedCheckout;
use App\Models\OrderVerification;
use App\Models\PrivacyRequest;
use App\Models\Shop;
use App\Models\WebhookEvent;
use App\Models\WhatsAppConsent;
use App\Models\WhatsAppMarketingConsent;
use App\Models\WorkflowExecution;
use App\Services\CheckoutIdentity;
use App\Services\PhoneIdentity;
use App\Services\ShopifyAdmin;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessShopifyWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 5;
    public function __construct(public int $eventId) {}
    public function backoff(): array { return [10, 30, 90, 300]; }

    public function handle(ShopifyAdmin $admin): void
    {
        $event = WebhookEvent::with('shop')->find($this->eventId);
        if (!$event || $event->processed_at) return;
        $shop = $event->shop;
        if (!$shop) return;
        $payload = $event->payload ?? [];

        try {
            if ($event->topic === 'APP_UNINSTALLED') {
                $shop->update(['installed'=>false,'access_token'=>null,'refresh_token'=>null,'access_token_expires_at'=>null,'refresh_token_expires_at'=>null,'whatify_api_key'=>null]);
                $event->update(['processed_at'=>now()]);
                return;
            }
            if ($event->topic === 'CUSTOMERS_DATA_REQUEST') {
                $this->exportCustomerData($shop, $payload, $event->id);
                $event->update(['processed_at'=>now()]);
                return;
            }
            if ($event->topic === 'CUSTOMERS_REDACT') {
                $this->redactCustomer($shop, $payload);
                $event->update(['processed_at'=>now()]);
                return;
            }
            if ($event->topic === 'SHOP_REDACT') {
                // Shopify requires deletion of all merchant data, including raw webhook payloads.
                DB::transaction(function () use ($shop) {
                    $shop->webhookEvents()->delete();
                    $shop->delete();
                });
                return;
            }
            if (in_array($event->topic, ['CHECKOUTS_CREATE','CHECKOUTS_UPDATE'], true)) {
                $this->upsertAbandonedCheckout($shop, $payload, $event);
                // The checkout is stored in minimized encrypted fields; discard the raw webhook body immediately.
                $event->update(['processed_at'=>now(),'payload'=>null]);
                return;
            }
            if ($event->topic === 'FULFILLMENTS_CREATE') {
                $payload = $this->orderContextForFulfillment($shop, $payload, $admin);
            }
            if ($event->topic === 'ORDERS_CREATE') {
                $this->upsertVerification($shop, $payload);
                $this->markCheckoutRecovered($shop, $payload);
            }
            $phone = PhoneIdentity::normalize($payload['customer']['phone'] ?? $payload['phone'] ?? '');
            $payload['_whatify']['whatsapp_opted_in'] = $phone !== '' && $shop->whatsappConsents()->active()->where('phone_hash', PhoneIdentity::hash($phone))->exists();
            $this->startMatchingWorkflows($shop, $event->topic, $payload, $event->id);
            $event->update(['processed_at'=>now()]);
        } catch (\Throwable $e) {
            $event->update(['processing_error'=>mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }
    }

    private function upsertAbandonedCheckout(Shop $shop, array $payload, WebhookEvent $event): void
    {
        $token = trim((string) ($payload['token'] ?? ''));
        $tokenHash = CheckoutIdentity::hash($token);
        if (!$tokenHash) return;

        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $phone = PhoneIdentity::normalize($customer['phone'] ?? $payload['phone'] ?? '');
        $customerId = $this->numericId((string) ($customer['id'] ?? ''));
        $checkout = AbandonedCheckout::where('shop_id',$shop->id)->where('checkout_token_hash',$tokenHash)->first();
        $phone = $phone ?: PhoneIdentity::normalize($checkout?->phone);
        $phoneHash = PhoneIdentity::hash($phone) ?: $checkout?->phone_hash;
        $optInSource = $this->marketingOptInSource(is_array($payload['note_attributes'] ?? null) ? $payload['note_attributes'] : []);
        $consentSourceReference = 'checkout:'.substr($tokenHash,0,16);
        $marketingConsent = $phoneHash ? WhatsAppMarketingConsent::where('shop_id',$shop->id)->where('phone_hash',$phoneHash)->first() : null;
        $checkoutCreatedAt = null;
        if (!empty($payload['created_at'])) {
            try { $checkoutCreatedAt = \Illuminate\Support\Carbon::parse($payload['created_at']); }
            catch (\Throwable) { $checkoutCreatedAt = null; }
        }
        // A stale affirmative attribute cannot undo STOP; re-opt-in must come from a checkout created afterward.
        $mayReOptIn = $marketingConsent?->status !== 'opted_out'
            || ($marketingConsent->opted_out_at && $checkoutCreatedAt && $checkoutCreatedAt->gt($marketingConsent->opted_out_at));
        if ($optInSource && $phoneHash && $phone && $mayReOptIn) {
            $consentValues = [
                'shopify_customer_id'=>$customerId ?: $checkout?->shopify_customer_id,
                'phone'=>$phone,
                'status'=>'opted_in',
                'source'=>'checkout_note_attribute:'.$optInSource,
                'source_reference'=>$consentSourceReference,
                'consented_at'=>$marketingConsent?->status === 'opted_in' ? ($marketingConsent->consented_at ?? now()) : now(),
                'opted_out_at'=>null,
            ];
            if ($marketingConsent) $marketingConsent->update($consentValues);
            else WhatsAppMarketingConsent::create(['shop_id'=>$shop->id,'phone_hash'=>$phoneHash] + $consentValues);
        }

        $completedAt = null;
        if (!empty($payload['completed_at'])) {
            try { $completedAt = \Illuminate\Support\Carbon::parse($payload['completed_at']); }
            catch (\Throwable) { $completedAt = now(); }
        }
        if ($completedAt) {
            $checkout = $checkout ?: AbandonedCheckout::firstOrCreate(['shop_id'=>$shop->id,'checkout_token_hash'=>$tokenHash],['status'=>'recovered','completed_at'=>$completedAt]);
            $checkout->update(['status'=>'recovered','completed_at'=>$completedAt,'recovery_url'=>null,'item_summary'=>null,'customer_first_name'=>null,'phone'=>null,'abandoned_at'=>null]);
            return;
        }
        if ($checkout?->status === 'recovered') {
            $checkout->update(['recovery_url'=>null,'item_summary'=>null,'customer_first_name'=>null,'phone'=>null]);
            return;
        }
        $hasConsent = $phoneHash && WhatsAppMarketingConsent::query()->active()->where('shop_id',$shop->id)->where('phone_hash',$phoneHash)->exists();
        if (!$hasConsent) {
            if ($checkout && !in_array($checkout->status,['message_sent','recovered','suppressed_opt_out'],true)) {
                $checkout->update(['status'=>'suppressed_no_marketing_opt_in','recovery_url'=>null,'item_summary'=>null,'customer_first_name'=>null,'phone'=>null,'phone_hash'=>$phoneHash]);
            }
            return;
        }
        if (!$checkout && !$this->hasEnabledCheckoutWorkflow($shop)) return;

        $recoveryUrl = $this->safeRecoveryUrl((string) ($payload['abandoned_checkout_url'] ?? '')) ?: $checkout?->recovery_url;
        if (!$recoveryUrl) return;
        $lineItems = is_array($payload['line_items'] ?? null) ? $payload['line_items'] : [];
        $summaryParts = [];
        foreach (array_slice($lineItems,0,3) as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title !== '') $summaryParts[] = $title . (!empty($item['quantity']) && (int)$item['quantity'] > 1 ? ' × '.(int)$item['quantity'] : '');
        }
        if (count($lineItems) > 3) $summaryParts[] = 'and '.(count($lineItems)-3).' more';
        $summary = mb_substr(implode(', ',$summaryParts),0,250);
        try { $lastActivity = isset($payload['updated_at']) ? \Illuminate\Support\Carbon::parse($payload['updated_at']) : now(); }
        catch (\Throwable) { $lastActivity = now(); }
        $checkout = DB::transaction(function () use ($shop,$tokenHash,$customerId,$phone,$phoneHash,$payload,$summary,$recoveryUrl,$lastActivity,$event) {
            $row = AbandonedCheckout::query()->where('shop_id',$shop->id)->where('checkout_token_hash',$tokenHash)->lockForUpdate()->first();
            $oldStatus = $row?->status;
            $sentAt = $row?->marketing_sent_at;
            $claimedAt = $row?->marketing_claimed_at;
            $status = ($oldStatus === 'message_sent' || $sentAt) ? 'message_sent' : ($claimedAt ? 'abandoned' : 'open');
            $values = [
                'shopify_customer_id'=>$customerId ?: ($row?->shopify_customer_id),
                'phone_hash'=>$phoneHash ?: ($row?->phone_hash),
                'phone'=>$phone ?: ($row?->phone),
                'customer_first_name'=>trim((string)($payload['customer']['first_name'] ?? '')) ?: ($row?->customer_first_name),
                'item_summary'=>$summary ?: ($row?->item_summary),
                'recovery_url'=>$recoveryUrl,
                'total_price'=>$payload['total_price'] ?? ($row?->total_price),
                'currency'=>$payload['currency'] ?? ($row?->currency),
                'status'=>$status,
                'creation_webhook_event_id'=>$row?->creation_webhook_event_id ?: $event->id,
                'last_activity_at'=>$lastActivity,
                'completed_at'=>$row?->completed_at,
                'abandoned_at'=>$claimedAt ? ($row?->abandoned_at ?? now()) : null,
                'marketing_claimed_at'=>$claimedAt,
                'marketing_sent_at'=>$sentAt,
            ];
            if ($row) { $row->update($values); return $row->fresh(); }
            return AbandonedCheckout::create(['shop_id'=>$shop->id,'checkout_token_hash'=>$tokenHash] + $values);
        },1);

        if ($checkout->status === 'open') {
            ProcessAbandonedCheckout::dispatch($checkout->id)
                ->delay(now()->addMinutes((int) config('services.shopify.abandoned_checkout_delay_minutes',60)))
                ->afterCommit();
        }
    }

    private function marketingOptInSource(array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            $key = strtolower(trim((string) ($attribute['name'] ?? $attribute['key'] ?? '')));
            $value = strtolower(trim((string) ($attribute['value'] ?? '')));
            if (in_array($key,['whatsapp_cart_recovery_opt_in','whatsapp_marketing_opt_in'],true)
                && in_array($value,['yes','true','1','on','opted_in','accepted'],true)) return $key;
        }
        return null;
    }

    private function safeRecoveryUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 4000) return null;
        $parts = parse_url($url);
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
        return $url;
    }

    private function hasEnabledCheckoutWorkflow(Shop $shop): bool
    {
        return $shop->workflows()->where('enabled',true)->with('publishedVersion')->get()->contains(function ($workflow) {
            return collect($workflow->publishedVersion?->graph['nodes'] ?? [])->contains(fn($node)=>(($node['type']??'')==='trigger' && (($node['config']['topic']??'')==='CHECKOUTS_CREATE')));
        });
    }

    private function markCheckoutRecovered(Shop $shop, array $order): void
    {
        $tokenHash = CheckoutIdentity::hash((string) ($order['checkout_token'] ?? ''));
        if (!$tokenHash) return;
        $checkout = AbandonedCheckout::firstOrCreate(
            ['shop_id'=>$shop->id,'checkout_token_hash'=>$tokenHash],
            ['status'=>'recovered','completed_at'=>now()]
        );
        $checkout->update(['status'=>'recovered','completed_at'=>$checkout->completed_at ?? now(),'recovery_url'=>null,'item_summary'=>null,'customer_first_name'=>null,'phone'=>null,'abandoned_at'=>null]);
        if ($checkout->creation_webhook_event_id) {
            $shop->executions()->where('webhook_event_id',$checkout->creation_webhook_event_id)->whereIn('status',['queued','running','waiting'])->get()->each(function ($execution) use ($checkout) {
                $execution->update(['status'=>'cancelled','finished_at'=>now(),'last_error'=>'Checkout completed before the recovery workflow finished.','trigger_payload'=>['_whatify'=>['abandoned_checkout_id'=>$checkout->id,'recovered'=>true]]]);
            });
        }
    }

    private function upsertVerification(Shop $shop, array $order): void
    {
        $orderId = $this->numericId((string) ($order['id'] ?? ''));
        if (!$orderId) return;
        $phone = PhoneIdentity::normalize($order['customer']['phone'] ?? $order['phone'] ?? '');
        $this->recordExplicitOptIn($shop, $order, $phone);
        $gateways = $order['payment_gateway_names'] ?? [];
        $payment = implode(', ', array_map('strval', is_array($gateways) ? $gateways : [$gateways]));
        $low = strtolower($payment);
        $isCod = str_contains($low, 'cash on delivery') || (bool) preg_match('/\bcod\b/', $low);
        $reasons = [];
        $score = 0;
        $total = (float) ($order['total_price'] ?? 0);
        $customer = $order['customer'] ?? [];
        $hash = PhoneIdentity::hash($phone);
        $hasConsent = $hash && $shop->whatsappConsents()->active()->where('phone_hash', $hash)->exists();

        if ($isCod && !$phone) { $score += 60; $reasons[] = 'No valid phone number on order'; }
        if ($isCod && $phone && !$hasConsent) $reasons[] = 'No recorded WhatsApp opt-in; no message sent';
        $threshold = (float) ($shop->settings['high_value_review_threshold'] ?? 50000);
        $highValue = $isCod && $total >= $threshold && $threshold > 0;
        if ($highValue) { $score += 25; $reasons[] = 'Order value exceeds configured review threshold'; }
        $status = !$isCod ? 'not_required' : ((!$phone || $score >= 60 || $highValue) ? 'review' : (!$hasConsent ? 'not_contacted' : 'pending'));

        OrderVerification::updateOrCreate(['shop_id'=>$shop->id,'shopify_order_id'=>$orderId], [
            'shopify_customer_id'=>isset($customer['id']) ? (string) $this->numericId((string) $customer['id']) : null,
            'order_name'=>$order['name'] ?? null,
            'customer_name'=>trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? '')) ?: null,
            'customer_phone'=>$phone ?: null,
            'customer_phone_hash'=>$hash,
            'payment_method'=>$payment ?: 'unknown',
            'order_total'=>$total,
            'currency'=>$order['currency'] ?? null,
            'status'=>$status,
            'risk_score'=>min(100,$score),
            'risk_reasons'=>$reasons,
        ]);
    }

    private function recordExplicitOptIn(Shop $shop, array $order, string $phone): void
    {
        $hash = PhoneIdentity::hash($phone);
        if (!$hash) return;
        $attributes = $order['note_attributes'] ?? [];
        $source = null;
        foreach ($attributes as $attribute) {
            $key = strtolower(trim((string) ($attribute['name'] ?? $attribute['key'] ?? '')));
            $value = strtolower(trim((string) ($attribute['value'] ?? '')));
            if (in_array($key, ['whatsapp_opt_in','whatsapp_consent','wa_opt_in'], true) && in_array($value, ['yes','true','1','on','opted_in','accepted'], true)) {
                $source = 'shopify_order_note_attribute:'.$key;
                break;
            }
        }
        if (!$source) {
            $tags = array_map('trim', explode(',', strtolower((string) ($order['tags'] ?? ''))));
            if (in_array('whatsapp_opt_in', $tags, true)) $source = 'merchant_tag:whatsapp_opt_in';
        }
        if (!$source) return;
        $customerId = $this->numericId((string) ($order['customer']['id'] ?? ''));
        $orderId = $this->numericId((string) ($order['id'] ?? ''));
        $consent = $shop->whatsappConsents()->where('phone_hash',$hash)->first();
        $orderCreatedAt = null;
        if (!empty($order['created_at'])) {
            try { $orderCreatedAt = \Illuminate\Support\Carbon::parse($order['created_at']); }
            catch (\Throwable) { $orderCreatedAt = null; }
        }
        // A delayed order webhook or stale tag must not reverse a previously received STOP.
        $mayReOptIn = $consent?->status !== 'opted_out'
            || ($consent->opted_out_at && $orderCreatedAt && $orderCreatedAt->gt($consent->opted_out_at));
        if (!$mayReOptIn) return;
        $values = [
            'shopify_customer_id'=>$customerId ?: null,
            'phone'=>$phone,
            'status'=>'opted_in',
            'source'=>$source,
            'source_reference'=>($order['name'] ?? null) ?: ($orderId ? 'order:'.$orderId : null),
            'consented_at'=>$consent?->status === 'opted_in' ? ($consent->consented_at ?? $orderCreatedAt ?? now()) : ($orderCreatedAt ?? now()),
            'opted_out_at'=>null,
        ];
        if ($consent) $consent->update($values);
        else $shop->whatsappConsents()->create(['phone_hash'=>$hash] + $values);
    }

    private function orderContextForFulfillment(Shop $shop, array $fulfillment, ShopifyAdmin $admin): array
    {
        $rawId = (string) ($fulfillment['order_id'] ?? '');
        $orderId = $this->numericId($rawId);
        if (!$orderId) throw new \RuntimeException('Fulfillment webhook did not contain a valid order_id.');
        $gid = str_starts_with($rawId, 'gid://') ? $rawId : 'gid://shopify/Order/'.$orderId;
        $data = $admin->request($shop, 'query OrderForFulfillment($id: ID!) { order(id: $id) { id name phone currencyCode totalPriceSet { shopMoney { amount currencyCode } } paymentGatewayNames tags customAttributes { key value } customer { id firstName lastName phone } } }', ['id'=>$gid]);
        $source = $data['order'] ?? null;
        if (!$source) throw new \RuntimeException('Shopify returned no order for the fulfillment webhook.');
        $customer = $source['customer'] ?? [];
        $money = $source['totalPriceSet']['shopMoney'] ?? [];
        $attrs = array_map(fn($item)=>['name'=>$item['key']??'','value'=>$item['value']??''], $source['customAttributes'] ?? []);
        return [
            'id'=>$orderId,
            'name'=>$source['name'] ?? null,
            'phone'=>$source['phone'] ?? null,
            'currency'=>$source['currencyCode'] ?? ($money['currencyCode'] ?? null),
            'total_price'=>$money['amount'] ?? null,
            'payment_gateway_names'=>$source['paymentGatewayNames'] ?? [],
            'tags'=>is_array($source['tags'] ?? null) ? implode(', ', $source['tags']) : ($source['tags'] ?? ''),
            'note_attributes'=>$attrs,
            'customer'=>[
                'id'=>$this->numericId((string) ($customer['id'] ?? '')),
                'first_name'=>$customer['firstName'] ?? '',
                'last_name'=>$customer['lastName'] ?? '',
                'phone'=>$customer['phone'] ?? null,
            ],
            '_fulfillment'=>$fulfillment,
        ];
    }

    private function startMatchingWorkflows(Shop $shop, string $topic, array $payload, int $webhookEventId): void
    {
        $customerId = isset($payload['customer']['id']) ? (string) $this->numericId((string) $payload['customer']['id']) : null;
        $phone = PhoneIdentity::normalize($payload['customer']['phone'] ?? $payload['phone'] ?? '');
        $hash = PhoneIdentity::hash($phone);
        $orderId = $this->numericId((string) ($payload['id'] ?? $payload['order_id'] ?? ''));
        foreach ($shop->workflows()->where('enabled',true)->with('publishedVersion')->get() as $workflow) {
            $version = $workflow->publishedVersion;
            if (!$version) continue;
            $trigger = collect($version->graph['nodes'] ?? [])->first(fn($node)=>(($node['type']??'')==='trigger' && (($node['config']['topic']??'')===$topic)));
            if (!$trigger) continue;
            $execution = WorkflowExecution::firstOrCreate(['workflow_id'=>$workflow->id,'webhook_event_id'=>$webhookEventId], [
                'shop_id'=>$shop->id,'workflow_version_id'=>$version->id,'trigger_topic'=>$topic,'shopify_customer_id'=>$customerId,
                'shopify_order_id'=>$orderId ?: null,'customer_phone_hash'=>$hash,'trigger_payload'=>$payload,'current_node_id'=>$trigger['id'],'status'=>'queued',
            ]);
            if ($execution->wasRecentlyCreated) RunWorkflowExecution::dispatch($execution->id);
        }
    }

    private function numericId(string $id): ?string
    {
        if (preg_match('/(\d+)$/', $id, $match)) return $match[1];
        return null;
    }

    private function exportCustomerData(Shop $shop, array $payload, int $eventId): void
    {
        $customer = $payload['customer'] ?? [];
        $id = (string) ($customer['id'] ?? '');
        if (!$id) return;
        $orders = $shop->verifications()->where('shopify_customer_id',$id)->get()->map(fn($order)=>['order_id'=>$order->shopify_order_id,'order_name'=>$order->order_name,'customer_name'=>$order->customer_name,'phone'=>$order->customer_phone,'payment_method'=>$order->payment_method,'total'=>$order->order_total,'currency'=>$order->currency,'verification_status'=>$order->status,'risk_score'=>$order->risk_score,'risk_reasons'=>$order->risk_reasons,'response'=>$order->response,'created_at'=>$order->created_at?->toIso8601String()])->all();
        $messages = $shop->messageLogs()->where('shopify_customer_id',$id)->get()->map(fn($message)=>['phone'=>$message->phone,'template'=>$message->template_name,'status'=>$message->status,'created_at'=>$message->created_at?->toIso8601String()])->all();
        $replies = $shop->customerReplies()->where('shopify_customer_id',$id)->get()->map(fn($reply)=>['phone'=>$reply->phone,'message'=>$reply->message_body,'received_at'=>$reply->received_at?->toIso8601String()])->all();
        $consents = $shop->whatsappConsents()->where('shopify_customer_id',$id)->get()->map(fn($consent)=>['phone'=>$consent->phone,'status'=>$consent->status,'source'=>$consent->source,'source_reference'=>$consent->source_reference,'consented_at'=>$consent->consented_at?->toIso8601String(),'opted_out_at'=>$consent->opted_out_at?->toIso8601String()])->all();
        $marketingConsents = $shop->whatsappMarketingConsents()->where('shopify_customer_id',$id)->get()->map(fn($consent)=>['phone'=>$consent->phone,'status'=>$consent->status,'source'=>$consent->source,'source_reference'=>$consent->source_reference,'consented_at'=>$consent->consented_at?->toIso8601String(),'opted_out_at'=>$consent->opted_out_at?->toIso8601String()])->all();
        $checkouts = $shop->abandonedCheckouts()->where('shopify_customer_id',$id)->get()->map(fn($checkout)=>['phone'=>$checkout->phone,'customer_first_name'=>$checkout->customer_first_name,'item_summary'=>$checkout->item_summary,'recovery_url'=>$checkout->recovery_url,'total_price'=>$checkout->total_price,'currency'=>$checkout->currency,'status'=>$checkout->status,'last_activity_at'=>$checkout->last_activity_at?->toIso8601String()])->all();
        PrivacyRequest::create(['shop_id'=>$shop->id,'webhook_event_id'=>$eventId,'shopify_customer_id'=>$id,'status'=>'ready','received_at'=>now(),'data_export'=>['shopify_customer_id'=>$id,'customer'=>$customer,'orders'=>$orders,'messages'=>$messages,'replies'=>$replies,'whatsapp_consents'=>$consents,'whatsapp_marketing_consents'=>$marketingConsents,'abandoned_checkouts'=>$checkouts,'generated_at'=>now()->toIso8601String()]]);
    }

    private function redactCustomer(Shop $shop, array $payload): void
    {
        $id = (string) ($payload['customer']['id'] ?? '');
        $hash = PhoneIdentity::hash($payload['customer']['phone'] ?? '');
        if (!$id && !$hash) return;
        $q = $shop->verifications();
        if ($id) $q->where('shopify_customer_id',$id); else $q->where('customer_phone_hash',$hash);
        $q->delete();
        if ($id) {
            $shop->messageLogs()->where('shopify_customer_id',$id)->delete();
            $shop->customerReplies()->where('shopify_customer_id',$id)->delete();
            $shop->privacyRequests()->where('shopify_customer_id',$id)->delete();
            $shop->whatsappConsents()->where('shopify_customer_id',$id)->delete();
            $shop->whatsappMarketingConsents()->where('shopify_customer_id',$id)->delete();
            $shop->abandonedCheckouts()->where('shopify_customer_id',$id)->delete();
        }
        $eq = $shop->executions();
        if ($id) $eq->where('shopify_customer_id',$id); else $eq->where('customer_phone_hash',$hash);
        $eq->delete();
        if ($hash) {
            $shop->messageLogs()->where('phone_hash',$hash)->delete();
            $shop->customerReplies()->where('phone_hash',$hash)->delete();
            $shop->whatsappConsents()->where('phone_hash',$hash)->delete();
            $shop->whatsappMarketingConsents()->where('phone_hash',$hash)->delete();
            $shop->abandonedCheckouts()->where('phone_hash',$hash)->delete();
        }
        // Remove retained encrypted raw payloads containing this customer from the replay window.
        foreach ($shop->webhookEvents()->get() as $webhook) {
            $raw = $webhook->payload ?? [];
            $rawId = (string) data_get($raw,'customer.id','');
            $rawPhone = data_get($raw,'customer.phone') ?? data_get($raw,'phone');
            $rawHash = PhoneIdentity::hash(is_string($rawPhone) ? $rawPhone : null);
            if (($id && $rawId === $id) || ($hash && $rawHash === $hash)) {
                $webhook->update(['payload'=>null]);
            }
        }
    }
}
