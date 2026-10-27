<?php
namespace App\Jobs;
use App\Models\CustomerReply;
use App\Models\WorkflowExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class ProcessWhatifyReply implements ShouldQueue {
 use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
 public function __construct(public int $replyId){}
 public function handle():void{
  $reply=CustomerReply::find($this->replyId);if(!$reply||!$reply->workflow_execution_id)return;$execution=WorkflowExecution::whereKey($reply->workflow_execution_id)->where('status','waiting')->first();if(!$execution)return;
  $graph=$execution->version->graph;$node=collect($graph['nodes']??[])->firstWhere('id',$execution->current_node_id);if(!$node)return;
  $text=mb_strtolower(trim($reply->message_body));$cfg=$node['config']??[];$yes=array_map('mb_strtolower',$cfg['yes_words']??['yes','confirm']);$no=array_map('mb_strtolower',$cfg['no_words']??['no','cancel']);$branch=in_array($text,$yes,true)?'yes':(in_array($text,$no,true)?'no':'timeout');
  $payload=$execution->trigger_payload??[];$payload['_workflow_reply']=$branch;$execution->update(['trigger_payload'=>$payload,'status'=>'queued']);$execution->events()->create(['node_id'=>$node['id'],'event_type'=>'customer_reply','status'=>$branch==='timeout'?'unrecognized':'received','details'=>['classification'=>$branch]]);RunWorkflowExecution::dispatch($execution->id);
 }
}
