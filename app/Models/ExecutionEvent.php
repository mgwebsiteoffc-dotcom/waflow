<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ExecutionEvent extends Model {
 protected $fillable=['workflow_execution_id','node_id','event_type','status','details','error'];
 protected $casts=['details'=>'array'];
 public function execution(){return $this->belongsTo(WorkflowExecution::class,'workflow_execution_id');}
}
