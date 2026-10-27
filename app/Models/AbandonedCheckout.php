<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AbandonedCheckout extends Model
{
    protected $fillable = [
        'shop_id','checkout_token_hash','shopify_customer_id','phone_hash','phone','customer_first_name',
        'item_summary','recovery_url','total_price','currency','status','creation_webhook_event_id',
        'last_activity_at','completed_at','abandoned_at','marketing_claimed_at','marketing_sent_at',
    ];

    protected $casts = [
        'phone'=>'encrypted','customer_first_name'=>'encrypted','item_summary'=>'encrypted','recovery_url'=>'encrypted',
        'total_price'=>'decimal:2','last_activity_at'=>'datetime','completed_at'=>'datetime','abandoned_at'=>'datetime',
        'marketing_claimed_at'=>'datetime','marketing_sent_at'=>'datetime',
    ];

    public function shop(){return $this->belongsTo(Shop::class);}
    public function creationWebhook(){return $this->belongsTo(WebhookEvent::class,'creation_webhook_event_id');}
}
