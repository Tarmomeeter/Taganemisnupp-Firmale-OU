<?php
/**
 * Plugin Name: Firmale OÜ – WooCommerce taganemisvorm
 * Description: Tellimuse kontrolli, 14-päevase tähtaja, tootepõhise avalduse ja WooCommerce’i osalise rahatagastusega kliendivorm.
 * Version: 1.2.0-rc.4
 * Author: Firmale OÜ
 * Update URI: https://github.com/Tarmomeeter/Taganemisnupp-Firmale-OU
 * Text Domain: firmale-taganemisvorm
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.2
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FIRMALE_RETURN_VERSION', '1.2.0-rc.4');
define('FIRMALE_RETURN_FILE', __FILE__);
define('FIRMALE_RETURN_PATH', plugin_dir_path(__FILE__));
define('FIRMALE_RETURN_URL', plugin_dir_url(__FILE__));

require_once FIRMALE_RETURN_PATH . 'includes/class-firmale-return-lock.php';
require_once FIRMALE_RETURN_PATH . 'includes/class-firmale-return-features.php';
require_once FIRMALE_RETURN_PATH . 'includes/class-firmale-return-plugin.php';
require_once FIRMALE_RETURN_PATH . 'includes/class-firmale-return-admin.php';
require_once FIRMALE_RETURN_PATH . 'includes/class-firmale-return-updater.php';

register_activation_hook(__FILE__, array('Firmale_Return_Features', 'activate'));
register_deactivation_hook(__FILE__, array('Firmale_Return_Features', 'deactivate'));

add_action('before_woocommerce_init', function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            FIRMALE_RETURN_FILE,
            true
        );
    }
});

add_action('plugins_loaded', function () {
    Firmale_Return_Features::instance();
    Firmale_Return_Plugin::instance();
    Firmale_Return_Admin::instance();
    Firmale_Return_Updater::instance();
});
