<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WebhookEvent extends Model {
 protected $fillable=['shop_id','webhook_id','topic','payload','processed_at','processing_error'];
 protected $casts=['payload'=>'encrypted:array','processed_at'=>'datetime'];
 public function shop(){return $this->belongsTo(Shop::class);}
}
