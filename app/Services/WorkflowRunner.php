<?php
namespace App\Services;
use App\Jobs\ResumeTimedOutExecution;
use App\Models\AbandonedCheckout;
use App\Models\MessageLog;
use App\Models\WorkflowExecution;
use App\Models\WhatsAppMarketingConsent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use RuntimeException;
class WorkflowRunner {
 public function __construct(private ShopifyAdmin $shopify){}
 public function run(int $id):void{
  $x=WorkflowExecution::with(['shop','version'])->find($id);if(!$x||in_array($x->status,['completed','failed','waiting','cancelled'],true))return;
  $graph=$x->version->graph??[];$nodes=collect($graph['nodes']??[])->keyBy('id');$edges=$graph['edges']??[];$payload=$x->trigger_payload??[];
  if(!$x->started_at)$x->started_at=now();$x->status='running';$x->save();
  try{
   for($steps=0;$steps<40;$steps++){
    $node=$nodes->get($x->current_node_id);if(!$node){$this->complete($x);return;}$type=$node['type']??'unknown';$cfg=$node['config']??[];$branch=null;
    if($type==='trigger'){$this->event($x,$node,'trigger','success');}
    elseif($type==='condition'){$branch=$this->condition($cfg,$payload)?'true':'false';$this->event($x,$node,'condition','success',['branch'=>$branch]);}
    elseif($type==='send_template'){$sent=$this->sendTemplate($x,$node,$cfg,$payload);$this->event($x,$node,'whatsapp',$sent?'success':'skipped',['template'=>$cfg['template_name']??null,'reason'=>$sent?null:'Missing active opt-in or template is not currently approved']);}
    elseif($type==='add_order_tag'){$this->addTag($x,$node,$cfg,$payload);$this->event($x,$node,'shopify_action','success',['action'=>'add_order_tag']);}
    elseif($type==='wait_for_reply'){
     if(isset($payload['_workflow_reply'])){$branch=$payload['_workflow_reply'];unset($payload['_workflow_reply']);$x->trigger_payload=$payload;$x->save();$this->event($x,$node,'wait_for_reply',$branch==='timeout'?'timeout':'resolved',['branch'=>$branch]);}
     else{$x->status='waiting';$x->current_node_id=$node['id'];$x->save();$this->event($x,$node,'wait_for_reply','waiting',['timeout_minutes'=>(int)($cfg['timeout_minutes']??120)]);ResumeTimedOutExecution::dispatch($x->id)->delay(now()->addMinutes(max(1,(int)($cfg['timeout_minutes']??120))));return;}
    }
    elseif($type==='delay'){$minutes=max(1,min(10080,(int)($cfg['minutes']??5)));$target=$this->next($edges,$node['id']);if(!$target){$this->complete($x);return;}$x->update(['status'=>'queued','current_node_id'=>$target['target'],'scheduled_at'=>now()->addMinutes($minutes)]);$this->event($x,$node,'delay','scheduled',['minutes'=>$minutes]);self::dispatchRun($x->id,$minutes);return;}
    elseif($type==='cancel_order'){$this->event($x,$node,'shopify_action','skipped',['reason'=>'Automatic cancellation is disabled by safety policy.']);}
    elseif($type==='notify_staff'){$this->event($x,$node,'notification','logged',['message'=>$cfg['message']??'Order needs staff review']);}
    elseif($type==='stop'){$this->event($x,$node,'stop','success');$this->complete($x);return;}
    else{$this->event($x,$node,'unknown','skipped',['reason'=>'Unsupported node type']);}
    $next=$this->next($edges,$node['id'],$branch);if(!$next){$this->complete($x);return;}$x->current_node_id=$next['target'];$x->scheduled_at=null;$x->save();
   }
   throw new RuntimeException('Workflow exceeded the 40-step execution safety limit.');
  }catch(\Throwable $e){$x->update(['status'=>'queued','last_error'=>mb_substr($e->getMessage(),0,2000)]);$x->events()->create(['node_id'=>$x->current_node_id,'event_type'=>'node','status'=>'error','error'=>mb_substr($e->getMessage(),0,2000)]);throw $e;}
 }
 private static function dispatchRun(int $id,int $minutes):void{\App\Jobs\RunWorkflowExecution::dispatch($id)->delay(now()->addMinutes($minutes));}
 private function complete(WorkflowExecution $x):void{$x->update(['status'=>'completed','finished_at'=>now(),'current_node_id'=>null,'scheduled_at'=>null]);}
 private function event(WorkflowExecution $x,array $node,string $type,string $status,array $details=[]):void{$x->events()->create(['node_id'=>$node['id']??null,'event_type'=>$type,'status'=>$status,'details'=>$details?:null]);}
 private function next(array $edges,string $id,?string $branch=null):?array{
  $out=array_values(array_filter($edges,fn($e)=>($e['source']??null)===$id));if($branch!==null){foreach($out as $edge)if(($edge['sourceHandle']??null)===$branch)return $edge;}
  foreach($out as $edge)if(empty($edge['sourceHandle']))return $edge;return null;
 }
 private function value(string $path,array $payload):mixed{$path=preg_replace('/^order\./','',$path);return data_get($payload,$path);}
 private function resolve(mixed $value,array $payload):mixed{if(!is_string($value))return $value;return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',function($m)use($payload){$v=$this->value($m[1],$payload);return is_scalar($v)?(string)$v:'';},$value);}
 private function condition(array $cfg,array $payload):bool{$actual=$this->value((string)($cfg['field']??''),$payload);$expected=$this->resolve($cfg['value']??'',$payload);$op=$cfg['operator']??'equals';$a=is_array($actual)?strtolower(implode(', ',$actual)):strtolower((string)$actual);$b=strtolower((string)$expected);return match($op){'contains'=>str_contains($a,$b),'not_contains'=>!str_contains($a,$b),'greater_than'=>(float)$actual>(float)$expected,'less_than'=>(float)$actual<(float)$expected,'not_equals'=>$a!==$b,default=>$a===$b};}
 private function sendTemplate(WorkflowExecution $x,array $node,array $cfg,array $payload):bool{
  $purpose=(string)($cfg['message_purpose']??'order_update');
  if(!in_array($purpose,['order_update','marketing'],true))throw new RuntimeException('Unsupported WhatsApp message purpose.');
  $defaultPhone=$purpose==='marketing'?'{{checkout.customer.phone}}':'{{order.customer.phone}}';
  $phone=(string)$this->resolve($cfg['phone']??$defaultPhone,$payload);$phone=PhoneIdentity::normalize($phone);$template=(string)($cfg['template_name']??'');
  if(!$phone||strlen($phone)<8)throw new RuntimeException('Customer phone number missing or invalid; WhatsApp message not sent.');if(!$template)throw new RuntimeException('Choose an approved Whatify template before publishing.');
  $phoneHash=PhoneIdentity::hash($phone);$log=MessageLog::where('workflow_execution_id',$x->id)->where('node_id',$node['id'])->first();
  $marketingCheckout=null;
  if($purpose==='marketing'){
   $checkoutId=(int)data_get($payload,'_whatify.abandoned_checkout_id',0);
   $marketingCheckout=$checkoutId?AbandonedCheckout::where('shop_id',$x->shop_id)->whereKey($checkoutId)->first():null;
   $consented=$x->shop->whatsappMarketingConsents()->active()->where('phone_hash',$phoneHash)->exists();
   if(!$marketingCheckout||!$consented){
    $log??=MessageLog::create(['shop_id'=>$x->shop_id,'workflow_execution_id'=>$x->id,'node_id'=>$node['id'],'shopify_customer_id'=>$x->shopify_customer_id,'phone_hash'=>$phoneHash,'phone'=>$phone,'template_name'=>$template,'status'=>'skipped_no_marketing','error'=>'No active cart-recovery marketing opt-in.']);
    if(!in_array($log->status,['sent','delivered','read','accepted'],true))$log->update(['status'=>'skipped_no_marketing','error'=>'No active cart-recovery marketing opt-in.']);return false;
   }
  }elseif(!$x->shop->whatsappConsents()->active()->where('phone_hash',$phoneHash)->exists()){
   $log??=MessageLog::create(['shop_id'=>$x->shop_id,'workflow_execution_id'=>$x->id,'node_id'=>$node['id'],'shopify_customer_id'=>$x->shopify_customer_id,'phone_hash'=>$phoneHash,'phone'=>$phone,'template_name'=>$template,'status'=>'skipped_no_opt_in','error'=>'No active recorded WhatsApp order-update opt-in.']);
   if(!in_array($log->status,['sent','delivered','read','accepted'],true))$log->update(['status'=>'skipped_no_opt_in','error'=>'No active recorded WhatsApp order-update opt-in.']);return false;
  }
  $approved=\Illuminate\Support\Facades\Cache::remember('whatify.approved_templates.'.$x->shop_id,now()->addMinutes(3),function()use($x){return (new WhatifyClient($x->shop))->templates();});
  $isApproved=collect($approved)->contains(function($candidate)use($template,$purpose){$category=strtolower((string)($candidate['category']??''));$categoryMatches=$purpose==='marketing'?$category==='marketing':$category==='utility';return strcasecmp((string)($candidate['name']??''),$template)===0&&strtolower((string)($candidate['status']??''))==='approved'&&$categoryMatches;});
  if(!$isApproved){$log??=MessageLog::create(['shop_id'=>$x->shop_id,'workflow_execution_id'=>$x->id,'node_id'=>$node['id'],'shopify_customer_id'=>$x->shopify_customer_id,'phone_hash'=>$phoneHash,'phone'=>$phone,'template_name'=>$template,'status'=>'skipped_not_approved','error'=>'Template is not currently approved in Whatify.']);if(!in_array($log->status,['sent','delivered','read','accepted'],true))$log->update(['status'=>'skipped_not_approved','error'=>'Template is not currently approved in Whatify.']);return false;}
  $params=array_map(fn($v)=>(string)$this->resolve($v,$payload),$cfg['body_params']??[]);$request=['phone'=>$phone,'template_name'=>$template,'body_params'=>$params];if(!empty($cfg['whatsapp_account_id']))$request['whatsapp_account_id']=(int)$cfg['whatsapp_account_id'];
  if($log&&in_array($log->status,['sent','delivered','read','accepted'],true))return true;
  if($log&&in_array($log->status,['unknown','sending'],true))throw new RuntimeException('Message delivery is uncertain; not resent automatically to avoid duplicate WhatsApp messages. Check Whatify and resolve this run manually.');
  if($purpose==='marketing'){
   $claimed=DB::transaction(function()use($marketingCheckout,$x,$phoneHash){
    $checkout=AbandonedCheckout::query()->lockForUpdate()->where('shop_id',$x->shop_id)->find($marketingCheckout->id);
    if(!$checkout||$checkout->status!=='abandoned'||$checkout->completed_at||$checkout->marketing_claimed_at||$checkout->marketing_sent_at)return false;
    $activityAt=$checkout->last_activity_at??$checkout->created_at??now();if(now()->lt($activityAt->copy()->addMinutes((int)config('services.shopify.abandoned_checkout_delay_minutes',60))))return false;
    if(!$x->shop->whatsappMarketingConsents()->active()->where('phone_hash',$phoneHash)->exists())return false;
    $checkout->update(['marketing_claimed_at'=>now()]);return true;
   },1);
   if(!$claimed){
    if($log&&in_array($log->status,['sent','delivered','read','accepted'],true))return true;
    $log??=MessageLog::create(['shop_id'=>$x->shop_id,'workflow_execution_id'=>$x->id,'node_id'=>$node['id'],'shopify_customer_id'=>$x->shopify_customer_id,'phone_hash'=>$phoneHash,'phone'=>$phone,'template_name'=>$template,'status'=>'skipped_checkout_closed','error'=>'Checkout is no longer eligible or a recovery message was already claimed.']);
    if(!in_array($log->status,['unknown','sending','failed'],true))$log->update(['status'=>'skipped_checkout_closed','error'=>'Checkout is no longer eligible or a recovery message was already claimed.']);return false;
   }
  }
  if($purpose==='marketing'){
   $stillConsented=$x->shop->whatsappMarketingConsents()->active()->where('phone_hash',$phoneHash)->exists();
   $latestCheckout=AbandonedCheckout::where('shop_id',$x->shop_id)->whereKey($marketingCheckout->id)->first();
   $latestActivity=$latestCheckout?->last_activity_at??$latestCheckout?->created_at??now();
   $stillEligible=$latestCheckout&&$latestCheckout->status==='abandoned'&&$latestCheckout->marketing_claimed_at&&!$latestCheckout->completed_at&&!$latestCheckout->marketing_sent_at&&now()->gte($latestActivity->copy()->addMinutes((int)config('services.shopify.abandoned_checkout_delay_minutes',60)));
   if(!$stillConsented||!$stillEligible){
    $log??=MessageLog::create(['shop_id'=>$x->shop_id,'workflow_execution_id'=>$x->id,'node_id'=>$node['id'],'shopify_customer_id'=>$x->shopify_customer_id,'phone_hash'=>$phoneHash,'phone'=>$phone,'template_name'=>$template,'status'=>'skipped_no_marketing','error'=>'Marketing opt-in or checkout eligibility changed before send.']);
    if(!in_array($log->status,['sent','delivered','read','accepted','unknown','sending'],true))$log->update(['status'=>'skipped_no_marketing','error'=>'Marketing opt-in or checkout eligibility changed before send.']);return false;
   }
  }
  if(!$log)$log=MessageLog::create(['shop_id'=>$x->shop_id,'workflow_execution_id'=>$x->id,'node_id'=>$node['id'],'shopify_customer_id'=>$x->shopify_customer_id,'phone_hash'=>$phoneHash,'phone'=>$phone,'template_name'=>$template,'status'=>'queued','request_payload'=>$request]);
  $log->update(['status'=>'sending','error'=>null]);
  try{
   $result=(new WhatifyClient($x->shop))->sendTemplate($request);
   $log->update(['provider_message_id'=>isset($result['message_id'])?(string)$result['message_id']:null,'status'=>$result['status']??'sent','response_payload'=>$result]);
   if($purpose==='marketing')AbandonedCheckout::where('shop_id',$x->shop_id)->whereKey($marketingCheckout->id)->update(['status'=>'message_sent','marketing_sent_at'=>now(),'updated_at'=>now()]);
  }
  catch(\Illuminate\Http\Client\ConnectionException $e){$log->update(['status'=>'unknown','error'=>mb_substr($e->getMessage(),0,1000)]);throw $e;}
  catch(\Throwable $e){$log->update(['status'=>'failed','error'=>mb_substr($e->getMessage(),0,1000)]);throw $e;}
  return true;
 }
 private function addTag(WorkflowExecution $x,array $node,array $cfg,array $payload):void{$orderId=(string)($payload['id']??'');$tag=trim((string)$this->resolve($cfg['tag']??'',$payload));if(!$orderId||!$tag)throw new RuntimeException('Shopify order ID or tag is missing.');$gid=str_starts_with($orderId,'gid://')?$orderId:'gid://shopify/Order/'.$orderId;$data=$this->shopify->addOrderTag($x->shop,$gid,$tag);$errors=$data['tagsAdd']['userErrors']??[];if($errors)throw new RuntimeException($errors[0]['message']??'Shopify rejected order tag.');
  $verification=\App\Models\OrderVerification::where('shop_id',$x->shop_id)->where('shopify_order_id',$x->shopify_order_id)->first();if($verification&&$tag==='whatify_cod_confirmed')$verification->update(['status'=>'confirmed','response'=>'whatsapp_confirmed','response_at'=>now()]);elseif($verification&&$tag==='whatify_needs_review')$verification->update(['status'=>'review']);
 }
}
