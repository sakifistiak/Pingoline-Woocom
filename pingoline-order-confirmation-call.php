<?php
/**
 * Plugin Name:       PingoLine Order Confirmation Call
 * Description:       Automatic phone call confirmations for WooCommerce orders in Bangladesh: press 1 to confirm, 2 to cancel. Powered by PingoLine Cloud PBX.
 * Version:           1.3.2
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
        add_action('admin_head', [$self, 'menu_icon_style']);
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
        add_menu_page(
            esc_html__('PingoLine Order Call', 'pingoline-order-confirmation-call'),
            esc_html__('PingoLine', 'pingoline-order-confirmation-call'),
            'manage_woocommerce',
            'pingoline-order-confirm',
            [$this, 'dashboard_page'],
            plugins_url('assets/pingoline-icon.png', __FILE__),
            56
        );

        add_submenu_page(
            'pingoline-order-confirm',
            esc_html__('Order confirmation', 'pingoline-order-confirmation-call'),
            esc_html__('Order confirmation', 'pingoline-order-confirmation-call'),
            'manage_woocommerce',
            'pingoline-order-confirm',
            [$this, 'dashboard_page']
        );

        add_submenu_page(
            'pingoline-order-confirm',
            esc_html__('Call logs', 'pingoline-order-confirmation-call'),
            esc_html__('Call logs', 'pingoline-order-confirmation-call'),
            'manage_woocommerce',
            'pingoline-order-logs',
            [$this, 'logs_page']
        );

        add_submenu_page(
            'pingoline-order-confirm',
            esc_html__('Settings', 'pingoline-order-confirmation-call'),
            esc_html__('Settings', 'pingoline-order-confirmation-call'),
            'manage_woocommerce',
            'pingoline-order-settings',
            [$this, 'settings_page']
        );
    }

    public function menu_icon_style()
    {
        echo '<style>#toplevel_page_pingoline-order-confirm .wp-menu-image img{width:22px;height:22px;padding:6px 0;border-radius:50%;opacity:1;}</style>';
    }

    public function settings_link($links)
    {
        array_unshift($links, '<a href="'.esc_url(admin_url('admin.php?page=pingoline-order-settings')).'">'.esc_html__('Settings', 'pingoline-order-confirmation-call').'</a>');

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

    public function dashboard_page()
    {
        $this->render_page(
            'pingoline-order-confirm',
            __('Order confirmation calls', 'pingoline-order-confirmation-call'),
            __('Every new order gets an automatic call: press 1 to confirm, 2 to cancel. Unanswered calls are retried and results update the order automatically.', 'pingoline-order-confirmation-call'),
            function ($o, $preset, $is_configured) {
                $this->render_dashboard_tab($is_configured);
            }
        );
    }

    public function logs_page()
    {
        $this->render_page(
            'pingoline-order-logs',
            __('Call logs', 'pingoline-order-confirmation-call'),
            __('Search and filter all WooCommerce order confirmation calls placed through PingoLine.', 'pingoline-order-confirmation-call'),
            function () {
                $this->render_logs_tab();
            }
        );
    }

    public function settings_page()
    {
        $this->render_page(
            'pingoline-order-settings',
            __('PingoLine settings', 'pingoline-order-confirmation-call'),
            __('Connect your PingoLine account, copy your store webhook URL, and configure automatic order call rules.', 'pingoline-order-confirmation-call'),
            function ($o, $preset) {
                $this->render_settings_tab($o, $preset);
            }
        );
    }

    private function render_page($page_slug, $title, $subtitle, $content_cb)
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'pingoline-order-confirmation-call'));
        }

        $o = self::options();
        $preset = self::preset();

        $ping = null;
        if ($o['api_url'] && $o['api_token'] && isset($_GET['pingoline_ping'])) {
            check_admin_referer('pingoline_test_ping', '_wpnonce');
            $ping = $this->api('GET', '/ping');
        }

        $test_ping_url = wp_nonce_url(
            add_query_arg(['page' => $page_slug, 'pingoline_ping' => 1], admin_url('admin.php')),
            'pingoline_test_ping',
            '_wpnonce'
        );
        $icon_url = plugins_url('assets/pingoline-icon.png', __FILE__);
        $is_configured = ! empty($o['api_url']) && ! empty($o['api_token']);
        ?>
        <div class="wrap pl-wrap">
            <h1 class="screen-reader-text"><?php esc_html_e('PingoLine Order Confirmation Call', 'pingoline-order-confirmation-call'); ?></h1>
            <hr class="wp-header-end">

            <style>
                .pl-wrap { width: 100%; max-width: none; margin: 16px 0 40px 0; padding-right: 20px; font-family: 'IBM Plex Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #0b1a2b; }
                .pl-wrap * { box-sizing: border-box; }
                .pl-shell { margin-top: 12px; }
                .pl-topbar { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
                .pl-title-row { display: flex; align-items: center; gap: 12px; }
                .pl-title-row img { width: 38px; height: 38px; border-radius: 50%; border: 1px solid #e3e8ec; flex-shrink: 0; }
                .pl-title-row h2 { margin: 0; font-size: 22px; font-weight: 700; color: #0b1a2b; line-height: 1.25; letter-spacing: -0.01em; }
                .pl-subtitle { margin: 4px 0 0; font-size: 13.5px; color: #5b6b78; line-height: 1.45; max-width: 620px; }
                .pl-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
                .pl-status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #ffffff; border: 1px solid #e3e8ec; color: #3b4c59; }
                .pl-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
                .pl-dot-ok { background: #0f8a5f; }
                .pl-dot-bad { background: #c4452a; }
                .pl-dot-accent { background: #ea580c; }
                .pl-dot-warn { background: #c27803; }
                .pl-dot-idle { background: #c3ccd3; }
                .pl-btn-primary { display: inline-flex; align-items: center; gap: 6px; background: #ea580c; color: #ffffff !important; font-weight: 600; font-size: 13px; padding: 8px 15px; border-radius: 8px; text-decoration: none; border: 1px solid #ea580c; cursor: pointer; line-height: 1.25; }
                .pl-btn-primary:hover { background: #c2410c; border-color: #c2410c; color: #ffffff !important; }
                .pl-btn-outline { display: inline-flex; align-items: center; gap: 6px; background: #ffffff; color: #0b1a2b !important; font-weight: 600; font-size: 13px; padding: 8px 14px; border-radius: 8px; border: 1px solid #d5dde3; cursor: pointer; text-decoration: none; line-height: 1.25; }
                .pl-btn-outline:hover { background: #f8fafb; border-color: #8a98a3; color: #0b1a2b !important; }
                .pl-btn-sm { padding: 6px 11px; font-size: 12px; border-radius: 6px; }
                .pl-tabs { display: flex; gap: 4px; margin: 0 0 18px; border-bottom: 1px solid #e3e8ec; }
                .pl-tab { padding: 9px 16px; font-size: 13.5px; font-weight: 600; color: #5b6b78; text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; display: inline-flex; align-items: center; gap: 6px; }
                .pl-tab:hover { color: #0b1a2b; }
                .pl-tab.active { color: #0b1a2b; border-bottom-color: #ea580c; }
                .pl-banner { padding: 12px 16px; border-radius: 10px; margin: 0 0 18px; font-size: 13px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
                .pl-banner-warn { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; }
                .pl-banner-ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #067647; }
                .pl-banner-err { background: #fef2f2; border: 1px solid #fecaca; color: #b42318; }
                .pl-stats-strip { background: #ffffff; border: 1px solid #e3e8ec; border-radius: 10px; display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); margin-bottom: 18px; overflow: hidden; }
                .pl-stat-cell { padding: 16px 18px; border-right: 1px solid #eef2f4; }
                .pl-stat-cell:last-child { border-right: none; }
                .pl-stat-head { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 500; color: #5b6b78; }
                .pl-stat-num { font-size: 24px; font-weight: 700; color: #0b1a2b; margin-top: 8px; line-height: 1.1; }
                .pl-card { background: #ffffff; border: 1px solid #e3e8ec; border-radius: 10px; margin-bottom: 18px; overflow: hidden; }
                .pl-card-head { padding: 14px 20px; border-bottom: 1px solid #eef2f4; display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #f8fafb; }
                .pl-card-head h3 { margin: 0; font-size: 14px; font-weight: 700; color: #0b1a2b; }
                .pl-card-head span { font-size: 12px; color: #5b6b78; }
                .pl-card-body { padding: 20px; }
                .pl-grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 18px; }
                .pl-field { margin-bottom: 16px; }
                .pl-field:last-child { margin-bottom: 0; }
                .pl-field label { display: block; font-size: 13px; font-weight: 600; color: #0b1a2b; margin-bottom: 6px; }
                .pl-input-group { display: flex; align-items: stretch; gap: 6px; }
                .pl-input-group input { flex: 1; }
                .pl-field input[type="text"], .pl-field input[type="password"], .pl-field input[type="url"], .pl-field select { width: 100%; max-width: 100%; padding: 8px 12px; border: 1px solid #d5dde3; border-radius: 8px; font-size: 13.5px; color: #0b1a2b; background: #ffffff; height: 38px; }
                .pl-field input:focus, .pl-field select:focus { outline: none; border-color: #ea580c; box-shadow: 0 0 0 2px rgba(234, 88, 12, 0.14); }
                .pl-field input[readonly] { background: #f8fafb; color: #5b6b78; }
                .pl-help { margin: 6px 0 0; font-size: 12px; color: #5b6b78; line-height: 1.45; }
                .pl-switch-row { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border: 1px solid #e3e8ec; border-radius: 8px; margin-bottom: 12px; background: #f8fafb; cursor: pointer; }
                .pl-switch-row input[type="checkbox"] { margin-top: 2px; accent-color: #ea580c; }
                .pl-switch-title { font-size: 13px; font-weight: 600; color: #0b1a2b; display: block; }
                .pl-switch-desc { font-size: 12px; color: #5b6b78; display: block; margin-top: 2px; }
                .pl-table-wrap { overflow-x: auto; }
                .pl-table { width: 100%; border-collapse: collapse; font-size: 13px; }
                .pl-table th { text-align: left; padding: 11px 18px; font-size: 12px; font-weight: 600; color: #5b6b78; background: #f8fafb; border-bottom: 1px solid #e3e8ec; }
                .pl-table td { padding: 12px 18px; border-bottom: 1px solid #eef2f4; color: #0b1a2b; vertical-align: middle; }
                .pl-table tr:last-child td { border-bottom: none; }
                .pl-badge { display: inline-flex; align-items: center; gap: 6px; padding: 3px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #f4f6f7; color: #3b4c59; }
                .pl-badge-confirmed { background: #ecfdf5; color: #067647; }
                .pl-badge-cancelled { background: #fef2f2; color: #b42318; }
                .pl-badge-queued { background: #eff6ff; color: #1d4ed8; }
                .pl-badge-agent { background: #fff7ed; color: #c2410c; }
                .pl-badge-unreached, .pl-badge-no_response { background: #fffbeb; color: #b45309; }
                .pl-badge-failed { background: #f4f6f7; color: #5b6b78; }
                .pl-empty { text-align: center; padding: 42px 20px; color: #5b6b78; font-size: 13.5px; }
                .pl-filter-bar { padding: 14px 18px; border-bottom: 1px solid #eef2f4; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; background: #ffffff; }
                .pl-filter-bar input[type="text"], .pl-filter-bar select { height: 34px; padding: 5px 10px; border: 1px solid #d5dde3; border-radius: 7px; font-size: 13px; color: #0b1a2b; background: #ffffff; }
                .pl-filter-bar input[type="text"] { min-width: 240px; }
                @media (max-width: 782px) { .pl-stats-strip { grid-template-columns: repeat(2, 1fr); } .pl-stat-cell { border-bottom: 1px solid #eef2f4; } }
            </style>

            <div class="pl-shell">
                <div class="pl-topbar">
                    <div class="pl-title-row">
                        <img src="<?php echo esc_url($icon_url); ?>" alt="PingoLine">
                        <div>
                            <h2><?php echo esc_html($title); ?></h2>
                            <p class="pl-subtitle"><?php echo esc_html($subtitle); ?></p>
                        </div>
                    </div>
                    <div class="pl-actions">
                        <span class="pl-status-pill">
                            <span class="pl-dot <?php echo $o['enabled'] === 'yes' ? 'pl-dot-ok' : 'pl-dot-idle'; ?>"></span>
                            <?php echo $o['enabled'] === 'yes' ? esc_html__('Auto-call active', 'pingoline-order-confirmation-call') : esc_html__('Auto-call paused', 'pingoline-order-confirmation-call'); ?>
                        </span>
                        <?php if ($page_slug !== 'pingoline-order-settings') : ?>
                            <a class="pl-btn-outline" href="<?php echo esc_url(admin_url('admin.php?page=pingoline-order-settings')); ?>">
                                <?php esc_html_e('API & Settings', 'pingoline-order-confirmation-call'); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($is_configured) : ?>
                            <a class="pl-btn-primary" href="<?php echo esc_url($test_ping_url); ?>">
                                <?php esc_html_e('Test connection', 'pingoline-order-confirmation-call'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (isset($_GET['settings-updated'])) : ?>
                    <div class="pl-banner pl-banner-ok">
                        <span><?php esc_html_e('Settings saved.', 'pingoline-order-confirmation-call'); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (! empty($preset['api_token'])) : ?>
                    <div class="pl-banner pl-banner-ok">
                        <span><?php
                            /* translators: %s: connected account name */
                            echo wp_kses_post(sprintf(__('Connected%s.', 'pingoline-order-confirmation-call'), empty($preset['account']) ? '' : ' — <strong>'.esc_html($preset['account']).'</strong>'));
                        ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($ping !== null) : ?>
                    <?php if (is_wp_error($ping) || $ping['code'] !== 200) : ?>
                        <div class="pl-banner pl-banner-err">
                            <span><?php
                                /* translators: %s: connection error message */
                                printf(esc_html__('Connection failed: %s', 'pingoline-order-confirmation-call'), esc_html(is_wp_error($ping) ? $ping->get_error_message() : ($ping['body']['error']['message'] ?? 'HTTP '.$ping['code'])));
                            ?></span>
                        </div>
                    <?php else : ?>
                        <div class="pl-banner pl-banner-ok">
                            <span><?php
                                /* translators: %s: connected account name */
                                printf(esc_html__('Connected to PingoLine account: %s.', 'pingoline-order-confirmation-call'), '<strong>'.esc_html($ping['body']['data']['account']).'</strong>');
                            ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php $content_cb($o, $preset, $is_configured); ?>
            </div>
        </div>
        <?php
    }

    private function get_call_stats()
    {
        $stats = [
            'total' => 0,
            'confirmed' => 0,
            'cancelled' => 0,
            'agent' => 0,
            'unreached' => 0,
            'queued' => 0,
            'recent' => [],
        ];

        if (! function_exists('wc_get_orders')) {
            return $stats;
        }

        $orders = wc_get_orders([
            'limit' => 100,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => self::META_CALL,
            'meta_compare' => 'EXISTS',
        ]);

        foreach ($orders as $order) {
            $status = (string) ($order->get_meta(self::META_STATUS) ?: $order->get_meta(self::LEGACY_META_STATUS) ?: 'queued');
            $stats['total']++;
            if ($status === 'confirmed') {
                $stats['confirmed']++;
            } elseif ($status === 'cancelled') {
                $stats['cancelled']++;
            } elseif ($status === 'agent') {
                $stats['agent']++;
            } elseif (in_array($status, ['unreached', 'no_response', 'failed'], true)) {
                $stats['unreached']++;
            } else {
                $stats['queued']++;
            }

            if (count($stats['recent']) < 15) {
                $stats['recent'][] = [
                    'id' => $order->get_id(),
                    'number' => $order->get_order_number(),
                    'edit_url' => $order->get_edit_order_url(),
                    'name' => trim($order->get_billing_first_name().' '.$order->get_billing_last_name()) ?: '—',
                    'phone' => $order->get_billing_phone() ?: $order->get_shipping_phone() ?: '—',
                    'total' => $order->get_formatted_order_total(),
                    'call_status' => $status,
                    'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n('M j, g:i A') : '—',
                ];
            }
        }

        return $stats;
    }

    private function render_dashboard_tab($is_configured)
    {
        $stats = $this->get_call_stats();
        ?>
        <?php if (! $is_configured) : ?>
            <div class="pl-banner pl-banner-warn">
                <span><?php esc_html_e('API credentials are not configured yet. Add your PingoLine API URL and Token to start automatic order calls.', 'pingoline-order-confirmation-call'); ?></span>
                <a class="pl-btn-primary pl-btn-sm" href="<?php echo esc_url(admin_url('admin.php?page=pingoline-order-settings')); ?>">
                    <?php esc_html_e('Configure', 'pingoline-order-confirmation-call'); ?>
                </a>
            </div>
        <?php endif; ?>

        <div class="pl-stats-strip">
            <div class="pl-stat-cell">
                <div class="pl-stat-head"><?php esc_html_e('Orders (Last 100)', 'pingoline-order-confirmation-call'); ?></div>
                <div class="pl-stat-num"><?php echo esc_html((string) $stats['total']); ?></div>
            </div>
            <div class="pl-stat-cell">
                <div class="pl-stat-head"><span class="pl-dot pl-dot-ok"></span><?php esc_html_e('Confirmed', 'pingoline-order-confirmation-call'); ?></div>
                <div class="pl-stat-num"><?php echo esc_html((string) $stats['confirmed']); ?></div>
            </div>
            <div class="pl-stat-cell">
                <div class="pl-stat-head"><span class="pl-dot pl-dot-bad"></span><?php esc_html_e('Cancelled', 'pingoline-order-confirmation-call'); ?></div>
                <div class="pl-stat-num"><?php echo esc_html((string) $stats['cancelled']); ?></div>
            </div>
            <div class="pl-stat-cell">
                <div class="pl-stat-head"><span class="pl-dot pl-dot-accent"></span><?php esc_html_e('To agent', 'pingoline-order-confirmation-call'); ?></div>
                <div class="pl-stat-num"><?php echo esc_html((string) $stats['agent']); ?></div>
            </div>
            <div class="pl-stat-cell">
                <div class="pl-stat-head"><span class="pl-dot pl-dot-idle"></span><?php esc_html_e('Not reached', 'pingoline-order-confirmation-call'); ?></div>
                <div class="pl-stat-num"><?php echo esc_html((string) $stats['unreached']); ?></div>
            </div>
        </div>

        <div class="pl-card">
            <div class="pl-table-wrap">
                <?php if (empty($stats['recent'])) : ?>
                    <div class="pl-empty">
                        <?php esc_html_e('No order calls yet. New WooCommerce orders will appear here automatically.', 'pingoline-order-confirmation-call'); ?>
                    </div>
                <?php else : ?>
                    <table class="pl-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Order', 'pingoline-order-confirmation-call'); ?></th>
                                <th><?php esc_html_e('Customer', 'pingoline-order-confirmation-call'); ?></th>
                                <th><?php esc_html_e('Phone', 'pingoline-order-confirmation-call'); ?></th>
                                <th><?php esc_html_e('Amount', 'pingoline-order-confirmation-call'); ?></th>
                                <th><?php esc_html_e('Status', 'pingoline-order-confirmation-call'); ?></th>
                                <th><?php esc_html_e('Time', 'pingoline-order-confirmation-call'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['recent'] as $row) : ?>
                                <?php
                                $badge_class = 'pl-badge-'.sanitize_html_class($row['call_status']);
                                $label = ucwords(str_replace('_', ' ', $row['call_status']));
                                ?>
                                <tr>
                                    <td><a href="<?php echo esc_url($row['edit_url']); ?>"><strong>#<?php echo esc_html($row['number']); ?></strong></a></td>
                                    <td><?php echo esc_html($row['name']); ?></td>
                                    <td><code><?php echo esc_html($row['phone']); ?></code></td>
                                    <td><?php echo wp_kses_post($row['total']); ?></td>
                                    <td><span class="pl-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($label); ?></span></td>
                                    <td><?php echo esc_html($row['date']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_logs_tab()
    {
        $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $status_filter = isset($_GET['call_status']) ? sanitize_key(wp_unslash($_GET['call_status'])) : '';
        $rows = [];

        if (function_exists('wc_get_orders')) {
            $args = [
                'limit' => 100,
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_key' => self::META_CALL,
                'meta_compare' => 'EXISTS',
            ];
            if ($status_filter !== '') {
                $args['meta_query'] = [
                    [
                        'key' => self::META_STATUS,
                        'value' => $status_filter,
                    ],
                ];
            }
            $orders = wc_get_orders($args);
            foreach ($orders as $order) {
                $number = (string) $order->get_order_number();
                $phone = (string) ($order->get_billing_phone() ?: $order->get_shipping_phone() ?: '');
                $name = trim($order->get_billing_first_name().' '.$order->get_billing_last_name());
                if ($search !== '') {
                    $needle = strtolower($search);
                    if (strpos(strtolower($number), $needle) === false && strpos(strtolower($phone), $needle) === false && strpos(strtolower($name), $needle) === false) {
                        continue;
                    }
                }
                $status = (string) ($order->get_meta(self::META_STATUS) ?: $order->get_meta(self::LEGACY_META_STATUS) ?: 'queued');
                $call_id = (string) ($order->get_meta(self::META_CALL) ?: $order->get_meta(self::LEGACY_META_CALL) ?: '—');
                $rows[] = [
                    'number' => $number,
                    'edit_url' => $order->get_edit_order_url(),
                    'name' => $name ?: '—',
                    'phone' => $phone ?: '—',
                    'total' => $order->get_formatted_order_total(),
                    'call_id' => $call_id,
                    'call_status' => $status,
                    'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n('M j, Y g:i A') : '—',
                ];
            }
        }

        $statuses = [
            '' => __('All statuses', 'pingoline-order-confirmation-call'),
            'confirmed' => __('Confirmed', 'pingoline-order-confirmation-call'),
            'cancelled' => __('Cancelled', 'pingoline-order-confirmation-call'),
            'agent' => __('To agent', 'pingoline-order-confirmation-call'),
            'queued' => __('Queued', 'pingoline-order-confirmation-call'),
            'unreached' => __('Not reached', 'pingoline-order-confirmation-call'),
            'no_response' => __('No response', 'pingoline-order-confirmation-call'),
            'failed' => __('Failed', 'pingoline-order-confirmation-call'),
        ];
        ?>
        <div class="pl-card">
            <form method="get" class="pl-filter-bar">
                <input type="hidden" name="page" value="pingoline-order-logs">
                <input type="text" name="q" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Order ID, customer, or phone', 'pingoline-order-confirmation-call'); ?>">
                <select name="call_status">
                    <?php foreach ($statuses as $val => $label) : ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php selected($status_filter, $val); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="pl-btn-outline pl-btn-sm"><?php esc_html_e('Search', 'pingoline-order-confirmation-call'); ?></button>
                <?php if ($search !== '' || $status_filter !== '') : ?>
                    <a class="pl-btn-outline pl-btn-sm" href="<?php echo esc_url(admin_url('admin.php?page=pingoline-order-logs')); ?>"><?php esc_html_e('Reset', 'pingoline-order-confirmation-call'); ?></a>
                <?php endif; ?>
            </form>
            <div class="pl-table-wrap">
                <?php if (empty($rows)) : ?>
                    <div class="pl-empty">
                        <?php esc_html_e('No matching call logs found.', 'pingoline-order-confirmation-call'); ?>
                    </div>
                <?php else : ?>
                    <table class="pl-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Order', 'pingoline-order-confirmation-call'); ?></th>
                                <th><?php esc_html_e('Customer', 'pingoline-order-confirmation-call'); ?></th>
                                <th><?php esc_html_e('Phone', 'pingoline-order-confirmation-call'); ?></th>
                                <th><?php esc_html_e('Amount', 'pingoline-order-confirmation-call'); ?></th>
                                <th><?php esc_html_e('Call ID', 'pingoline-order-confirmation-call'); ?></th>
                                <th><?php esc_html_e('Status', 'pingoline-order-confirmation-call'); ?></th>
                                <th><?php esc_html_e('Time', 'pingoline-order-confirmation-call'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <?php
                                $badge_class = 'pl-badge-'.sanitize_html_class($row['call_status']);
                                $label = ucwords(str_replace('_', ' ', $row['call_status']));
                                ?>
                                <tr>
                                    <td><a href="<?php echo esc_url($row['edit_url']); ?>"><strong>#<?php echo esc_html($row['number']); ?></strong></a></td>
                                    <td><?php echo esc_html($row['name']); ?></td>
                                    <td><code><?php echo esc_html($row['phone']); ?></code></td>
                                    <td><?php echo wp_kses_post($row['total']); ?></td>
                                    <td><code><?php echo esc_html($row['call_id']); ?></code></td>
                                    <td><span class="pl-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($label); ?></span></td>
                                    <td><?php echo esc_html($row['date']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_settings_tab($o, $preset)
    {
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        $webhook = rest_url('pingoline/v1/webhook');
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('pingoline_order_confirm'); ?>
            <div class="pl-grid-2">
                <div class="pl-card">
                    <div class="pl-card-head">
                        <h3><?php esc_html_e('API & Webhook Connection', 'pingoline-order-confirmation-call'); ?></h3>
                        <span><?php esc_html_e('PingoLine Portal', 'pingoline-order-confirmation-call'); ?></span>
                    </div>
                    <div class="pl-card-body">
                        <div class="pl-field">
                            <label for="pingoline_api_url"><?php esc_html_e('API URL', 'pingoline-order-confirmation-call'); ?></label>
                            <input id="pingoline_api_url" type="url" <?php echo empty($preset['api_token']) ? '' : 'readonly'; ?> name="<?php echo esc_attr(self::OPTION); ?>[api_url]" value="<?php echo esc_attr($o['api_url']); ?>" placeholder="https://pingoline.com/api/v1">
                            <p class="pl-help"><?php esc_html_e('From the PingoLine portal: Developer → API base URL.', 'pingoline-order-confirmation-call'); ?></p>
                        </div>

                        <div class="pl-field">
                            <label for="pingoline_api_token"><?php esc_html_e('API Token', 'pingoline-order-confirmation-call'); ?></label>
                            <div class="pl-input-group">
                                <input id="pingoline_api_token" <?php echo empty($preset['api_token']) ? '' : 'readonly'; ?> type="password" name="<?php echo esc_attr(self::OPTION); ?>[api_token]" value="<?php echo esc_attr($o['api_token']); ?>" autocomplete="off">
                                <button type="button" class="pl-btn-outline pl-btn-sm pl-toggle-secret" data-target="pingoline_api_token"><?php esc_html_e('Show', 'pingoline-order-confirmation-call'); ?></button>
                            </div>
                        </div>

                        <div class="pl-field">
                            <label for="pingoline_secret"><?php esc_html_e('Webhook Signing Secret', 'pingoline-order-confirmation-call'); ?></label>
                            <div class="pl-input-group">
                                <input id="pingoline_secret" <?php echo empty($preset['api_token']) ? '' : 'readonly'; ?> type="password" name="<?php echo esc_attr(self::OPTION); ?>[webhook_secret]" value="<?php echo esc_attr($o['webhook_secret']); ?>" autocomplete="off">
                                <button type="button" class="pl-btn-outline pl-btn-sm pl-toggle-secret" data-target="pingoline_secret"><?php esc_html_e('Show', 'pingoline-order-confirmation-call'); ?></button>
                            </div>
                        </div>

                        <div class="pl-field">
                            <label for="pingoline_webhook_url"><?php esc_html_e('Store Webhook URL', 'pingoline-order-confirmation-call'); ?></label>
                            <div class="pl-input-group">
                                <input id="pingoline_webhook_url" type="text" readonly value="<?php echo esc_attr($webhook); ?>">
                                <button type="button" class="pl-btn-outline pl-btn-sm" id="pl-copy-webhook"><?php esc_html_e('Copy', 'pingoline-order-confirmation-call'); ?></button>
                            </div>
                            <p class="pl-help"><?php
                                /* translators: %s: site domain */
                                printf(esc_html__('In the PingoLine portal open Developer → Connected sites, add %s and paste this Webhook URL.', 'pingoline-order-confirmation-call'), '<code>'.esc_html(home_url()).'</code>');
                            ?></p>
                        </div>
                    </div>
                </div>

                <div class="pl-card">
                    <div class="pl-card-head">
                        <h3><?php esc_html_e('Call Behavior & Order Status', 'pingoline-order-confirmation-call'); ?></h3>
                        <span><?php esc_html_e('Automation Rules', 'pingoline-order-confirmation-call'); ?></span>
                    </div>
                    <div class="pl-card-body">
                        <label class="pl-switch-row">
                            <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enabled]" value="1" <?php checked($o['enabled'], 'yes'); ?>>
                            <span>
                                <span class="pl-switch-title"><?php esc_html_e('Enable Automatic Calls', 'pingoline-order-confirmation-call'); ?></span>
                                <span class="pl-switch-desc"><?php esc_html_e('Automatically trigger a confirmation call when a new order is placed.', 'pingoline-order-confirmation-call'); ?></span>
                            </span>
                        </label>

                        <label class="pl-switch-row">
                            <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[only_cod]" value="1" <?php checked($o['only_cod'], 'yes'); ?>>
                            <span>
                                <span class="pl-switch-title"><?php esc_html_e('Only Cash on Delivery (COD) Orders', 'pingoline-order-confirmation-call'); ?></span>
                                <span class="pl-switch-desc"><?php esc_html_e('Skip prepaid/card/mobile-banking orders and only verify COD orders.', 'pingoline-order-confirmation-call'); ?></span>
                            </span>
                        </label>

                        <div class="pl-field">
                            <label for="pingoline_caller"><?php esc_html_e('Caller Number (096...)', 'pingoline-order-confirmation-call'); ?></label>
                            <input id="pingoline_caller" type="text" name="<?php echo esc_attr(self::OPTION); ?>[caller_number]" value="<?php echo esc_attr($o['caller_number']); ?>" placeholder="<?php esc_attr_e('Leave empty for your default IP number', 'pingoline-order-confirmation-call'); ?>">
                        </div>

                        <div class="pl-field">
                            <label for="pingoline_ok"><?php esc_html_e('When Confirmed (Customer presses 1)', 'pingoline-order-confirmation-call'); ?></label>
                            <select id="pingoline_ok" name="<?php echo esc_attr(self::OPTION); ?>[confirmed_status]"><?php $this->status_options($statuses, $o['confirmed_status']); ?></select>
                        </div>

                        <div class="pl-field">
                            <label for="pingoline_no"><?php esc_html_e('When Cancelled (Customer presses 2)', 'pingoline-order-confirmation-call'); ?></label>
                            <select id="pingoline_no" name="<?php echo esc_attr(self::OPTION); ?>[cancelled_status]"><?php $this->status_options($statuses, $o['cancelled_status']); ?></select>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display:flex;align-items:center;gap:12px;">
                <button type="submit" class="pl-btn-primary"><?php esc_html_e('Save Settings', 'pingoline-order-confirmation-call'); ?></button>
            </div>
        </form>

        <script>
            (function () {
                document.querySelectorAll('.pl-toggle-secret').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var input = document.getElementById(btn.getAttribute('data-target'));
                        if (!input) return;
                        var isPass = input.type === 'password';
                        input.type = isPass ? 'text' : 'password';
                        btn.textContent = isPass ? '<?php echo esc_js(__('Hide', 'pingoline-order-confirmation-call')); ?>' : '<?php echo esc_js(__('Show', 'pingoline-order-confirmation-call')); ?>';
                    });
                });

                var copyBtn = document.getElementById('pl-copy-webhook');
                var webhookInput = document.getElementById('pingoline_webhook_url');
                if (copyBtn && webhookInput) {
                    copyBtn.addEventListener('click', function () {
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(webhookInput.value);
                        } else {
                            webhookInput.select();
                            document.execCommand('copy');
                        }
                        var orig = copyBtn.textContent;
                        copyBtn.textContent = '<?php echo esc_js(__('Copied!', 'pingoline-order-confirmation-call')); ?>';
                        setTimeout(function () { copyBtn.textContent = orig; }, 1600);
                    });
                }
            })();
        </script>
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
