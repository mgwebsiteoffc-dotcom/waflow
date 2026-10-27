<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WorkflowExecution extends Model {
 protected $fillable=['shop_id','workflow_id','workflow_version_id','webhook_event_id','trigger_topic','shopify_customer_id','shopify_order_id','customer_phone_hash','trigger_payload','current_node_id','status','scheduled_at','started_at','finished_at','last_error'];
 protected $casts=['trigger_payload'=>'encrypted:array','scheduled_at'=>'datetime','started_at'=>'datetime','finished_at'=>'datetime'];
 public function shop(){return $this->belongsTo(Shop::class);}
 public function workflow(){return $this->belongsTo(Workflow::class);}
 public function version(){return $this->belongsTo(WorkflowVersion::class,'workflow_version_id');}
 public function events(){return $this->hasMany(ExecutionEvent::class);}
}
