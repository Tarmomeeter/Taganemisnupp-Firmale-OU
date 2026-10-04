<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Firmale_Return_Features
{
    const OTP_PREFIX = 'firmale_return_otp_';
    const CRON_HOOK = 'firmale_return_daily_cleanup';
    const REFUND_CAP = 'firmale_process_refunds';

    private static $instance;

    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_shortcode('firmale_tagastuse_staatus', array($this, 'render_tracking_shortcode'));
        add_action('admin_post_firmale_return_attachment', array($this, 'download_attachment'));
        add_action(self::CRON_HOOK, array($this, 'run_retention_cleanup'));
        add_action('admin_init', array($this, 'sync_capabilities'));
        add_action('woocommerce_product_options_general_product_data', array($this, 'product_options'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_options'));
        add_filter('wp_privacy_personal_data_exporters', array($this, 'register_privacy_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array($this, 'register_privacy_eraser'));
        add_action('updated_post_meta', array($this, 'status_changed'), 10, 4);
        add_action('added_post_meta', array($this, 'status_changed'), 10, 4);
        add_action('template_redirect', array($this, 'protect_private_page'), 0);
    }

    public function protect_private_page()
    {
        if (!isset($_GET['return_token'])) { return; }
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    }

    public static function activate()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
        self::grant_capabilities(true);
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private static function grant_capabilities($include_shop_manager)
    {
        $administrator = get_role('administrator');
        if ($administrator) {
            $administrator->add_cap(self::REFUND_CAP);
        }
        $shop_manager = get_role('shop_manager');
        if ($shop_manager) {
            if ($include_shop_manager) {
                $shop_manager->add_cap(self::REFUND_CAP);
            } else {
                $shop_manager->remove_cap(self::REFUND_CAP);
            }
        }
    }

    public function sync_capabilities()
    {
        self::grant_capabilities((bool) get_option('firmale_return_refund_shop_managers', true));
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public function can_process_refunds()
    {
        if (!(bool) get_option('firmale_return_separate_refund_capability', false)) {
            return current_user_can('manage_woocommerce');
        }
        return current_user_can('manage_woocommerce') && current_user_can(self::REFUND_CAP);
    }

    public function turnstile_enabled()
    {
        return (bool) get_option('firmale_return_enable_turnstile', false);
    }

    public function turnstile_ready()
    {
        return $this->turnstile_enabled()
            && trim((string) get_option('firmale_return_turnstile_site_key', '')) !== ''
            && trim((string) get_option('firmale_return_turnstile_secret_key', '')) !== '';
    }

    public function verify_turnstile($token)
    {
        if (!$this->turnstile_enabled()) {
            return true;
        }
        $secret = trim((string) get_option('firmale_return_turnstile_secret_key', ''));
        if ($secret === '') {
            return new WP_Error('firmale_turnstile_config', 'Turvakontroll ei ole korrektselt seadistatud. Palun võta ühendust klienditeenindusega.');
        }
        if (!is_string($token) || !$token || strlen($token) > 2048) {
            return new WP_Error('firmale_turnstile_missing', 'Palun kinnita turvakontroll.');
        }

        $body = array(
            'secret'   => $secret,
            'response' => sanitize_text_field($token),
        );

        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
            'timeout' => 10,
            'redirection' => 0,
            'limit_response_size' => 16384,
            'body'    => $body,
        ));
        if (is_wp_error($response)) {
            return new WP_Error('firmale_turnstile_unavailable', 'Turvakontroll ei ole hetkel kättesaadav. Palun proovi uuesti.');
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || !is_array($data) || !isset($data['success']) || $data['success'] !== true) {
            return new WP_Error('firmale_turnstile_failed', 'Turvakontroll ebaõnnestus. Palun proovi uuesti.');
        }
        if (!isset($data['action']) || $data['action'] !== 'firmale-return-verify') {
            return new WP_Error('firmale_turnstile_action', 'Turvakontrolli vastus ei vasta vormile.');
        }
        $expected_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        if (empty($data['hostname']) || !is_string($data['hostname']) || strtolower($data['hostname']) !== $expected_host) {
            return new WP_Error('firmale_turnstile_host', 'Turvakontrolli vastus pärineb valelt domeenilt.');
        }
        return true;
    }

    public function otp_enabled()
    {
        return (bool) get_option('firmale_return_enable_email_otp', true);
    }

    public function create_otp($order, $verification_data)
    {
        $mail_key = 'firmale_otp_mail_' . hash('sha256', (string) $order->get_id());
        $mail_lock = Firmale_Return_Lock::acquire($mail_key);
        if (!$mail_lock || get_transient($mail_key)) {
            return new WP_Error('firmale_otp_wait', 'Palun oota enne uue koodi küsimist üks minut.');
        }
        set_transient($mail_key, 1, MINUTE_IN_SECONDS);
        $email = sanitize_email($order->get_billing_email());
        if (!$email) {
            return new WP_Error('firmale_otp_email', 'Tellimusel puudub korrektne e-posti aadress.');
        }
        try {
            $code = (string) random_int(100000, 999999);
        } catch (Exception $exception) {
            $code = (string) wp_rand(100000, 999999);
        }
        $token = wp_generate_password(48, false, false);
        $minutes = min(30, max(5, absint(get_option('firmale_return_otp_expiry', 10))));
        $payload = array(
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'attempts'  => 0,
            'expires'   => time() + ($minutes * MINUTE_IN_SECONDS),
            'data'      => $verification_data,
        );
        set_transient(self::OTP_PREFIX . hash('sha256', $token), $payload, $minutes * MINUTE_IN_SECONDS);

        $sent = wp_mail(
            $email,
            'Tagastusvormi kinnituskood',
            "Sinu tagastusvormi kinnituskood on: {$code}\n\nKood kehtib {$minutes} minutit. Kui sina seda ei küsinud, võid kirja eirata."
        );
        if (!$sent) {
            delete_transient(self::OTP_PREFIX . hash('sha256', $token));
            return new WP_Error('firmale_otp_send', 'Kinnituskoodi saatmine ebaõnnestus. Palun proovi uuesti.');
        }

        $parts = explode('@', $email, 2);
        $local = isset($parts[0]) ? $parts[0] : '';
        $domain = isset($parts[1]) ? $parts[1] : '';
        $masked = substr($local, 0, min(2, strlen($local))) . str_repeat('*', max(2, strlen($local) - 2)) . '@' . $domain;

        return array('token' => $token, 'masked_email' => $masked, 'expires_minutes' => $minutes);
    }

    public function confirm_otp($token, $code)
    {
        if (!is_string($token) || !preg_match('/^[a-zA-Z0-9]{48}$/D', $token) || !is_string($code) || !preg_match('/^[0-9]{6}$/D', $code)) {
            return new WP_Error('firmale_otp_invalid', 'Kinnituskoodi andmed ei ole korrektsed.');
        }
        $key = self::OTP_PREFIX . hash('sha256', (string) $token);
        $lock = Firmale_Return_Lock::acquire($key);
        if (!$lock) {
            return new WP_Error('firmale_otp_busy', 'Koodi kontroll juba käib. Palun oota.');
        }
        $payload = get_transient($key);
        if (!is_array($payload) || empty($payload['data']) || empty($payload['code_hash'])) {
            return new WP_Error('firmale_otp_expired', 'Kinnituskood on aegunud. Palun kontrolli tellimus uuesti.');
        }
        if (time() > (int) $payload['expires']) {
            delete_transient($key);
            return new WP_Error('firmale_otp_expired', 'Kinnituskood on aegunud. Palun kontrolli tellimus uuesti.');
        }
        $payload['attempts'] = isset($payload['attempts']) ? (int) $payload['attempts'] + 1 : 1;
        if ($payload['attempts'] > 5) {
            delete_transient($key);
            return new WP_Error('firmale_otp_attempts', 'Liiga palju ebaõnnestunud katseid. Palun kontrolli tellimus uuesti.');
        }
        if (!password_verify((string) $code, $payload['code_hash'])) {
            if ($payload['attempts'] >= 5) {
                delete_transient($key);
            } else {
                set_transient($key, $payload, max(1, (int) $payload['expires'] - time()));
            }
            return new WP_Error('firmale_otp_invalid', 'Kinnituskood ei ole õige.');
        }
        delete_transient($key);
        return $payload['data'];
    }

    public function create_tracking_token($request_id)
    {
        $token = wp_generate_password(48, false, false);
        update_post_meta($request_id, '_firmale_tracking_token_hash', hash('sha256', $token));
        return $token;
    }

    public function tracking_url($token)
    {
        if (!(bool) get_option('firmale_return_enable_tracking', true)) {
            return '';
        }
        $base = trim((string) get_option('firmale_return_tracking_page_url', ''));
        if (!$base) {
            return '';
        }
        return add_query_arg('return_token', rawurlencode($token), $base);
    }

    public function render_tracking_shortcode()
    {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        if (!headers_sent()) {
            nocache_headers();
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
        }
        if (!(bool) get_option('firmale_return_enable_tracking', true)) {
            return '<p>Tagastuse jälgimine ei ole hetkel saadaval.</p>';
        }
        $token = isset($_GET['return_token']) ? sanitize_text_field(wp_unslash($_GET['return_token'])) : '';
        if (!preg_match('/^[a-zA-Z0-9]{48}$/D', $token)) {
            return '<p>Sisestatud jälgimislink ei ole täielik.</p>';
        }
        $requests = get_posts(array(
            'post_type'      => Firmale_Return_Plugin::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_key'       => '_firmale_tracking_token_hash',
            'meta_value'     => hash('sha256', $token),
            'fields'         => 'ids',
        ));
        if (!$requests) {
            return '<p>Tagastust ei leitud või jälgimislink ei ole enam kehtiv.</p>';
        }
        $request_id = (int) $requests[0];
        $status = get_post_meta($request_id, '_firmale_status', true) ?: 'new';
        $labels = $this->status_labels();
        $code = get_post_meta($request_id, '_firmale_return_code', true);
        $tracking = get_post_meta($request_id, '_firmale_return_tracking_url', true);
        wp_enqueue_style('firmale-return-form');
        ob_start();
        ?>
        <section class="firmale-return firmale-return__tracking">
            <div class="firmale-return__card">
                <p class="firmale-return__eyebrow">TAGASTUSE OLEK</p>
                <h2>Avaldus #<?php echo esc_html($request_id); ?></h2>
                <div class="firmale-return__status">
                    <strong><?php echo esc_html(isset($labels[$status]) ? $labels[$status] : $status); ?></strong>
                    <p>Viimati uuendatud <?php echo esc_html(get_post_meta($request_id, '_firmale_updated_at', true) ?: get_the_modified_date('d.m.Y H:i', $request_id)); ?></p>
                </div>
                <?php if ($code) : ?><p><strong>Tagastuskood:</strong> <?php echo esc_html($code); ?></p><?php endif; ?>
                <?php if ($tracking) : ?><p><a href="<?php echo esc_url($tracking); ?>" rel="nofollow noopener noreferrer" referrerpolicy="no-referrer">Jälgi tagastussaadetist</a></p><?php endif; ?>
                <?php if ((bool) get_option('firmale_return_enable_print_document', true)) : ?>
                    <p class="firmale-return__print-action"><button class="firmale-return__button" type="button" onclick="window.print()">Prindi või salvesta PDF-ina</button></p>
                <?php endif; ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    public function audit($request_id, $event, $message, $context = array())
    {
        update_post_meta($request_id, '_firmale_updated_at', current_time('mysql'));
        if (!(bool) get_option('firmale_return_enable_audit_log', true)) { return; }
        // One row per event: parallel writes cannot replace one another's history.
        add_post_meta($request_id, '_firmale_audit_event', array(
            'time' => current_time('mysql'), 'user_id' => get_current_user_id(),
            'event' => sanitize_key($event), 'message' => sanitize_text_field($message),
            'context' => is_array($context) ? $context : array(),
        ));
    }

    public function audit_entries($request_id)
    {
        $legacy = get_post_meta($request_id, '_firmale_audit_log', true);
        return array_merge(is_array($legacy) ? $legacy : array(), get_post_meta($request_id, '_firmale_audit_event', false));
    }

    public function status_changed($meta_id, $request_id, $key, $value)
    {
        if ($key !== '_firmale_status' || get_post_type($request_id) !== Firmale_Return_Plugin::CPT) { return; }
        update_post_meta($request_id, '_firmale_updated_at', current_time('mysql'));
        if (in_array($value, array('completed', 'rejected'), true)) {
            update_post_meta($request_id, '_firmale_closed_timestamp', time());
        } else {
            delete_post_meta($request_id, '_firmale_closed_timestamp');
        }
    }

    public function send_status_email($request_id, $old_status, $new_status)
    {
        if (!(bool) get_option('firmale_return_status_emails', true) || $old_status === $new_status) {
            return;
        }
        $email = sanitize_email(get_post_meta($request_id, '_firmale_customer_email', true));
        if (!$email) {
            return;
        }
        $labels = $this->status_labels();
        $label = isset($labels[$new_status]) ? $labels[$new_status] : $new_status;
        $message = "Sinu tagastusavalduse #{$request_id} olek muutus.\n\nUus olek: {$label}.";
        $status_message = trim((string) get_post_meta($request_id, '_firmale_status_message', true));
        if ($status_message) {
            $message .= "\n\n" . $status_message;
        }
        $tracking_url = get_post_meta($request_id, '_firmale_tracking_url_customer', true);
        if ($tracking_url) {
            $message .= "\n\nJälgi tagastust: {$tracking_url}";
        }
        if (!wp_mail($email, 'Tagastusavalduse olek muutus', $message)) {
            $this->audit($request_id, 'email_failed', 'Olekuteavituse saatmine ebaõnnestus.');
        }
    }

    public function store_attachments($request_id, $files)
    {
        if (!(bool) get_option('firmale_return_enable_attachments', true) || empty($files['name'])) {
            return true;
        }
        foreach (array('name', 'tmp_name', 'size', 'error') as $field) {
            if (!isset($files[$field]) || !is_array($files[$field])) {
                return new WP_Error('firmale_attachment_shape', 'Manuste andmed on vigased.');
            }
        }
        if (count($files['name']) > 3) { return new WP_Error('firmale_attachment_count', 'Lisa kuni kolm faili.'); }
        $max_mb = min(5, max(1, absint(get_option('firmale_return_attachment_max_mb', 2))));
        $allowed = array('image/jpeg', 'image/png', 'image/webp', 'application/pdf');
        if ((bool) get_option('firmale_return_enable_video_attachments', false)) {
            $allowed = array_merge($allowed, array('video/mp4', 'video/webm'));
        }
        $stored = array();
        $names = is_array($files['name']) ? $files['name'] : array($files['name']);
        $tmp_names = is_array($files['tmp_name']) ? $files['tmp_name'] : array($files['tmp_name']);
        $sizes = is_array($files['size']) ? $files['size'] : array($files['size']);
        $errors = is_array($files['error']) ? $files['error'] : array($files['error']);
        $limit = min(3, count($names));

        for ($index = 0; $index < $limit; $index++) {
            if (!isset($errors[$index], $tmp_names[$index], $names[$index], $sizes[$index])
                || !is_scalar($names[$index]) || !is_scalar($tmp_names[$index]) || !is_scalar($errors[$index])) {
                return new WP_Error('firmale_attachment_shape', 'Manuste andmed on vigased.');
            }
            if ((int) $errors[$index] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ((int) $errors[$index] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp_names[$index])
                || filesize($tmp_names[$index]) > $max_mb * MB_IN_BYTES || filesize($tmp_names[$index]) < 1) {
                return new WP_Error('firmale_attachment_size', sprintf('Manus peab olema kuni %d MB.', $max_mb));
            }
            $checked = wp_check_filetype_and_ext($tmp_names[$index], $names[$index]);
            $mime = isset($checked['type']) ? $checked['type'] : '';
            $detected = function_exists('finfo_open') ? (new finfo(FILEINFO_MIME_TYPE))->file($tmp_names[$index]) : '';
            if (!in_array($mime, $allowed, true) || $mime !== $detected) {
                return new WP_Error('firmale_attachment_type', 'Faili tegelik vorming pole lubatud või seda ei saa kontrollida. Kasuta JPG-, PNG-, WEBP- või PDF-faili; video vajab eraldi lubamist.');
            }
            $contents = file_get_contents($tmp_names[$index]);
            if ($contents === false) {
                return new WP_Error('firmale_attachment_read', 'Manuse lugemine ebaõnnestus.');
            }
            $stored[] = array(
                'name' => sanitize_file_name($names[$index]),
                'type' => $mime,
                'size' => strlen($contents),
                'data' => base64_encode($contents),
            );
        }
        if ($stored) {
            update_post_meta($request_id, '_firmale_private_attachments', $stored);
            $this->audit($request_id, 'attachments_added', 'Avaldusele lisati turvalised manused.', array('count' => count($stored)));
        }
        return true;
    }

    public function attachment_links($request_id)
    {
        $files = get_post_meta($request_id, '_firmale_private_attachments', true);
        if (!is_array($files) || !$files) {
            return array();
        }
        $links = array();
        foreach ($files as $index => $file) {
            $links[] = array(
                'name' => isset($file['name']) ? $file['name'] : ('manus-' . ($index + 1)),
                'url'  => wp_nonce_url(
                    admin_url('admin-post.php?action=firmale_return_attachment&request_id=' . absint($request_id) . '&file=' . absint($index)),
                    'firmale_return_attachment_' . absint($request_id) . '_' . absint($index)
                ),
            );
        }
        return $links;
    }

    public function download_attachment()
    {
        $request_id = isset($_GET['request_id']) ? absint($_GET['request_id']) : 0;
        $index = isset($_GET['file']) ? absint($_GET['file']) : 0;
        if (!current_user_can('manage_woocommerce') || get_post_type($request_id) !== Firmale_Return_Plugin::CPT || !current_user_can('edit_post', $request_id)) {
            wp_die('Ligipääs puudub.', '', array('response' => 403));
        }
        check_admin_referer('firmale_return_attachment_' . $request_id . '_' . $index);
        $files = get_post_meta($request_id, '_firmale_private_attachments', true);
        if (!is_array($files) || !isset($files[$index]['data'])) {
            wp_die('Faili ei leitud.', '', array('response' => 404));
        }
        $file = $files[$index];
        $contents = base64_decode($file['data'], true);
        if ($contents === false) {
            wp_die('Fail on vigane.', '', array('response' => 500));
        }
        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox");
        header('Content-Type: ' . sanitize_mime_type($file['type']));
        header('Content-Length: ' . strlen($contents));
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($file['name']) . '"');
        echo $contents;
        exit;
    }

    public function product_options()
    {
        if (!(bool) get_option('firmale_return_enable_product_rules', true)) {
            return;
        }
        woocommerce_wp_checkbox(array(
            'id'          => '_firmale_return_manual_review',
            'label'       => 'Tagastus vajab käsitsi kontrolli',
            'description' => 'Toodet ei blokeerita automaatselt, kuid selle tagastus märgitakse erandina käsitsi kontrolli.',
        ));
        woocommerce_wp_text_input(array(
            'id'                => '_firmale_return_period_days',
            'label'             => 'Tagastusperiood päevades',
            'type'              => 'number',
            'custom_attributes' => array('min' => '14', 'max' => '365'),
            'description'       => 'Jäta tühjaks, et kasutada poe vaikimisi perioodi.',
            'desc_tip'          => true,
        ));
    }

    public function save_product_options($product_id)
    {
        if (!(bool) get_option('firmale_return_enable_product_rules', true)) {
            return;
        }
        update_post_meta($product_id, '_firmale_return_manual_review', isset($_POST['_firmale_return_manual_review']) ? 'yes' : 'no');
        $days = isset($_POST['_firmale_return_period_days']) ? absint(wp_unslash($_POST['_firmale_return_period_days'])) : 0;
        if ($days) {
            update_post_meta($product_id, '_firmale_return_period_days', min(365, max(14, $days)));
        } else {
            delete_post_meta($product_id, '_firmale_return_period_days');
        }
    }

    public function product_policy($product_id)
    {
        $product = wc_get_product($product_id);
        $parent_id = $product ? (int) $product->get_parent_id() : 0;
        $base_id = $parent_id ?: $product_id;
        $days = 0;
        $manual = false;
        if ((bool) get_option('firmale_return_enable_product_rules', true)) {
            $manual = get_post_meta($product_id, '_firmale_return_manual_review', true) === 'yes' || get_post_meta($base_id, '_firmale_return_manual_review', true) === 'yes';
            $days = max(absint(get_post_meta($product_id, '_firmale_return_period_days', true)), absint(get_post_meta($base_id, '_firmale_return_period_days', true)));
        }
        if ((bool) get_option('firmale_return_enable_category_period', false)) {
            $categories = array_filter(array_map('absint', explode(',', (string) get_option('firmale_return_extended_categories', ''))));
            if ($categories && has_term($categories, 'product_cat', $base_id)) {
                $days = max($days, min(365, max(14, absint(get_option('firmale_return_category_period_days', 30)))));
            }
        }
        return array(
            'manual_review' => $manual,
            'period_days'   => min(365, $days),
        );
    }

    public function request_carrier_code($request_id)
    {
        if (!current_user_can('manage_woocommerce') || get_post_type($request_id) !== Firmale_Return_Plugin::CPT) {
            return new WP_Error('firmale_carrier_permission', 'Ligipääs puudub.');
        }
        if (!(bool) get_option('firmale_return_enable_carrier_connector', false)) {
            return new WP_Error('firmale_carrier_disabled', 'Vedaja ühendus ei ole sisse lülitatud.');
        }
        $lock = Firmale_Return_Lock::acquire('carrier-' . absint($request_id));
        if (!$lock || get_post_meta($request_id, '_firmale_return_code', true)) {
            return new WP_Error('firmale_carrier_exists', 'Tagastuskood on juba olemas või loomisel.');
        }
        $endpoint = esc_url_raw((string) get_option('firmale_return_carrier_webhook_url', ''));
        if (!$endpoint || strtolower(wp_parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
            return new WP_Error('firmale_carrier_endpoint', 'Vedaja HTTPS ühenduse aadress puudub.');
        }
        $order_id = absint(get_post_meta($request_id, '_firmale_order_id', true));
        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order) {
            return new WP_Error('firmale_carrier_order', 'Tellimust ei leitud.');
        }
        $payload = array(
            'provider'   => sanitize_key(get_option('firmale_return_carrier_provider', 'omniva')),
            'request_id' => absint($request_id),
            'order_id'   => $order->get_id(),
            'order_no'   => $order->get_order_number(),
            'method'     => get_post_meta($request_id, '_firmale_return_method', true),
            'timestamp'  => time(),
            'idempotency_key' => hash('sha256', home_url() . '|return|' . absint($request_id)),
            'customer'   => array(
                'name'  => get_post_meta($request_id, '_firmale_customer_name', true),
                'email' => get_post_meta($request_id, '_firmale_customer_email', true),
                'phone' => $order->get_billing_phone(),
            ),
        );
        $json = wp_json_encode($payload);
        $secret = (string) get_option('firmale_return_carrier_webhook_secret', '');
        if (strlen($secret) < 16) {
            return new WP_Error('firmale_carrier_secret', 'Vedaja ühenduse salavõti peab olema vähemalt 16 märki.');
        }
        $response = wp_safe_remote_post($endpoint, array(
            'timeout' => 20,
            'redirection' => 0,
            'limit_response_size' => 32768,
            'headers' => array(
                'Content-Type'       => 'application/json',
                'Idempotency-Key' => $payload['idempotency_key'],
                'X-Firmale-Signature'=> hash_hmac('sha256', $json, $secret),
            ),
            'body' => $json,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300 || empty($data['return_code']) || !is_string($data['return_code']) || strlen($data['return_code']) > 200) {
            return new WP_Error('firmale_carrier_response', 'Vedaja ühendus ei tagastanud korrektset tagastuskoodi.');
        }
        foreach (array('tracking_url', 'label_url') as $url_key) {
            if (!empty($data[$url_key]) && (!is_string($data[$url_key]) || wp_parse_url($data[$url_key], PHP_URL_SCHEME) !== 'https')) {
                return new WP_Error('firmale_carrier_url', 'Vedaja vastus sisaldas vigast linki.');
            }
        }
        update_post_meta($request_id, '_firmale_return_code', sanitize_text_field($data['return_code']));
        if (!empty($data['tracking_url'])) {
            update_post_meta($request_id, '_firmale_return_tracking_url', esc_url_raw($data['tracking_url']));
        }
        if (!empty($data['label_url'])) {
            update_post_meta($request_id, '_firmale_return_label_url', esc_url_raw($data['label_url']));
        }
        $this->audit($request_id, 'carrier_code_created', 'Vedaja tagastuskood loodi.', array('provider' => $payload['provider']));
        $old_status = get_post_meta($request_id, '_firmale_status', true) ?: 'new';
        update_post_meta($request_id, '_firmale_status', 'instructions_sent');
        $this->send_status_email($request_id, $old_status, 'instructions_sent');
        $email_lines = array(
            'Sinu tagastusjuhised on valmis.',
            'Tagastuskood: ' . sanitize_text_field($data['return_code']),
        );
        if (!empty($data['tracking_url'])) {
            $email_lines[] = 'Saadetise jälgimine: ' . esc_url_raw($data['tracking_url']);
        }
        if (!empty($data['label_url'])) {
            $email_lines[] = 'Pakisilt: ' . esc_url_raw($data['label_url']);
        }
        if ((bool) get_option('firmale_return_status_emails', true)) { wp_mail(
            sanitize_email(get_post_meta($request_id, '_firmale_customer_email', true)),
            'Tagastuskood ja tagastusjuhised',
            implode("\n", $email_lines)
        ); }
        return $data;
    }

    public function create_store_credit($request_id)
    {
        // A fixed-cart coupon is a discount, not money with a reusable balance.
        // Capture the customer's preference, but never silently exchange their refund for a coupon.
        return new WP_Error('firmale_credit_integration_required',
            'Poekrediidi soov on salvestatud. Krediidi väljastamiseks on vaja eraldi saldopõhise poekrediidi lahenduse ühendust. Tavalist sooduskupongi automaatselt ei looda.');
    }

    public function register_privacy_exporter($exporters)
    {
        if ((bool) get_option('firmale_return_enable_privacy_tools', true)) {
            $exporters['firmale-return'] = array(
                'exporter_friendly_name' => 'Firmale OÜ tagastusavaldused',
                'callback'               => array($this, 'privacy_exporter'),
            );
        }
        return $exporters;
    }

    public function register_privacy_eraser($erasers)
    {
        if ((bool) get_option('firmale_return_enable_privacy_tools', true)) {
            $erasers['firmale-return'] = array(
                'eraser_friendly_name' => 'Firmale OÜ tagastusavaldused',
                'callback'             => array($this, 'privacy_eraser'),
            );
        }
        return $erasers;
    }

    public function privacy_exporter($email_address, $page = 1)
    {
        $ids = get_posts(array(
            'post_type'      => Firmale_Return_Plugin::CPT,
            'post_status'    => 'any',
            'posts_per_page' => 20,
            'paged'          => max(1, absint($page)),
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => '_firmale_customer_email',
            'meta_value'     => sanitize_email($email_address),
            'fields'         => 'ids',
        ));
        $data = array();
        foreach ($ids as $request_id) {
            $data[] = array(
                'group_id'    => 'firmale-return',
                'group_label' => 'Tagastusavaldused',
                'item_id'     => 'firmale-return-' . $request_id,
                'data'        => array(
                    array('name' => 'Avalduse number', 'value' => $request_id),
                    array('name' => 'Nimi', 'value' => get_post_meta($request_id, '_firmale_customer_name', true)),
                    array('name' => 'E-post', 'value' => get_post_meta($request_id, '_firmale_customer_email', true)),
                    array('name' => 'Lisainfo', 'value' => get_post_meta($request_id, '_firmale_details', true)),
                    array('name' => 'Olek', 'value' => get_post_meta($request_id, '_firmale_status', true)),
                    array('name' => 'Tooted', 'value' => wp_json_encode(get_post_meta($request_id, '_firmale_items', true))),
                    array('name' => 'Kuupäevad', 'value' => wp_json_encode(get_post_meta($request_id, '_firmale_date_info', true))),
                    array('name' => 'Põhjus', 'value' => get_post_meta($request_id, '_firmale_reason', true)),
                    array('name' => 'Soovitud lahendus', 'value' => get_post_meta($request_id, '_firmale_resolution', true)),
                    array('name' => 'Kliendile saadetud sõnum', 'value' => get_post_meta($request_id, '_firmale_status_message', true)),
                    array('name' => 'Tagastusviis', 'value' => get_post_meta($request_id, '_firmale_return_method', true)),
                    array('name' => 'Manuste nimed', 'value' => implode(', ', wp_list_pluck((array) get_post_meta($request_id, '_firmale_private_attachments', true), 'name'))),
                ),
            );
        }
        return array('data' => $data, 'done' => count($ids) < 20);
    }

    public function privacy_eraser($email_address, $page = 1)
    {
        $ids = get_posts(array(
            'post_type' => Firmale_Return_Plugin::CPT, 'post_status' => 'any', 'posts_per_page' => 20,
            'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids',
            'meta_query' => array(
                array('key' => '_firmale_customer_email', 'value' => sanitize_email($email_address)),
                array('key' => '_firmale_status', 'value' => array('completed', 'rejected'), 'compare' => 'IN'),
            ),
        ));
        foreach ($ids as $request_id) { $this->anonymize_request($request_id); }
        return array(
            'items_removed' => !empty($ids), 'items_retained' => true,
            'messages' => array('Eemaldati lõpetatud avalduste kontaktandmed, vaba tekst, manused ja jälgimislingid. Avatud avaldused ning tellimusega seotud tehingu- ja auditikirjed säilitati. WooCommerce tellimused ja varukoopiad on eraldi.'),
            'done' => count($ids) < 20,
        );
    }

    public function run_retention_cleanup()
    {
        if (!(bool) get_option('firmale_return_enable_retention', false)) { return; }
        $days = min(3650, max(30, absint(get_option('firmale_return_retention_days', 730))));
        $ids = get_posts(array(
            'post_type' => Firmale_Return_Plugin::CPT, 'post_status' => 'publish',
            'posts_per_page' => 100, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
            'meta_query' => array(
                array('key' => '_firmale_status', 'value' => array('completed', 'rejected'), 'compare' => 'IN'),
                array('key' => '_firmale_closed_timestamp', 'value' => time() - $days * DAY_IN_SECONDS, 'type' => 'NUMERIC', 'compare' => '<'),
                array('key' => '_firmale_anonymized_at', 'compare' => 'NOT EXISTS'),
            ),
        ));
        foreach ($ids as $request_id) { $this->anonymize_request($request_id); }
    }

    private function anonymize_request($request_id)
    {
        if (!in_array(get_post_meta($request_id, '_firmale_status', true), array('completed', 'rejected'), true)) { return; }
        update_post_meta($request_id, '_firmale_customer_name', 'Kontaktandmed eemaldatud');
        foreach (array('_firmale_customer_email', '_firmale_details', '_firmale_private_attachments',
            '_firmale_tracking_token_hash', '_firmale_tracking_url_customer', '_firmale_status_message',
            '_firmale_return_code', '_firmale_return_tracking_url', '_firmale_return_label_url',
            '_firmale_received_evidence') as $key) {
            delete_post_meta($request_id, $key);
        }
        update_post_meta($request_id, '_firmale_anonymized_at', current_time('mysql'));
        $this->audit($request_id, 'privacy_cleaned', 'Kontaktandmed, manused ja vaba tekst eemaldati; tehinguseos säilitati.');
    }

    public function status_labels()
    {
        return array(
            'new'               => 'Uus',
            'manual_review'     => 'Vajab käsitsi kontrolli',
            'instructions_sent' => 'Tagastusjuhised saadetud',
            'in_transit'        => 'Tagastuspakk teel',
            'received'          => 'Kaup saabunud',
            'inspecting'        => 'Kaup kontrollimisel',
            'reviewing'         => 'Menetluses',
            'accepted'          => 'Kinnitatud',
            'rejected'          => 'Tagasi lükatud',
            'manual_payment_due'=> 'Tagastuskanne loodud – ootab käsitsi makset',
            'completed'         => 'Menetlus lõpetatud',
        );
    }
}
