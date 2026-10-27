<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CustomerReply extends Model {
 protected $fillable=['shop_id','workflow_execution_id','shopify_customer_id','provider_event_id','phone_hash','phone','message_body','mapped_order_id','received_at'];
 protected $casts=['phone'=>'encrypted','message_body'=>'encrypted','received_at'=>'datetime'];
 public function shop(){return $this->belongsTo(Shop::class);}
}
