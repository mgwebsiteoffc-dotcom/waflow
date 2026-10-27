<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PrivacyRequest extends Model {
 protected $fillable=['shop_id','webhook_event_id','shopify_customer_id','status','data_export','received_at','fulfilled_at'];
 protected $casts=['data_export'=>'encrypted:array','received_at'=>'datetime','fulfilled_at'=>'datetime'];
 public function shop(){return $this->belongsTo(Shop::class);}
}
