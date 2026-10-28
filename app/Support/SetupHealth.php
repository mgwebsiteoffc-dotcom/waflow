<?php

namespace App\Support;

use App\Models\AbandonedCheckout;
use App\Models\MessageLog;
use App\Models\WebhookEvent;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Services\WhatifyClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SetupHealth
{
    public const HEARTBEAT_KEY = 'whatify_scheduler_heartbeat';

    /**
     * Human-readable explanations for every MessageLog status the runner writes.
     */
    private const MESSAGE_STATUS_LABELS = [
        'queued' => 'Queued, waiting for the worker',
        'sending' => 'Sending right now',
        'sent' => 'Sent to Whatify successfully',
        'delivered' => 'Delivered on WhatsApp',
        'read' => 'Read by the customer',
        'accepted' => 'Accepted by the provider',
        'failed' => 'Send failed — check the logged error',
        'unknown' => 'Uncertain delivery — blocked from auto-resend to avoid duplicates',
        'skipped_no_opt_in' => 'Skipped: no recorded order-update WhatsApp opt-in',
        'skipped_no_marketing' => 'Skipped: no active cart-recovery marketing opt-in',
        'skipped_not_approved' => 'Skipped: template not approved (or wrong category) in Whatify',
        'skipped_checkout_closed' => 'Skipped: checkout completed, already messaged, or not yet eligible',
    ];

    /**
     * @return array{checks: array<int, array<string, mixed>>, outcomes: array<int, array<string, mixed>>, counts: array<string, int>, worst: string}
     */
    public function build(\App\Models\Shop $shop): array
    {
        $checks = [];
        $whatify = $this->whatifySnapshot($shop);

        $checks[] = $this->shopifyCheck($shop);
        $checks[] = $this->protectedDataCheck($shop);
        $checks[] = $this->whatifyCheck($shop, $whatify);
        $checks[] = $this->approvedTemplatesCheck($whatify);
        $checks[] = $this->templateMappingCheck($shop, $whatify);
        $checks[] = $this->coverageCheck($shop);
        $checks[] = $this->unpublishedCheck($shop);
        $checks[] = $this->schedulerCheck();
        $checks[] = $this->stuckRunsCheck($shop);
        $checks[] = $this->staleWaitingCheck($shop);
        $checks[] = $this->ambiguousRepliesCheck($shop);
        $checks[] = $this->failedRunsCheck($shop);
        $checks[] = $this->webhookErrorCheck($shop);
        $checks[] = $this->abandonedCheckoutCheck($shop);

        $outcomes = $this->messageOutcomes($shop);
        $counts = [
            'ok' => count(array_filter($checks, fn ($c) => $c['level'] === 'ok')),
            'warn' => count(array_filter($checks, fn ($c) => $c['level'] === 'warn')),
            'error' => count(array_filter($checks, fn ($c) => $c['level'] === 'error')),
            'waiting_replies' => (int) $shop->executions()->where('status', 'waiting')->count(),
        ];
        $worst = $counts['error'] > 0 ? 'error' : ($counts['warn'] > 0 ? 'warn' : 'ok');

        return ['checks' => $checks, 'outcomes' => $outcomes, 'counts' => $counts, 'worst' => $worst];
    }

    /**
     * @return array{ok: bool, templates: array<int, array<string, mixed>>, accounts: array<int, mixed>, error: ?string}
     */
    private function whatifySnapshot(\App\Models\Shop $shop): array
    {
        if (! $shop->whatify_api_key) {
            return ['ok' => false, 'templates' => [], 'accounts' => [], 'error' => 'Whatify API key not connected'];
        }

        return Cache::remember('whatify_health_snapshot_'.$shop->id, now()->addMinutes(5), function () use ($shop) {
            try {
                $client = new WhatifyClient($shop);
                $accounts = $client->accounts();
                $templates = $client->templates();
                return ['ok' => true, 'templates' => $templates, 'accounts' => $accounts, 'error' => null];
            } catch (\Throwable $e) {
                return ['ok' => false, 'templates' => [], 'accounts' => [], 'error' => mb_substr($e->getMessage(), 0, 300)];
            }
        });
    }

    private function shopifyCheck(\App\Models\Shop $shop): array
    {
        if ($shop->installed && $shop->access_token) {
            return $this->check('ok', 'Shopify store connected', 'Webhooks from '.$shop->shop_domain.' can start automations.');
        }
        return $this->check('error', 'Shopify store not connected', 'Install the app from your Shopify admin so order and checkout events arrive.', ['label' => 'Open integrations', 'route' => 'integrations.index']);
    }

    private function protectedDataCheck(\App\Models\Shop $shop): array
    {
        if (!$shop->installed || !$shop->access_token) {
            return $this->check('warn', 'Protected customer data not verifiable', 'Connect the Shopify store first; Order object access is checked automatically afterward.');
        }
        try {
            $probe = app(\App\Services\OrderSync::class)->protectedDataAccess($shop);
        } catch (\Throwable $e) {
            return $this->check('warn', 'Protected data check unavailable', 'The Order access probe could not run: '.mb_substr($e->getMessage(), 0, 180));
        }
        if ($probe['ok']) {
            return $this->check('ok', 'Protected customer data (Order object) approved', 'The app can read orders through the GraphQL Admin API.');
        }
        $detail = ($probe['protected'] ?? false)
            ? 'Shopify blocks Order access until protected customer data is approved. Complete App setup → Protected customer data in Partner Dashboard and submit for approval; development stores work immediately after redeploying the app config.'
            : 'Order probe failed: '.($probe['error'] ?? 'unknown error');
        return $this->check('error', 'Shopify protected customer data not approved', $detail, ['label' => 'Open integrations', 'route' => 'integrations.index']);
    }

    private function whatifyCheck(\App\Models\Shop $shop, array $whatify): array
    {
        if (! $shop->whatify_api_key) {
            return $this->check('error', 'Whatify WhatsApp not connected', 'No WhatsApp messages can be sent until the Whatify API key is added and verified.', ['label' => 'Open integrations', 'route' => 'integrations.index']);
        }
        if (! $whatify['ok']) {
            return $this->check('error', 'Whatify connection failing', 'Stored API key could not be verified: '.($whatify['error'] ?? 'unknown error').'. Reconnect the key in Integrations.', ['label' => 'Open integrations', 'route' => 'integrations.index']);
        }
        return $this->check('ok', 'Whatify WhatsApp connected', count($whatify['accounts']).' sender account(s) reachable.');
    }

    private function approvedTemplatesCheck(array $whatify): array
    {
        if (! $whatify['ok']) {
            return $this->check('warn', 'Approved templates unknown', 'Connect Whatify to verify approved template names and categories.');
        }
        $approved = array_filter($whatify['templates'], fn ($t) => strtolower((string) ($t['status'] ?? '')) === 'approved');
        $utility = array_filter($approved, fn ($t) => strtolower((string) ($t['category'] ?? '')) === 'utility');
        $marketing = array_filter($approved, fn ($t) => strtolower((string) ($t['category'] ?? '')) === 'marketing');
        if (count($approved) === 0) {
            return $this->check('error', 'No approved Whatify templates', 'The app can only send approved templates. Create and get templates approved in Whatify first.');
        }
        $details = count($utility).' approved utility, '.count($marketing).' approved marketing template(s).';
        if (count($utility) === 0 || count($marketing) === 0) {
            $missing = count($utility) === 0 && count($marketing) === 0 ? 'utility and marketing' : (count($utility) === 0 ? 'utility' : 'marketing');
            return $this->check('warn', 'Approved templates: missing '.$missing.' category', $details.' Cart recovery needs marketing; order notices need utility.');
        }
        return $this->check('ok', 'Approved Whatify templates available', $details);
    }

    private function templateMappingCheck(\App\Models\Shop $shop, array $whatify): array
    {
        $approvedNames = [];
        foreach ($whatify['templates'] as $template) {
            if (strtolower((string) ($template['status'] ?? '')) !== 'approved') {
                continue;
            }
            $approvedNames[strtolower((string) $template['name'])][] = strtolower((string) ($template['category'] ?? ''));
        }

        $problems = [];
        foreach ($shop->workflows()->get() as $workflow) {
            foreach ([$workflow->draft_graph, optional($workflow->publishedVersion)->graph] as $graph) {
                foreach (($graph['nodes'] ?? []) as $node) {
                    if (($node['type'] ?? '') !== 'send_template') {
                        continue;
                    }
                    $name = trim((string) ($node['config']['template_name'] ?? ''));
                    $purpose = ($node['config']['message_purpose'] ?? 'order_update') === 'marketing' ? 'marketing' : 'utility';
                    if ($name === '') {
                        $problems[] = '"'.$workflow->name.'": step "'.($node['title'] ?? 'Send template').'" has no template selected';
                        continue;
                    }
                    if (! $whatify['ok']) {
                        continue; // reported by the Whatify connection check
                    }
                    $categories = $approvedNames[strtolower($name)] ?? null;
                    if ($categories === null) {
                        $problems[] = '"'.$workflow->name.'": template "'.$name.'" is not an approved Whatify template';
                    } elseif (! in_array($purpose, $categories, true)) {
                        $problems[] = '"'.$workflow->name.'": template "'.$name.'" is not a '.$purpose.' template';
                    }
                }
            }
        }
        $problems = array_values(array_unique($problems));
        if ($problems === []) {
            return $this->check('ok', 'Workflow template mappings valid', $whatify['ok'] ? 'Every WhatsApp step points to an approved template in the right category.' : 'Will be verified once Whatify is connected.');
        }
        return $this->check('error', 'Broken WhatsApp template mapping', implode(' · ', array_slice($problems, 0, 6)).(count($problems) > 6 ? ' …' : ''), ['label' => 'Open automations', 'route' => 'automations.index']);
    }

    private function coverageCheck(\App\Models\Shop $shop): array
    {
        $topics = ['ORDERS_CREATE' => 'Order automations (COD / confirmation)', 'FULFILLMENTS_CREATE' => 'Delivery / shipped updates', 'CHECKOUTS_CREATE' => 'Abandoned cart recovery'];
        $active = [];
        foreach ($shop->workflows()->where('enabled', true)->with('publishedVersion')->get() as $workflow) {
            foreach (($workflow->publishedVersion->graph['nodes'] ?? []) as $node) {
                if (($node['type'] ?? '') === 'trigger' && isset($topics[$node['config']['topic'] ?? ''])) {
                    $active[$node['config']['topic']][] = $workflow->name;
                }
            }
        }
        $missing = array_values(array_diff(array_keys($topics), array_keys($active)));
        if ($missing === []) {
            return $this->check('ok', 'All automation types covered', 'Order, fulfillment and checkout triggers each have an enabled workflow.');
        }
        $labels = array_map(fn ($t) => $topics[$t], $missing);
        return $this->check('warn', 'No enabled automation for: '.implode('; ', $labels), 'Add one of the predefined templates to cover this scenario.', ['label' => 'Browse templates', 'route' => 'automations.presets']);
    }

    private function unpublishedCheck(\App\Models\Shop $shop): array
    {
        $drafts = $shop->workflows()->whereNull('published_version_id')->count();
        if ($drafts === 0) {
            return $this->check('ok', 'No unpublished drafts', 'Every automation has a published version.');
        }
        return $this->check('warn', $drafts.' workflow(s) never published', 'Drafts do not run until they are published.', ['label' => 'Open automations', 'route' => 'automations.index']);
    }

    private function schedulerCheck(): array
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);
        if (! $beat) {
            return $this->check('error', 'Scheduler heartbeat missing', 'No whatify:health-heartbeat run seen. The Laravel scheduler (php artisan schedule:work) must run every minute or delayed automations and token refresh stop.');
        }
        $age = time() - (int) $beat;
        if ($age > 300) {
            return $this->check('error', 'Scheduler appears stopped', 'Last heartbeat was '.ceil($age / 60).' minutes ago. Restart the scheduler and queue worker.');
        }
        return $this->check('ok', 'Scheduler running', 'Last heartbeat '.$age.' seconds ago. Keep php artisan queue:work running too.');
    }

    private function stuckRunsCheck(\App\Models\Shop $shop): array
    {
        $stuck = (int) $shop->executions()->whereIn('status', ['queued', 'running'])->where('created_at', '<', now()->subMinutes(15))->count();
        if ($stuck === 0) {
            return $this->check('ok', 'No stuck automation runs', 'Queued runs are being picked up by the worker.');
        }
        return $this->check('error', $stuck.' automation run(s) stuck queued/running for over 15 minutes', 'The database queue worker is likely not running. Start php artisan queue:work.', ['label' => 'Run history', 'route' => 'executions.index']);
    }

    private function staleWaitingCheck(\App\Models\Shop $shop): array
    {
        $stale = 0;
        foreach ($shop->executions()->where('status', 'waiting')->with('version')->limit(200)->get() as $execution) {
            $node = collect($execution->version->graph['nodes'] ?? [])->firstWhere('id', $execution->current_node_id);
            $timeoutMinutes = (int) ($node['config']['timeout_minutes'] ?? 120);
            if ($execution->updated_at->lt(now()->subMinutes($timeoutMinutes + 10))) {
                $stale++;
            }
        }
        if ($stale === 0) {
            return $this->check('ok', 'No overdue reply waits', 'Waiting automations are still inside their configured reply timeout.');
        }
        return $this->check('warn', $stale.' automation(s) waiting for a reply past their timeout', 'The delayed timeout job was likely lost (queue flush/worker downtime). Restart the queue worker; new runs recover automatically, and these runs can be retried from run history.', ['label' => 'Run history', 'route' => 'executions.index']);
    }

    private function ambiguousRepliesCheck(\App\Models\Shop $shop): array
    {
        $duplicates = (int) $shop->executions()
            ->where('status', 'waiting')
            ->whereNotNull('customer_phone_hash')
            ->selectRaw('customer_phone_hash, COUNT(*) AS aggregate')
            ->groupBy('customer_phone_hash')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        if ($duplicates === 0) {
            return $this->check('ok', 'No ambiguous customer replies', 'Each waiting automation maps to a unique customer conversation.');
        }
        return $this->check('warn', $duplicates.' customer(s) have multiple automations waiting on a reply', 'Inbound WhatsApp replies are routed only when exactly one run is waiting for a phone; with several, replies cannot be matched and the runs time out. Consolidate reply-waiting steps or pause duplicate automations.', ['label' => 'Open automations', 'route' => 'automations.index']);
    }

    private function failedRunsCheck(\App\Models\Shop $shop): array
    {
        $failed = (int) $shop->executions()->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count();
        if ($failed === 0) {
            return $this->check('ok', 'No failed runs in the last 7 days', '');
        }
        return $this->check('warn', $failed.' failed automation run(s) in the last 7 days', 'Open run history to inspect the logged error and retry after fixing the cause.', ['label' => 'Run history', 'route' => 'executions.index']);
    }

    private function webhookErrorCheck(\App\Models\Shop $shop): array
    {
        $errors = (int) $shop->webhookEvents()->whereNotNull('processing_error')->where('created_at', '>=', now()->subDays(7))->count();
        if ($errors === 0) {
            return $this->check('ok', 'No webhook processing errors in 7 days', '');
        }
        return $this->check('warn', $errors.' Shopify webhook(s) failed processing in 7 days', 'Failures are retried automatically; persistent errors usually indicate an API or template configuration problem.');
    }

    private function abandonedCheckoutCheck(\App\Models\Shop $shop): array
    {
        $delay = (int) config('services.shopify.abandoned_checkout_delay_minutes', 60);
        $stalled = (int) AbandonedCheckout::where('shop_id', $shop->id)
            ->where('status', 'open')
            ->where('last_activity_at', '<', now()->subMinutes($delay))
            ->count();
        $suppressed = (int) AbandonedCheckout::where('shop_id', $shop->id)
            ->where('status', 'suppressed_no_marketing_opt_in')
            ->where('updated_at', '>=', now()->subDays(7))
            ->count();
        if ($stalled === 0 && $suppressed === 0) {
            return $this->check('ok', 'Abandoned checkout pipeline healthy', 'No stalled or consent-suppressed checkouts.');
        }
        $details = [];
        if ($stalled > 0) {
            $details[] = $stalled.' open checkout(s) past the '.$delay.'-minute window — the recovery sweep reclaims these automatically';
        }
        if ($suppressed > 0) {
            $details[] = $suppressed.' checkout(s) skipped because the customer did not give marketing opt-in at checkout';
        }
        return $this->check($stalled > 0 ? 'warn' : 'ok', 'Abandoned cart recovery status', implode(' · ', $details));
    }

    /**
     * WhatsApp send outcomes for the last 7 days with plain-language reasons.
     *
     * @return array<int, array<string, mixed>>
     */
    private function messageOutcomes(\App\Models\Shop $shop): array
    {
        $rows = MessageLog::where('shop_id', $shop->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();
        $outcomes = [];
        foreach ($rows as $status => $count) {
            $outcomes[] = [
                'status' => $status,
                'count' => (int) $count,
                'label' => self::MESSAGE_STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', (string) $status)),
                'tone' => in_array($status, ['sent', 'delivered', 'read', 'accepted'], true) ? 'ok' : (in_array($status, ['failed', 'unknown'], true) ? 'error' : 'warn'),
            ];
        }
        usort($outcomes, fn ($a, $b) => $b['count'] <=> $a['count']);
        return $outcomes;
    }

    /**
     * @param  array{label: string, route: string}|null  $fix
     * @return array<string, mixed>
     */
    private function check(string $level, string $title, string $detail, ?array $fix = null): array
    {
        return compact('level', 'title', 'detail', 'fix');
    }
}
