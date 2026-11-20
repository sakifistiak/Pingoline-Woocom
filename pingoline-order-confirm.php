<?php
/**
 * Plugin Name:       PingoLine Order Confirmation Call
 * Description:       Automatic phone call confirmations for WooCommerce orders in Bangladesh: press 1 to confirm, 2 to cancel. Powered by PingoLine Cloud PBX.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 * Author:            PingoLine
 * Author URI:        https://pingoline.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pingoline-order-confirmation-call
 */

if (! defined('ABSPATH')) {
    exit;
}

final class PingoLine_Order_Confirm
{
    const OPTION = 'pingoline_order_confirm';
    const LEGACY_OPTION = 'xpbx_order_confirm';
    const META_CALL = '_pingoline_call_id';
    const LEGACY_META_CALL = '_xpbx_call_id';
    const META_STATUS = '_pingoline_call_status';
    const LEGACY_META_STATUS = '_xpbx_call_status';
    const ACTION = 'pingoline_order_confirm_send';
    const LEGACY_ACTION = 'xpbx_order_confirm_send';

    public static function boot()
    {
        $self = new self();

        add_action('admin_menu', [$self, 'menu']);
        add_action('admin_init', [$self, 'register_settings']);
        add_filter('plugin_action_links_'.plugin_basename(__FILE__), [$self, 'settings_link']);

        // New orders: classic checkout and block checkout.
        add_action('woocommerce_checkout_order_processed', [$self, 'on_new_order'], 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$self, 'on_new_order_object'], 20, 1);

        // Sent in the background so checkout is never slowed by the API.
        add_action(self::ACTION, [$self, 'send'], 10, 1);
        add_action(self::LEGACY_ACTION, [$self, 'send'], 10, 1);

        // Manual "send call" from the order screen.
        add_filter('woocommerce_order_actions', [$self, 'order_action']);
        add_action('woocommerce_order_action_pingoline_send_call', [$self, 'manual_send']);
        add_action('woocommerce_order_action_xpbx_send_call', [$self, 'manual_send']);

        add_action('rest_api_init', [$self, 'routes']);

