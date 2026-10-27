<?php

namespace App\Support;

/**
 * Predefined, publish-validated WhatsApp automation templates.
 * Every graph returned here satisfies WorkflowController::publish() rules:
 *  - exactly one trigger node with a supported topic
 *  - marketing-purpose sends only appear on a CHECKOUTS_CREATE flow
 *  - condition edges use true/false handles, reply edges yes/no/timeout
 */
class AutomationPresets
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::codVerification(),
            self::orderConfirmation(),
            self::abandonedCart(),
            self::orderShipped(),
        ];
    }

    public static function find(string $key): ?array
    {
        foreach (self::all() as $preset) {
            if ($preset['key'] === $key) {
                return $preset;
            }
        }
        return null;
    }

    /**
     * Return a deep-copied graph with the merchant's chosen approved Whatify
     * template names substituted into the send nodes.
     *
     * @param  array<string, mixed>  $preset
     * @param  array<string, string>  $chosenTemplates  node id => Whatify template name
     * @return array<string, mixed>
     */
    public static function graph(array $preset, array $chosenTemplates = []): array
    {
        $graph = json_decode(json_encode($preset['graph'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        foreach ($graph['nodes'] as $i => $node) {
            if (($node['type'] ?? '') !== 'send_template') {
                continue;
            }
            $chosen = trim((string) ($chosenTemplates[$node['id']] ?? ''));
            if ($chosen !== '') {
                $graph['nodes'][$i]['config']['template_name'] = $chosen;
            }
        }
        return $graph;
    }

    private static function codVerification(): array
    {
        return [
            'key' => 'cod_verification',
            'name' => 'COD order verification',
            'icon' => '◉',
            'color' => 'purple',
            'trigger_topic' => 'ORDERS_CREATE',
            'purpose' => 'order_update',
            'description' => 'Confirm every Cash on Delivery order on WhatsApp, wait for the customer reply, then tag confirmed orders or flag non-responses for staff review.',
            'consent_note' => 'Runs only for COD orders that carry a recorded WhatsApp order-update opt-in (order note attribute or whatsapp_opt_in tag).',
            'setup_notes' => [
                'Shopify order must contain the customer phone and an affirmative WhatsApp opt-in.',
                'Replies matching the confirmation keywords tag the order whatify_cod_confirmed.',
                'No reply within the timeout tags the order whatify_needs_review for staff.',
                'The app never cancels orders automatically.',
            ],
            'required_templates' => [
                ['node_id' => 'send-cod', 'category' => 'utility', 'purpose' => 'order_update', 'suggested_name' => 'cod_order_confirmation', 'label' => 'COD confirmation message'],
            ],
            'graph' => [
                'nodes' => [
                    ['id' => 'trigger-order', 'type' => 'trigger', 'title' => 'Order created', 'x' => 360, 'y' => 20, 'config' => ['topic' => 'ORDERS_CREATE']],
                    ['id' => 'cond-cod', 'type' => 'condition', 'title' => 'Is Cash on Delivery?', 'x' => 360, 'y' => 150, 'config' => ['field' => 'payment_gateway_names', 'operator' => 'contains', 'value' => 'cash on delivery']],
                    ['id' => 'cond-consent', 'type' => 'condition', 'title' => 'WhatsApp opt-in recorded?', 'x' => 360, 'y' => 280, 'config' => ['field' => '_whatify.whatsapp_opted_in', 'operator' => 'equals', 'value' => true]],
                    ['id' => 'send-cod', 'type' => 'send_template', 'title' => 'Send COD confirmation', 'x' => 360, 'y' => 410, 'config' => ['template_name' => 'cod_order_confirmation', 'message_purpose' => 'order_update', 'phone' => '{{order.customer.phone}}', 'body_params' => ['{{order.customer.first_name}}', '{{order.name}}', '{{order.total_price}}']]],
                    ['id' => 'wait-reply', 'type' => 'wait_for_reply', 'title' => 'Wait for customer reply', 'x' => 360, 'y' => 540, 'config' => ['timeout_minutes' => 120, 'yes_words' => ['yes', 'confirm'], 'no_words' => ['no', 'cancel']]],
                    ['id' => 'tag-confirm', 'type' => 'add_order_tag', 'title' => 'Tag order confirmed', 'x' => 120, 'y' => 640, 'config' => ['tag' => 'whatify_cod_confirmed']],
                    ['id' => 'tag-review', 'type' => 'add_order_tag', 'title' => 'Flag for staff review', 'x' => 600, 'y' => 640, 'config' => ['tag' => 'whatify_needs_review']],
                    ['id' => 'end', 'type' => 'stop', 'title' => 'End workflow', 'x' => 360, 'y' => 780, 'config' => []],
                ],
                'edges' => [
                    ['source' => 'trigger-order', 'target' => 'cond-cod'],
                    ['source' => 'cond-cod', 'target' => 'cond-consent', 'sourceHandle' => 'true'],
                    ['source' => 'cond-cod', 'target' => 'end', 'sourceHandle' => 'false'],
                    ['source' => 'cond-consent', 'target' => 'send-cod', 'sourceHandle' => 'true'],
                    ['source' => 'cond-consent', 'target' => 'end', 'sourceHandle' => 'false'],
                    ['source' => 'send-cod', 'target' => 'wait-reply'],
                    ['source' => 'wait-reply', 'target' => 'tag-confirm', 'sourceHandle' => 'yes'],
                    ['source' => 'wait-reply', 'target' => 'tag-review', 'sourceHandle' => 'no'],
                    ['source' => 'wait-reply', 'target' => 'tag-review', 'sourceHandle' => 'timeout'],
                    ['source' => 'tag-confirm', 'target' => 'end'],
                    ['source' => 'tag-review', 'target' => 'end'],
                ],
            ],
        ];
    }

    private static function orderConfirmation(): array
    {
        return [
            'key' => 'order_confirmation',
            'name' => 'Order confirmation',
            'icon' => '◉',
            'color' => 'green',
            'trigger_topic' => 'ORDERS_CREATE',
            'purpose' => 'order_update',
            'description' => 'Send a branded WhatsApp order confirmation immediately after every paid or COD order is created, with order number and total.',
            'consent_note' => 'Transactional utility message; requires the recorded WhatsApp order-update opt-in.',
            'setup_notes' => [
                'Fires on every order creation, not just COD.',
                'Template must be an approved Utility-category Whatify template.',
                'Body parameters must match the approved template parameter order.',
            ],
            'required_templates' => [
                ['node_id' => 'send-order', 'category' => 'utility', 'purpose' => 'order_update', 'suggested_name' => 'order_confirmation', 'label' => 'Order confirmation message'],
            ],
            'graph' => [
                'nodes' => [
                    ['id' => 'trigger-order', 'type' => 'trigger', 'title' => 'Order created', 'x' => 360, 'y' => 20, 'config' => ['topic' => 'ORDERS_CREATE']],
                    ['id' => 'cond-consent', 'type' => 'condition', 'title' => 'WhatsApp opt-in recorded?', 'x' => 360, 'y' => 150, 'config' => ['field' => '_whatify.whatsapp_opted_in', 'operator' => 'equals', 'value' => true]],
                    ['id' => 'send-order', 'type' => 'send_template', 'title' => 'Send order confirmation', 'x' => 360, 'y' => 280, 'config' => ['template_name' => 'order_confirmation', 'message_purpose' => 'order_update', 'phone' => '{{order.customer.phone}}', 'body_params' => ['{{order.customer.first_name}}', '{{order.name}}', '{{order.total_price}}']]],
                    ['id' => 'end', 'type' => 'stop', 'title' => 'End workflow', 'x' => 360, 'y' => 420, 'config' => []],
                ],
                'edges' => [
                    ['source' => 'trigger-order', 'target' => 'cond-consent'],
                    ['source' => 'cond-consent', 'target' => 'send-order', 'sourceHandle' => 'true'],
                    ['source' => 'cond-consent', 'target' => 'end', 'sourceHandle' => 'false'],
                    ['source' => 'send-order', 'target' => 'end'],
                ],
            ],
        ];
    }

    private static function abandonedCart(): array
    {
        return [
            'key' => 'abandoned_cart_recovery',
            'name' => 'Abandoned cart recovery',
            'icon' => '◷',
            'color' => 'blue',
            'trigger_topic' => 'CHECKOUTS_CREATE',
            'purpose' => 'marketing',
            'description' => 'Wait for checkout inactivity, then send one approved marketing recovery WhatsApp with the customer items and a secure recovery link. Completed checkouts are suppressed automatically.',
            'consent_note' => 'Marketing message. Requires a separate active cart-recovery opt-in submitted at checkout (whatsapp_marketing_opt_in / whatsapp_cart_recovery_opt_in note attribute). Order-update consent is never reused.',
            'setup_notes' => [
                'The app waits ABANDONED_CHECKOUT_DELAY_MINUTES (default 60) after the last checkout update before sending.',
                'Checkout must collect an affirmative WhatsApp marketing opt-in note attribute.',
                'Only one recovery message is sent per checkout; orders placed later cancel the reminder.',
                'Template must be an approved Marketing-category Whatify template.',
            ],
            'required_templates' => [
                ['node_id' => 'send-recovery', 'category' => 'marketing', 'purpose' => 'marketing', 'suggested_name' => 'cart_abandonment_reminder', 'label' => 'Cart recovery message'],
            ],
            'graph' => [
                'nodes' => [
                    ['id' => 'trigger-checkout', 'type' => 'trigger', 'title' => 'Checkout inactivity (recovery)', 'x' => 360, 'y' => 20, 'config' => ['topic' => 'CHECKOUTS_CREATE']],
                    ['id' => 'send-recovery', 'type' => 'send_template', 'title' => 'Send cart recovery reminder', 'x' => 360, 'y' => 160, 'config' => ['template_name' => 'cart_abandonment_reminder', 'message_purpose' => 'marketing', 'phone' => '{{checkout.customer.phone}}', 'body_params' => ['{{checkout.customer.first_name}}', '{{checkout.item_summary}}', '{{checkout.shop_name}}', '{{checkout.abandoned_checkout_url}}']]],
                    ['id' => 'end', 'type' => 'stop', 'title' => 'End workflow', 'x' => 360, 'y' => 310, 'config' => []],
                ],
                'edges' => [
                    ['source' => 'trigger-checkout', 'target' => 'send-recovery'],
                    ['source' => 'send-recovery', 'target' => 'end'],
                ],
            ],
        ];
    }

    private static function orderShipped(): array
    {
        return [
            'key' => 'order_shipped_update',
            'name' => 'Order shipped / delivery update',
            'icon' => '♧',
            'color' => 'green',
            'trigger_topic' => 'FULFILLMENTS_CREATE',
            'purpose' => 'order_update',
            'description' => 'Notify customers on WhatsApp as soon as Shopify creates a fulfillment, so they know the order is on its way. Order details are fetched from Shopify automatically.',
            'consent_note' => 'Transactional utility message; requires the recorded WhatsApp order-update opt-in.',
            'setup_notes' => [
                'Requires the fulfillments/create webhook in the deployed Shopify app configuration.',
                'The app loads the linked order (name, customer, phone) before running the flow.',
                'Template must be an approved Utility-category Whatify template.',
            ],
            'required_templates' => [
                ['node_id' => 'send-shipped', 'category' => 'utility', 'purpose' => 'order_update', 'suggested_name' => 'order_shipped', 'label' => 'Order shipped message'],
            ],
            'graph' => [
                'nodes' => [
                    ['id' => 'trigger-fulfillment', 'type' => 'trigger', 'title' => 'Fulfillment created', 'x' => 360, 'y' => 20, 'config' => ['topic' => 'FULFILLMENTS_CREATE']],
                    ['id' => 'cond-consent', 'type' => 'condition', 'title' => 'WhatsApp opt-in recorded?', 'x' => 360, 'y' => 150, 'config' => ['field' => '_whatify.whatsapp_opted_in', 'operator' => 'equals', 'value' => true]],
                    ['id' => 'send-shipped', 'type' => 'send_template', 'title' => 'Send shipped update', 'x' => 360, 'y' => 280, 'config' => ['template_name' => 'order_shipped', 'message_purpose' => 'order_update', 'phone' => '{{order.customer.phone}}', 'body_params' => ['{{order.customer.first_name}}', '{{order.name}}']]],
                    ['id' => 'end', 'type' => 'stop', 'title' => 'End workflow', 'x' => 360, 'y' => 420, 'config' => []],
                ],
                'edges' => [
                    ['source' => 'trigger-fulfillment', 'target' => 'cond-consent'],
                    ['source' => 'cond-consent', 'target' => 'send-shipped', 'sourceHandle' => 'true'],
                    ['source' => 'cond-consent', 'target' => 'end', 'sourceHandle' => 'false'],
                    ['source' => 'send-shipped', 'target' => 'end'],
                ],
            ],
        ];
    }
}
