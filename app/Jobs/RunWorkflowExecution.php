<?php
namespace App\Jobs;
use App\Models\WorkflowExecution;
use App\Services\WorkflowRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class RunWorkflowExecution implements ShouldQueue {
 use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
 public int $tries=5;
 public function __construct(public int $executionId){}
 public function backoff():array{return [10,30,90,300];}
 public function handle(WorkflowRunner $runner):void{$runner->run($this->executionId);}
 public function failed(?\Throwable $e):void{if($x=WorkflowExecution::find($this->executionId)){$x->update(['status'=>'failed','finished_at'=>now(),'last_error'=>mb_substr($e?->getMessage()??'Workflow job failed',0,2000)]);$x->events()->create(['event_type'=>'workflow','status'=>'failed','error'=>$e?->getMessage()]);}}
}
