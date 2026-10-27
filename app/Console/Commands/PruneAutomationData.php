<?php
namespace App\Console\Commands;
use App\Models\AbandonedCheckout;
use App\Models\WebhookEvent;
use App\Models\WorkflowExecution;
use App\Models\MessageLog;
use App\Models\CustomerReply;
use App\Models\OrderVerification;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
class PruneAutomationData extends Command {
 protected $signature='whatify:prune {--days=90 : Retention window for terminal automation runs}';
 protected $description='Prune old terminal automation runs and processed webhook payloads';
 public function handle():int{$days=max(7,(int)$this->option('days'));$cutoff=now()->subDays($days);$runs=WorkflowExecution::where('created_at','<',$cutoff)->delete();$messages=MessageLog::where('created_at','<',$cutoff)->delete();$replies=CustomerReply::where('created_at','<',$cutoff)->delete();$orders=OrderVerification::where('updated_at','<',$cutoff)->delete();$privacy=PrivacyRequest::where('created_at','<',now()->subDays(min($days,30)))->delete();$events=WebhookEvent::whereNotNull('processed_at')->where('processed_at','<',now()->subDays(min($days,30)))->delete();$checkouts=AbandonedCheckout::where('created_at','<',now()->subDays(30))->delete();$this->info("Pruned {$runs} executions, {$messages} messages, {$replies} replies, {$orders} old orders, {$privacy} privacy exports, {$events} processed webhook events and {$checkouts} abandoned checkouts older than 30 days.");return self::SUCCESS;}
}
