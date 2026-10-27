<?php
namespace App\Http\Controllers;

use App\Jobs\ProcessShopifyWebhook;
use App\Jobs\ProcessWhatifyReply;
use App\Models\AbandonedCheckout;
use App\Models\CustomerReply;
use App\Models\Shop;
use App\Models\WebhookEvent;
use App\Models\WhatsAppConsent;
use App\Models\WhatsAppMarketingConsent;
use App\Services\PhoneIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class ApiWebhookController extends Controller
{
    public function shopify(Request $request)
    {
        $secret = config('services.shopify.api_secret');
        abort_unless($secret, 503, 'Shopify webhook secret missing.');
        $raw = $request->getContent();
        $signature = $request->header('X-Shopify-Hmac-Sha256', '');
        $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));
        abort_unless($signature && hash_equals($expected, $signature), 401, 'Invalid Shopify webhook signature.');
        $topic = strtoupper(str_replace('/', '_', $request->header('X-Shopify-Topic', '')));
        $domain = strtolower($request->header('X-Shopify-Shop-Domain', ''));
        // Compliance webhooks can arrive after app/uninstall, so retain lookup for known shops.
        $shop = Shop::where('shop_domain', $domain)->first();
        if (!$shop) return response()->json(['received'=>true,'ignored'=>'unknown shop']);
        $id = $request->header('X-Shopify-Webhook-Id');
        if (!$id) return response()->json(['error'=>'Missing webhook id'], 400);
        try {
            $event = WebhookEvent::create(['shop_id'=>$shop->id,'webhook_id'=>$id,'topic'=>$topic,'payload'=>json_decode($raw,true)??[]]);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') throw $e;
            return response()->json(['received'=>true,'duplicate'=>true]);
        }
        ProcessShopifyWebhook::dispatch($event->id)->afterCommit();
        return response()->json(['received'=>true]);
    }

    public function whatify(Request $request)
    {
        $secret = config('services.whatify.webhook_secret');
        abort_unless($secret, 503, 'Inbound webhook signature secret is not configured.');
        $raw = $request->getContent();
        $signature = $request->header('X-Whatify-Signature', '');
        $hex = hash_hmac('sha256', $raw, $secret);
        $base64 = base64_encode(hash_hmac('sha256', $raw, $secret, true));
        abort_unless($signature && (hash_equals($hex,$signature) || hash_equals($base64,$signature)), 401, 'Invalid Whatify signature.');
        $data = json_decode($raw, true) ?? [];
        $phone = PhoneIdentity::normalize((string) ($data['phone'] ?? $data['from'] ?? ''));
        $button = $data['button_reply'] ?? [];
        $buttonText = is_array($button) ? ($button['id'] ?? $button['title'] ?? '') : (string) $button;
        $text = trim((string) ($data['text'] ?? $data['message'] ?? $buttonText));
        if (!$phone || !$text) return response()->json(['received'=>false,'reason'=>'phone or reply missing'], 422);
        $hash = PhoneIdentity::hash($phone);
        $keyword = mb_strtolower(preg_replace('/\s+/', ' ', $text));
        if (in_array($keyword, ['stop','unsubscribe','opt out','opt-out','end','quit'], true)) {
            $updated = WhatsAppConsent::query()->where('phone_hash',$hash)->where('status','opted_in')
                ->update(['status'=>'opted_out','source'=>'whatsapp_stop','opted_out_at'=>now(),'updated_at'=>now()]);
            $marketingUpdated = WhatsAppMarketingConsent::query()->where('phone_hash',$hash)->where('status','opted_in')
                ->update(['status'=>'opted_out','source'=>'whatsapp_stop','opted_out_at'=>now(),'updated_at'=>now()]);
            AbandonedCheckout::query()->where('phone_hash',$hash)->where('status','!=','recovered')
                ->update(['status'=>'suppressed_opt_out','phone'=>null,'customer_first_name'=>null,'item_summary'=>null,'recovery_url'=>null,'updated_at'=>now()]);
            \App\Models\WorkflowExecution::query()->whereIn('status',['queued','running','waiting'])->where('customer_phone_hash',$hash)->get()
                ->each(fn($execution)=>$execution->update(['status'=>'cancelled','finished_at'=>now(),'last_error'=>'Customer opted out via WhatsApp STOP.','trigger_payload'=>['_whatify'=>['opted_out'=>true]]]));
            return response()->json(['received'=>true,'matched'=>false,'opted_out'=>$updated,'marketing_opted_out'=>$marketingUpdated]);
        }
        $matches = \App\Models\WorkflowExecution::where('status','waiting')->where('customer_phone_hash',$hash)
            ->whereHas('shop',fn($query)=>$query->whereNotNull('whatify_api_key'))->limit(2)->get();
        if ($matches->count() !== 1) return response()->json(['received'=>true,'matched'=>false]);
        $execution = $matches->first();
        try {
            $reply = CustomerReply::create(['shop_id'=>$execution->shop_id,'workflow_execution_id'=>$execution->id,'shopify_customer_id'=>$execution->shopify_customer_id,'provider_event_id'=>$data['event_id']??$data['id']??null,'phone_hash'=>$hash,'phone'=>$phone,'message_body'=>$text,'mapped_order_id'=>$execution->shopify_order_id,'received_at'=>now()]);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') throw $e;
            return response()->json(['received'=>true,'duplicate'=>true]);
        }
        ProcessWhatifyReply::dispatch($reply->id)->afterCommit();
        return response()->json(['received'=>true,'matched'=>true]);
    }
}
