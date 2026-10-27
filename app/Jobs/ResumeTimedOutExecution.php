<?php
namespace App\Jobs;
use App\Models\WorkflowExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class ResumeTimedOutExecution implements ShouldQueue {
 use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
 public function __construct(public int $executionId){}
 public function handle():void{$x=WorkflowExecution::find($this->executionId);if(!$x||$x->status!=='waiting')return;$p=$x->trigger_payload??[];$p['_workflow_reply']='timeout';$x->update(['trigger_payload'=>$p,'status'=>'queued']);RunWorkflowExecution::dispatch($x->id);}
}
