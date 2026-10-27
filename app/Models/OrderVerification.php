<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderVerification extends Model {
 protected $fillable=['shop_id','shopify_order_id','shopify_customer_id','order_name','customer_name','customer_phone','customer_phone_hash','payment_method','order_total','currency','status','risk_score','risk_reasons','response','response_at'];
 protected $casts=['customer_phone'=>'encrypted','risk_reasons'=>'array','response_at'=>'datetime'];
 public function shop(){return $this->belongsTo(Shop::class);}
}