        // HPOS compatible.
        add_action('before_woocommerce_init', function () {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });
    }

    // ------------------------------------------------------------ settings

    /**
     * Connection details baked into a ready-made download (pingoline-config.php or legacy xpbx-config.php),
     * or an empty array for the plain plugin.
     */
    public static function preset()
    {
        static $preset = null;

        if ($preset === null) {
            $file = __DIR__.'/pingoline-config.php';
            if (! is_file($file)) {
                $file = __DIR__.'/xpbx-config.php';
            }
            $preset = is_file($file) ? (array) include $file : [];
        }

        return $preset;
    }

    public static function options()
    {
        $saved = get_option(self::OPTION);
        if ($saved === false) {
            $saved = get_option(self::LEGACY_OPTION, []);
        }

        $o = wp_parse_args($saved, [
            'api_url' => '',
            'api_token' => '',
            'webhook_secret' => '',
            'enabled' => 'yes',
            'only_cod' => 'yes',
            'confirmed_status' => 'processing',
            'cancelled_status' => 'cancelled',
            'caller_number' => '',
        ]);

        // A ready-made plugin always uses the key it was issued with; a new
        // download (new key) replaces it simply by being installed.
        foreach (['api_url', 'api_token', 'webhook_secret'] as $key) {
            if (! empty(self::preset()[$key]) && is_string(self::preset()[$key])) {
                $o[$key] = self::preset()[$key];
            }
        }

        return $o;
    }

    public function menu()
    {
        add_submenu_page(
            'woocommerce',
            esc_html__('PingoLine Order Call', 'pingoline-order-confirmation-call'),
            esc_html__('PingoLine Order Call', 'pingoline-order-confirmation-call'),
            'manage_woocommerce',
            'pingoline-order-confirm',
            [$this, 'settings_page']
        );
    }

    public function settings_link($links)
    {
        array_unshift($links, '<a href="'.esc_url(admin_url('admin.php?page=pingoline-order-confirm')).'">'.esc_html__('Settings', 'pingoline-order-confirmation-call').'</a>');

        return $links;
    }

    public function register_settings()
    {
        $sanitize = function ($input) {
            $clean = self::options();
            foreach (['api_url', 'api_token', 'webhook_secret', 'confirmed_status', 'cancelled_status', 'caller_number'] as $key) {
                $clean[$key] = isset($input[$key]) ? sanitize_text_field(wp_unslash($input[$key])) : '';
            }
            $clean['api_url'] = untrailingslashit(esc_url_raw($clean['api_url']));
            $clean['enabled'] = empty($input['enabled']) ? 'no' : 'yes';
            $clean['only_cod'] = empty($input['only_cod']) ? 'no' : 'yes';

            return $clean;
        };

        register_setting('pingoline_order_confirm', self::OPTION, ['sanitize_callback' => $sanitize]);
        register_setting('xpbx_order_confirm', self::LEGACY_OPTION, ['sanitize_callback' => $sanitize]);
    }

    public function settings_page()
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'pingoline-order-confirmation-call'));
        }

        $o = self::options();
        $statuses = wc_get_order_statuses();
        $webhook = rest_url('pingoline/v1/webhook');
        $ping = null;

        if ($o['api_url'] && $o['api_token'] && isset($_GET['pingoline_ping'])) {
            check_admin_referer('pingoline_test_ping', '_wpnonce');
            $ping = $this->api('GET', '/ping');
        } ?>
        <div class="wrap">
            <h1><?php esc_html_e('PingoLine Order Confirmation Call', 'pingoline-order-confirmation-call'); ?></h1>
            <p><?php echo wp_kses_post(__('New orders get an automatic call: <strong>press 1 to confirm, 2 to cancel</strong>. The result updates the order and adds an order note.', 'pingoline-order-confirmation-call')); ?></p>
            <?php $preset = self::preset(); ?>
            <?php if (! empty($preset['api_token'])) : ?>
                <div class="notice notice-success inline"><p><?php 
                    /* translators: %s: connected account name */
                    echo wp_kses_post(sprintf(__('Ready to use: this plugin is already connected%s. Use <em>Test connection</em> below to check.', 'pingoline-order-confirmation-call'), empty($preset['account']) ? '' : ' for <strong>'.esc_html($preset['account']).'</strong>')); 
                ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('pingoline_order_confirm'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="pingoline_api_url"><?php esc_html_e('API URL', 'pingoline-order-confirmation-call'); ?></label></th>
                        <td><input id="pingoline_api_url" class="regular-text" <?php echo empty($preset['api_token']) ? '' : 'readonly'; ?> name="<?php echo esc_attr(self::OPTION); ?>[api_url]" value="<?php echo esc_attr($o['api_url']); ?>" placeholder="https://pingoline.com/api/v1">
                        <p class="description"><?php esc_html_e('From the PingoLine portal: Developer → API base URL.', 'pingoline-order-confirmation-call'); ?></p></td></tr>
                    <tr><th scope="row"><label for="pingoline_api_token"><?php esc_html_e('API token', 'pingoline-order-confirmation-call'); ?></label></th>
                        <td><input id="pingoline_api_token" class="regular-text" <?php echo empty($preset['api_token']) ? '' : 'readonly'; ?> type="password" name="<?php echo esc_attr(self::OPTION); ?>[api_token]" value="<?php echo esc_attr($o['api_token']); ?>" autocomplete="off"></td></tr>
                    <tr><th scope="row"><label for="pingoline_secret"><?php esc_html_e('Webhook signing secret', 'pingoline-order-confirmation-call'); ?></label></th>
                        <td><input id="pingoline_secret" class="regular-text" <?php echo empty($preset['api_token']) ? '' : 'readonly'; ?> type="password" name="<?php echo esc_attr(self::OPTION); ?>[webhook_secret]" value="<?php echo esc_attr($o['webhook_secret']); ?>" autocomplete="off">
                        <p class="description"><?php 
                            /* translators: 1: site domain, 2: webhook URL */
                            printf(esc_html__('In the PingoLine portal open Developer, Connected sites, and add this site (domain %1$s). Its webhook URL is %2$s. Paste the site\'s API key above and its signing secret here.', 'pingoline-order-confirmation-call'), '<code>'.esc_html(home_url()).'</code>', '<code>'.esc_html($webhook).'</code>'); 
                        ?></p></td></tr>
                    <tr><th scope="row"><label for="pingoline_caller"><?php esc_html_e('Caller number', 'pingoline-order-confirmation-call'); ?></label></th>
                        <td><input id="pingoline_caller" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[caller_number]" value="<?php echo esc_attr($o['caller_number']); ?>" placeholder="Leave empty for your default IP number"></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Call new orders', 'pingoline-order-confirmation-call'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enabled]" value="1" <?php checked($o['enabled'], 'yes'); ?>> <?php esc_html_e('Enabled', 'pingoline-order-confirmation-call'); ?></label><br>
                        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[only_cod]" value="1" <?php checked($o['only_cod'], 'yes'); ?>> <?php esc_html_e('Only Cash on Delivery orders', 'pingoline-order-confirmation-call'); ?></label></td></tr>
                    <tr><th scope="row"><label for="pingoline_ok"><?php esc_html_e('When confirmed (1)', 'pingoline-order-confirmation-call'); ?></label></th>
                        <td><select id="pingoline_ok" name="<?php echo esc_attr(self::OPTION); ?>[confirmed_status]"><?php $this->status_options($statuses, $o['confirmed_status']); ?></select></td></tr>
                    <tr><th scope="row"><label for="pingoline_no"><?php esc_html_e('When cancelled (2)', 'pingoline-order-confirmation-call'); ?></label></th>
                        <td><select id="pingoline_no" name="<?php echo esc_attr(self::OPTION); ?>[cancelled_status]"><?php $this->status_options($statuses, $o['cancelled_status']); ?></select></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php
            $test_ping_url = wp_nonce_url(
                add_query_arg(['page' => 'pingoline-order-confirm', 'pingoline_ping' => 1], admin_url('admin.php')),
                'pingoline_test_ping',
                '_wpnonce'
            );
            ?>
            <p><a class="button" href="<?php echo esc_url($test_ping_url); ?>"><?php esc_html_e('Test connection', 'pingoline-order-confirmation-call'); ?></a></p>
            <?php if ($ping !== null) : ?>
                <?php if (is_wp_error($ping) || $ping['code'] !== 200) : ?>
                    <div class="notice notice-error inline"><p><?php 
                        /* translators: %s: connection error message */
                        printf(esc_html__('Connection failed: %s', 'pingoline-order-confirmation-call'), esc_html(is_wp_error($ping) ? $ping->get_error_message() : ($ping['body']['error']['message'] ?? 'HTTP '.$ping['code']))); 
                    ?></p></div>
                <?php else : ?>
                    <div class="notice notice-success inline"><p><?php 
                        /* translators: %s: connected account name */
                        printf(esc_html__('Connected to %s.', 'pingoline-order-confirmation-call'), '<strong>'.esc_html($ping['body']['data']['account']).'</strong>'); 
                    ?></p></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function status_options($statuses, $current)
    {
        echo '<option value="">— do not change —</option>';
        foreach ($statuses as $key => $label) {
            $key = str_replace('wc-', '', $key);
            printf('<option value="%s" %s>%s</option>', esc_attr($key), selected($current, $key, false), esc_html($label));
        }
    }

    // ------------------------------------------------------------ sending

    public function on_new_order_object($order)
    {
        if ($order instanceof WC_Order) {
            $this->on_new_order($order->get_id());
        }
    }

    public function on_new_order($order_id)
    {
        $o = self::options();
        $order = wc_get_order($order_id);

        if (! $order || $o['enabled'] !== 'yes' || $order->get_meta(self::META_CALL) || $order->get_meta(self::LEGACY_META_CALL)) {
            return;
        }

        if ($o['only_cod'] === 'yes' && $order->get_payment_method() !== 'cod') {
            return;
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION, [$order_id], 'pingoline');
        } else {
            $this->send($order_id);
        }
    }

    public function order_action($actions)
    {
        $actions['pingoline_send_call'] = 'Send PingoLine confirmation call';

        return $actions;
    }

    public function manual_send($order)
    {
        $order->delete_meta_data(self::META_CALL);
        $order->delete_meta_data(self::LEGACY_META_CALL);
        $order->save();
        $this->send($order->get_id(), true);
    }

    public function send($order_id, $manual = false)
    {
        $o = self::options();
        $order = wc_get_order($order_id);

        if (! $order || ! $o['api_url'] || ! $o['api_token']) {
            return;
        }

        $phone = $order->get_billing_phone() ?: $order->get_shipping_phone();

        if (! $phone) {
            $order->add_order_note('PingoLine: no phone number on the order, confirmation call not sent.');

            return;
        }

        $body = [
            'order_id' => (string) $order->get_order_number().($manual ? '-'.time() : ''),
            'phone' => $phone,
            'customer_name' => trim($order->get_billing_first_name().' '.$order->get_billing_last_name()),
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'callback_url' => rest_url('pingoline/v1/webhook'),
            'metadata' => ['wc_order_id' => $order->get_id()],
        ];

        if ($o['caller_number']) {
            $body['number'] = $o['caller_number'];
        }

        $response = $this->api('POST', '/order-calls', $body);

        if (is_wp_error($response) || ! in_array($response['code'], [200, 201], true)) {
            $message = is_wp_error($response) ? $response->get_error_message() : ($response['body']['error']['message'] ?? $response['body']['message'] ?? 'HTTP '.$response['code']);
            $order->add_order_note('PingoLine: confirmation call could not be queued: '.$message);

            return;
        }

        $call = $response['body']['data'];
        $order->update_meta_data(self::META_CALL, $call['id']);
        $order->update_meta_data(self::META_STATUS, $call['status']);
        $order->save();
        $order->add_order_note(sprintf('PingoLine: confirmation call queued to %s (call %s).', $call['phone'], $call['id']));
    }

    /**
     * @return array{code:int, body:array}|WP_Error
     */
    private function api($method, $path, $body = null)
    {
        $o = self::options();
        $args = [
            'method' => $method,
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer '.$o['api_token'],
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-PingoLine-Source' => 'woocommerce',
                'X-XPBX-Source' => 'woocommerce',
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($o['api_url'].$path, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'code' => (int) wp_remote_retrieve_response_code($response),
            'body' => (array) json_decode(wp_remote_retrieve_body($response), true),
        ];
    }

    // ------------------------------------------------------------ webhook

    public function routes()
    {
        $cb = [$this, 'webhook'];

        register_rest_route('pingoline/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => $cb,
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('xpbx/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => $cb,
            'permission_callback' => '__return_true',
        ]);
    }

    public function webhook(WP_REST_Request $request)
    {
        $o = self::options();
        $raw = $request->get_body();
        $expected = 'sha256='.hash_hmac('sha256', $raw, (string) $o['webhook_secret']);
        $sig = $request->get_header('x-pingoline-signature') ?: $request->get_header('x-xpbx-signature');

        if (! $o['webhook_secret'] || ! hash_equals($expected, (string) $sig)) {
            return new WP_REST_Response(['error' => 'invalid signature'], 401);
        }

        $payload = json_decode($raw, true);
        $event = $payload['event'] ?? '';

        if ($event === 'webhook.test') {
            return new WP_REST_Response(['ok' => true], 200);
        }

        if ($event !== 'order_call.completed') {
            return new WP_REST_Response(['ignored' => $event], 200);
        }

        $call = $payload['data'] ?? [];
        $order = $this->find_order($call);

        if (! $order) {
            return new WP_REST_Response(['error' => 'order not found'], 404);
        }

        $order->update_meta_data(self::META_STATUS, $call['status']);
        $order->save();

        $notes = [
            'confirmed' => 'Customer pressed 1: order CONFIRMED by phone.',
            'cancelled' => 'Customer pressed 2: order CANCELLED by phone.',
            'agent' => 'Customer pressed 0 and was put through to an agent — confirm or cancel the order by hand.',
            'no_response' => 'Customer answered but pressed nothing (%d attempts).',
            'unreached' => 'Customer could not be reached (%d attempts).',
            'failed' => 'Confirmation call could not be placed (%s).',
        ];
        $note = sprintf($notes[$call['status']] ?? 'Call finished: %s', $call['status'] === 'failed' ? ($call['last_result'] ?? 'error') : (int) $call['attempts']);

        if ($call['status'] === 'confirmed' && $o['confirmed_status']) {
            $order->update_status($o['confirmed_status'], 'PingoLine: '.$note);
        } elseif ($call['status'] === 'cancelled' && $o['cancelled_status']) {
            $order->update_status($o['cancelled_status'], 'PingoLine: '.$note);
        } else {
            $order->add_order_note('PingoLine: '.$note);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    private function find_order($call)
    {
        if (! empty($call['id'])) {
            $orders = wc_get_orders(['limit' => 1, 'meta_key' => self::META_CALL, 'meta_value' => $call['id']]);
            if (! $orders) {
                $orders = wc_get_orders(['limit' => 1, 'meta_key' => self::LEGACY_META_CALL, 'meta_value' => $call['id']]);
            }
            if ($orders) {
                return $orders[0];
            }
        }

        return empty($call['order_id']) ? null : wc_get_order((int) $call['order_id']);
    }
}

class_alias('PingoLine_Order_Confirm', 'XPBX_Order_Confirm');

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        PingoLine_Order_Confirm::boot();
    }
});
