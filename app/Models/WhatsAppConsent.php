<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WhatsAppConsent extends Model {
 protected $table='whatsapp_consents';
 protected $fillable=['shop_id','shopify_customer_id','phone_hash','phone','contact_name','status','source','source_reference','consented_at','opted_out_at'];
 protected $casts=['phone'=>'encrypted','consented_at'=>'datetime','opted_out_at'=>'datetime'];
 public function shop(){return $this->belongsTo(Shop::class);}
 public function scopeActive($query){return $query->where('status','opted_in');}
}
