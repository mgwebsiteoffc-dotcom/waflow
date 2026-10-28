<?php
namespace App\Http\Controllers;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Support\AutomationPresets;
use App\Support\SetupHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class WorkflowController extends Controller {
 private function shop(Request $r){return $r->attributes->get('currentShop');}
 private function approvedTemplates($shop):array{if(!$shop->whatify_api_key)return [];try{return array_values(array_filter((new \App\Services\WhatifyClient($shop))->templates(),fn($t)=>strtolower($t['status']??'')==='approved'));}catch(\Throwable $e){return [];}}
 public function presets(Request $r){
  $shop=$this->shop($r);
  $templates=$this->approvedTemplates($shop);
  $byCategory=['utility'=>[],'marketing'=>[]];
  foreach($templates as $t){$category=strtolower($t['category']??'utility');$byCategory[$category]??=[];$byCategory[$category][]=$t;}
  $presets=AutomationPresets::all();
  $installed=$shop->workflows()->whereNotNull('preset_key')->pluck('preset_key')->all();
  return view('automations.presets',compact('presets','byCategory','installed'));
 }
 public function fromPreset(Request $r){
  $shop=$this->shop($r);
  $data=$r->validate(['preset_key'=>'required|string|max:64','name'=>'nullable|string|max:120','templates'=>'nullable|array','templates.*'=>'nullable|string|max:190']);
  $preset=AutomationPresets::find($data['preset_key']);
  if(!$preset)throw ValidationException::withMessages(['preset_key'=>'Choose one of the predefined automation templates.']);
  $graph=AutomationPresets::graph($preset,$data['templates']??[]);
  $workflow=$shop->workflows()->create(['name'=>trim((string)($data['name']??''))?:$preset['name'],'description'=>$preset['description'],'preset_key'=>$preset['key'],'draft_graph'=>$graph,'enabled'=>false]);
  return redirect()->route('automations.edit',$workflow)->with('success','Predefined "'.$preset['name'].'" automation added. Confirm the WhatsApp template mapping, then Publish to switch it on.');
 }
 public function health(Request $r){
  $health=app(SetupHealth::class)->build($this->shop($r));
  return view('automations.health',compact('health'));
 }
 public function index(Request $r){$query=$this->shop($r)->workflows()->withCount('executions')->latest();if($term=$r->query('q'))$query->where('name','like','%'.$term.'%');$workflows=$query->paginate(15)->withQueryString();return view('automations.index',compact('workflows'));}
 public function create(Request $r){$workflow=$this->shop($r)->workflows()->create(['name'=>'New automation','draft_graph'=>$this->starterGraph()]);return redirect()->route('automations.edit',$workflow);}
 public function edit(Request $r,Workflow $workflow){abort_unless($workflow->shop_id===$this->shop($r)->id,404);$workflow->load('publishedVersion');$graph=$workflow->draft_graph??$workflow->publishedVersion?->graph??$this->starterGraph();$templates=[];$accounts=[];try{if($this->shop($r)->whatify_api_key){$client=new \App\Services\WhatifyClient($this->shop($r));$templates=$client->templates();$accounts=$client->accounts();}}catch(\Throwable $e){}$templates=array_values(array_filter($templates,fn($t)=>strtolower($t['status']??'')==='approved'));return view('automations.builder',compact('workflow','graph','templates','accounts'));}
 public function update(Request $r,Workflow $workflow){abort_unless($workflow->shop_id===$this->shop($r)->id,404);$data=$r->validate(['name'=>'required|string|max:120','description'=>'nullable|string|max:1000','graph'=>'required|array','graph.nodes'=>'required|array|min:1|max:150','graph.nodes.*.id'=>'required|string|max:100','graph.nodes.*.type'=>'required|string|in:trigger,condition,send_template,wait_for_reply,delay,add_order_tag,notify_staff,cancel_order,stop','graph.nodes.*.title'=>'required|string|max:120','graph.nodes.*.x'=>'nullable|numeric|min:0|max:5000','graph.nodes.*.y'=>'nullable|numeric|min:0|max:10000','graph.nodes.*.config'=>'nullable|array','graph.nodes.*.config.topic'=>'nullable|string|in:ORDERS_CREATE,FULFILLMENTS_CREATE,CHECKOUTS_CREATE','graph.nodes.*.config.message_purpose'=>'nullable|string|in:order_update,marketing','graph.nodes.*.config.whatsapp_account_id'=>'nullable|integer','graph.edges'=>'present|array|max:300','graph.edges.*.source'=>'required|string|max:100','graph.edges.*.target'=>'required|string|max:100','graph.edges.*.sourceHandle'=>'nullable|string|in:true,false,yes,no,timeout']);$nodeIds=collect($data['graph']['nodes'])->pluck('id')->all();foreach($data['graph']['edges'] as $edge)if(!in_array($edge['source'],$nodeIds,true)||!in_array($edge['target'],$nodeIds,true))throw ValidationException::withMessages(['graph'=>'Workflow connections must reference existing steps.']);$workflow->update(['name'=>$data['name'],'description'=>$data['description']??null,'draft_graph'=>$data['graph']]);return response()->json(['saved'=>true,'updated_at'=>$workflow->fresh()->updated_at->toIso8601String()]);}
 public function publish(Request $r,Workflow $workflow){abort_unless($workflow->shop_id===$this->shop($r)->id,404);if(!$workflow->draft_graph)throw ValidationException::withMessages(['graph'=>'Save a workflow draft before publishing.']);$graph=$workflow->draft_graph;if(!collect($graph['nodes']??[])->contains(fn($n)=>($n['type']??'')==='trigger'))throw ValidationException::withMessages(['graph'=>'Add a trigger node before publishing.']);
  $nodes=collect($graph['nodes']??[]);$hasCheckoutTrigger=$nodes->contains(fn($n)=>(($n['type']??'')==='trigger'&&(($n['config']['topic']??'')==='CHECKOUTS_CREATE')));$hasMarketing=$nodes->contains(fn($n)=>(($n['type']??'')==='send_template'&&(($n['config']['message_purpose']??'order_update')==='marketing')));if($hasMarketing&&!$hasCheckoutTrigger)throw ValidationException::withMessages(['graph'=>'Marketing-purpose WhatsApp steps require a checkout recovery trigger.']);
  DB::transaction(function()use($workflow,$graph){$w=Workflow::whereKey($workflow->id)->lockForUpdate()->firstOrFail();$v=(int)$w->versions()->max('version')+1;$version=$w->versions()->create(['version'=>$v,'graph'=>$graph,'published_at'=>now()]);$w->update(['published_version_id'=>$version->id,'enabled'=>true]);});return back()->with('success','Workflow published. New executions will use this immutable version.');}
 public function toggle(Request $r,Workflow $workflow){abort_unless($workflow->shop_id===$this->shop($r)->id,404);if(!$workflow->published_version_id)return back()->with('error','Publish the workflow before turning it on.');$workflow->update(['enabled'=>!$workflow->enabled]);return back()->with('success',$workflow->enabled?'Workflow enabled.':'Workflow paused.');}
 public function destroy(Request $r,Workflow $workflow){
  abort_unless($workflow->shop_id===$this->shop($r)->id,404);
  $name=$workflow->name;
  DB::transaction(function()use($workflow){
   $workflow->executions()->whereIn('status',['queued','running','waiting'])->update(['status'=>'cancelled','finished_at'=>now(),'last_error'=>'Automation deleted by merchant.']);
   $workflow->delete();
  });
  return redirect()->route('automations.index')->with('success','Automation "'.$name.'" deleted. Its workflow versions and run history were removed; WhatsApp message logs are retained for audit.');
 }
 public function executions(Request $r){$runs=$this->shop($r)->executions()->with('workflow','events')->latest()->paginate(25);return view('automations.executions',compact('runs'));}
 private function starterGraph():array{return ['nodes'=>[
  ['id'=>'trigger-order','type'=>'trigger','title'=>'Order created','x'=>360,'y'=>20,'config'=>['topic'=>'ORDERS_CREATE']],
  ['id'=>'cod-condition','type'=>'condition','title'=>'Check payment method','x'=>360,'y'=>150,'config'=>['field'=>'payment_gateway_names','operator'=>'contains','value'=>'cash on delivery']],
  ['id'=>'consent-condition','type'=>'condition','title'=>'Check WhatsApp opt-in','x'=>360,'y'=>280,'config'=>['field'=>'_whatify.whatsapp_opted_in','operator'=>'equals','value'=>true]],
  ['id'=>'send-cod','type'=>'send_template','title'=>'Send COD confirmation','x'=>360,'y'=>410,'config'=>['template_name'=>'cod_order_confirmation','body_params'=>['{{order.customer.first_name}}','{{order.name}}','{{order.total_price}}'],'phone'=>'{{order.customer.phone}}']],
  ['id'=>'wait-reply','type'=>'wait_for_reply','title'=>'Wait for customer reply','x'=>360,'y'=>540,'config'=>['timeout_minutes'=>120,'yes_words'=>['yes','confirm'],'no_words'=>['no','cancel']]],
  ['id'=>'confirm-tag','type'=>'add_order_tag','title'=>'Mark confirmed','x'=>120,'y'=>620,'config'=>['tag'=>'whatify_cod_confirmed']],
  ['id'=>'review-tag','type'=>'add_order_tag','title'=>'Flag for staff review','x'=>600,'y'=>620,'config'=>['tag'=>'whatify_needs_review']],
  ['id'=>'end','type'=>'stop','title'=>'End workflow','x'=>120,'y'=>760,'config'=>[]]
 ],'edges'=>[
  ['source'=>'trigger-order','target'=>'cod-condition'],['source'=>'cod-condition','target'=>'consent-condition','sourceHandle'=>'true'],['source'=>'cod-condition','target'=>'end','sourceHandle'=>'false'],['source'=>'consent-condition','target'=>'send-cod','sourceHandle'=>'true'],['source'=>'consent-condition','target'=>'end','sourceHandle'=>'false'],['source'=>'send-cod','target'=>'wait-reply'],['source'=>'wait-reply','target'=>'confirm-tag','sourceHandle'=>'yes'],['source'=>'wait-reply','target'=>'review-tag','sourceHandle'=>'no'],['source'=>'wait-reply','target'=>'review-tag','sourceHandle'=>'timeout'],['source'=>'confirm-tag','target'=>'end'],['source'=>'review-tag','target'=>'end']
 ]];}
}
