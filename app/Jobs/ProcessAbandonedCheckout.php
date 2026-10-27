<?php
namespace App\Jobs;

use App\Models\AbandonedCheckout;
use App\Models\WorkflowExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessAbandonedCheckout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 5;
    public function __construct(public int $checkoutId) {}
    public function backoff(): array { return [30, 120, 600, 1800]; }

    public function handle(): void
    {
        $checkout = AbandonedCheckout::with('shop')->find($this->checkoutId);
        if (!$checkout || $checkout->status !== 'open' || !$checkout->shop?->installed) return;
        $activityAt = $checkout->last_activity_at ?? $checkout->created_at ?? now();
        $eligibleAt = $activityAt->copy()->addMinutes((int) config('services.shopify.abandoned_checkout_delay_minutes', 60));
        if (now()->lt($eligibleAt)) {
            self::dispatch($checkout->id)->delay($eligibleAt);
            return;
        }

        $retryAt = null;
        $executionIds = DB::transaction(function () use ($checkout,&$retryAt) {
            $locked = AbandonedCheckout::query()->lockForUpdate()->with('shop')->find($checkout->id);
            if (!$locked || $locked->status !== 'open' || !$locked->shop?->installed) return [];
            $lockedActivityAt = $locked->last_activity_at ?? $locked->created_at ?? now();
            $lockedEligibleAt = $lockedActivityAt->copy()->addMinutes((int) config('services.shopify.abandoned_checkout_delay_minutes',60));
            if (now()->lt($lockedEligibleAt)) { $retryAt = $lockedEligibleAt; return []; }
            if ($locked->completed_at) {
                $locked->update(['status'=>'recovered']);
                return [];
            }
            $phoneHash = $locked->phone_hash;
            if (!$phoneHash || !$locked->recovery_url || !$locked->shop->whatsappMarketingConsents()->active()->where('phone_hash',$phoneHash)->exists()) {
                $locked->update(['status'=>'suppressed_no_marketing_opt_in','abandoned_at'=>now(),'phone'=>null,'customer_first_name'=>null,'item_summary'=>null,'recovery_url'=>null]);
                return [];
            }
            $sourceEventId = $locked->creation_webhook_event_id;
            if (!$sourceEventId) {
                $locked->update(['status'=>'suppressed_missing_source_event','abandoned_at'=>now(),'phone'=>null,'customer_first_name'=>null,'item_summary'=>null,'recovery_url'=>null]);
                return [];
            }
            $locked->update(['status'=>'abandoned','abandoned_at'=>now()]);
            $payload = [
                'checkout'=>[
                    'customer'=>[
                        'id'=>$locked->shopify_customer_id,
                        'first_name'=>$locked->customer_first_name,
                        'phone'=>$locked->phone,
                    ],
                    'item_summary'=>$locked->item_summary,
                    'abandoned_checkout_url'=>$locked->recovery_url,
                    'total_price'=>$locked->total_price,
                    'currency'=>$locked->currency,
                    'shop_name'=>$locked->shop->shop_domain,
                ],
                '_whatify'=>[
                    'whatsapp_marketing_opted_in'=>true,
                    'abandoned_checkout_id'=>$locked->id,
                ],
            ];
            $ids = [];
            foreach ($locked->shop->workflows()->where('enabled',true)->with('publishedVersion')->get() as $workflow) {
                $version = $workflow->publishedVersion;
                if (!$version) continue;
                $trigger = collect($version->graph['nodes'] ?? [])->first(fn($node)=>(($node['type']??'')==='trigger' && (($node['config']['topic']??'')==='CHECKOUTS_CREATE')));
                if (!$trigger) continue;
                $execution = WorkflowExecution::firstOrCreate(
                    ['workflow_id'=>$workflow->id,'webhook_event_id'=>$sourceEventId],
                    [
                        'shop_id'=>$locked->shop_id,'workflow_version_id'=>$version->id,'trigger_topic'=>'CHECKOUTS_CREATE',
                        'shopify_customer_id'=>$locked->shopify_customer_id,'shopify_order_id'=>null,'customer_phone_hash'=>$phoneHash,
                        'trigger_payload'=>$payload,'current_node_id'=>$trigger['id'],'status'=>'queued',
                    ]
                );
                if ($execution->wasRecentlyCreated) $ids[] = $execution->id;
            }
            return $ids;
        }, 1);

        if ($retryAt) {
            self::dispatch($checkout->id)->delay($retryAt);
            return;
        }
        foreach ($executionIds as $id) RunWorkflowExecution::dispatch($id)->afterCommit();
    }
}
