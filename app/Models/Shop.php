<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Shop extends Model {
 protected $fillable=['shop_domain','access_token','refresh_token','access_token_expires_at','refresh_token_expires_at','whatify_api_key','granted_scopes','installed','settings','installed_at'];
 protected $casts=['access_token'=>'encrypted','refresh_token'=>'encrypted','whatify_api_key'=>'encrypted','access_token_expires_at'=>'datetime','refresh_token_expires_at'=>'datetime','settings'=>'array','installed'=>'boolean','installed_at'=>'datetime'];
 public function workflows(){return $this->hasMany(Workflow::class);}
 public function executions(){return $this->hasMany(WorkflowExecution::class);}
 public function verifications(){return $this->hasMany(OrderVerification::class);}
 public function webhookEvents(){return $this->hasMany(WebhookEvent::class);}
 public function messageLogs(){return $this->hasMany(MessageLog::class);}
 public function customerReplies(){return $this->hasMany(CustomerReply::class);}
 public function privacyRequests(){return $this->hasMany(PrivacyRequest::class);}
 public function whatsappConsents(){return $this->hasMany(WhatsAppConsent::class);}
 public function whatsappMarketingConsents(){return $this->hasMany(WhatsAppMarketingConsent::class);}
 public function abandonedCheckouts(){return $this->hasMany(AbandonedCheckout::class);}
 public function widgetSetting(){return $this->hasOne(WidgetSetting::class);}
}
