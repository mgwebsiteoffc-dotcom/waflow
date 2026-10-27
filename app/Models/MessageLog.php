<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MessageLog extends Model {
 protected $fillable=['shop_id','workflow_execution_id','node_id','shopify_customer_id','provider_message_id','phone_hash','phone','template_name','status','request_payload','response_payload','error'];
 protected $casts=['phone'=>'encrypted','request_payload'=>'encrypted:array','response_payload'=>'encrypted:array'];
 public function shop(){return $this->belongsTo(Shop::class);}
 public function execution(){return $this->belongsTo(WorkflowExecution::class,'workflow_execution_id');}
}
