<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Firmale_Return_Plugin
{
    const CPT = 'firmale_return';
    const TOKEN_PREFIX = 'firmale_return_token_';
    const NONCE_ACTION = 'firmale_return_form';

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
        add_action('init', array($this, 'register_post_type'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_shortcode('firmale_tagastusvorm', array($this, 'render_shortcode'));

        add_action('wp_ajax_firmale_verify_order', array($this, 'ajax_verify_order'));
        add_action('wp_ajax_nopriv_firmale_verify_order', array($this, 'ajax_verify_order'));
        add_action('wp_ajax_firmale_confirm_return_otp', array($this, 'ajax_confirm_otp'));
        add_action('wp_ajax_nopriv_firmale_confirm_return_otp', array($this, 'ajax_confirm_otp'));
        add_action('wp_ajax_firmale_submit_return', array($this, 'ajax_submit_return'));
        add_action('wp_ajax_nopriv_firmale_submit_return', array($this, 'ajax_submit_return'));
    }

    public function register_post_type()
    {
        register_post_type(self::CPT, array(
            'labels' => array(
                'name'          => 'Tagastusavaldused',
                'singular_name' => 'Tagastusavaldus',
                'menu_name'     => 'Tagastusavaldused',
                'add_new_item'  => 'Lisa tagastusavaldus',
                'edit_item'     => 'Vaata tagastusavaldust',
            ),
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'supports'            => array('title'),
            'capability_type'     => 'shop_order',
            'map_meta_cap'        => true,
            'exclude_from_search' => true,
        ));
    }

    public function register_assets()
    {
        wp_register_style(
            'firmale-return-form',
            FIRMALE_RETURN_URL . 'assets/css/return-form.css',
            array(),
            FIRMALE_RETURN_VERSION
        );

        wp_register_script(
            'firmale-return-form',
            FIRMALE_RETURN_URL . 'assets/js/return-form.js',
            array(),
            FIRMALE_RETURN_VERSION,
            true
        );

        wp_register_script(
            'firmale-turnstile',
            'https://challenges.cloudflare.com/turnstile/v0/api.js',
            array(),
            null,
            true
        );
    }

    public function render_shortcode()
    {
        if (!class_exists('WooCommerce')) {
            return '<p class="firmale-return-unavailable">Tagastusvorm ei ole hetkel saadaval.</p>';
        }

        /* Avalik vorm sisaldab ajaliselt piiratud turvatokenit. Palu lehevahemälu
         * pluginatel seda lehte mitte salvestada. */
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!headers_sent()) {
            nocache_headers();
        }

        wp_enqueue_style('firmale-return-form');
        wp_enqueue_script('firmale-return-form');

        $features = Firmale_Return_Features::instance();
        if ($features->turnstile_enabled()) {
            wp_enqueue_script('firmale-turnstile');
        }

        wp_localize_script('firmale-return-form', 'FirmaleReturnForm', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'i18n'    => array(
                'networkError' => 'Ühendus ebaõnnestus. Palun proovi mõne hetke pärast uuesti.',
                'serverError'  => 'Serveris tekkis tellimuse kontrollimisel viga. Palun teavita klienditeenindust.',
                'selectProduct' => 'Vali vähemalt üks tagastatav toode.',
                'working'       => 'Kontrollin…',
                'sending'       => 'Saadan…',
                'confirming'    => 'Kinnitan…',
                'otpError'      => 'Sisesta e-postile saadetud 6-kohaline kood.',
            ),
        ));

        $show_estimate = (bool) get_option('firmale_return_show_estimate', true);
        $use_site_styles = (bool) get_option('firmale_return_use_site_styles', false);
        $turnstile_enabled = $features->turnstile_enabled();
        $turnstile_site_key = trim((string) get_option('firmale_return_turnstile_site_key', ''));
        $defect_flow = (bool) get_option('firmale_return_enable_defect_flow', true);
        $attachments_enabled = (bool) get_option('firmale_return_enable_attachments', true);
        $methods_enabled = (bool) get_option('firmale_return_enable_return_methods', true)
            && (
                (bool) get_option('firmale_return_method_locker', true)
                || (bool) get_option('firmale_return_method_courier', true)
                || (bool) get_option('firmale_return_method_store', true)
            );

        ob_start();
        ?>
        <section class="firmale-return<?php echo $use_site_styles ? ' firmale-return--site-theme' : ''; ?>"
                 data-firmale-return
                 data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                 data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>">
            <header class="firmale-return__header">
                <p class="firmale-return__eyebrow">TAGASTUS</p>
                <h1>Taganemisavaldus</h1>
                <p>Sisesta tellimuse andmed. Kontrollime automaatselt, kas sinu tellimuse taganemistähtaeg kehtib.</p>
            </header>

            <div class="firmale-return__layout">
                <main class="firmale-return__card">
                    <ol class="firmale-return__steps" aria-label="Tagastusavalduse sammud">
                        <li class="is-active" data-step="1"><span>1</span><strong>Tellimus</strong></li>
                        <li data-step="2"><span>2</span><strong>Tooted</strong></li>
                        <li data-step="3"><span>3</span><strong>Tagastus</strong></li>
                    </ol>

                    <form class="firmale-return__verify" data-verify-form novalidate>
                        <div class="firmale-return__field-grid">
                            <label>
                                <span>Tellimuse number</span>
                                <input type="text" name="order_number" placeholder="#12345" autocomplete="off" required>
                            </label>
                            <label>
                                <span>Tellimuses kasutatud e-post</span>
                                <input type="email" name="billing_email" placeholder="nimi@epost.ee" autocomplete="email" required>
                            </label>
                        </div>
                        <?php if ($turnstile_enabled && $turnstile_site_key) : ?>
                            <div class="firmale-return__turnstile cf-turnstile"
                                 data-sitekey="<?php echo esc_attr($turnstile_site_key); ?>"
                                 data-size="compact"
                                 data-action="firmale-return-verify"></div>
                        <?php elseif ($turnstile_enabled) : ?>
                            <div class="firmale-return__message is-error">Cloudflare Turnstile on sisse lülitatud, kuid saidivõti puudub.</div>
                        <?php endif; ?>
                        <button class="firmale-return__button" type="submit" data-verify-button>
                            Kontrolli tellimust
                        </button>
                    </form>

                    <form class="firmale-return__otp" data-otp-form hidden novalidate>
                        <input type="hidden" name="otp_token" data-otp-token>
                        <label>
                            <span>E-postile saadetud kinnituskood</span>
                            <input type="text" name="otp_code" inputmode="numeric" autocomplete="one-time-code"
                                   pattern="[0-9]{6}" maxlength="6" placeholder="000000" required>
                        </label>
                        <button class="firmale-return__button" type="submit" data-otp-button>Kinnita kood</button>
                    </form>

                    <div class="firmale-return__message" data-message role="status" aria-live="polite" hidden></div>

                    <section class="firmale-return__verified" data-verified hidden>
                        <div class="firmale-return__status" data-status-card>
                            <div class="firmale-return__status-title">
                                <span class="firmale-return__check" aria-hidden="true">✓</span>
                                <strong>Tellimus leitud</strong>
                            </div>
                            <dl>
                                <div><dt>Taganemisõigus</dt><dd data-right-status></dd></div>
                                <div><dt>Kätte saadud</dt><dd data-received-date></dd></div>
                                <div><dt>Avalduse tähtaeg</dt><dd data-deadline></dd></div>
                            </dl>
                        </div>

                        <form class="firmale-return__submit" data-return-form novalidate>
                            <input type="hidden" name="verification_token" data-token>

                            <fieldset class="firmale-return__products">
                                <legend>Vali tagastatavad tooted</legend>
                                <div data-products></div>
                            </fieldset>

                            <?php if ($show_estimate) : ?>
                                <div class="firmale-return__estimate" data-estimate-wrap>
                                    <div>
                                        <span>Hinnanguline tagastussumma</span>
                                        <small>Lõpliku summa kinnitab administraator. Saatmiskulu ei ole hinnangus.</small>
                                    </div>
                                    <strong data-estimate>0,00 €</strong>
                                </div>
                            <?php endif; ?>

                            <?php if ($defect_flow) : ?>
                                <fieldset class="firmale-return__choice-group">
                                    <legend>Avalduse liik</legend>
                                    <label><input type="radio" name="request_type" value="withdrawal" checked> 14-päevane taganemine</label>
                                    <label><input type="radio" name="request_type" value="defect"> Puudusega või vale toode</label>
                                </fieldset>
                                <div class="firmale-return__defect-options" data-defect-options hidden>
                                    <label>
                                        <span>Soovitud lahendus</span>
                                        <select name="resolution">
                                            <option value="repair">Parandamine</option>
                                            <option value="replacement">Asendamine</option>
                                            <?php if ((bool) get_option('firmale_return_enable_exchange', true)) : ?><option value="exchange">Vahetamine teise variandi vastu</option><?php endif; ?>
                                            <option value="refund">Raha tagastamine</option>
                                            <?php if ((bool) get_option('firmale_return_enable_store_credit', false)) : ?><option value="store_credit">Poekrediit</option><?php endif; ?>
                                        </select>
                                    </label>
                                    <?php if ($attachments_enabled) : ?>
                                        <label>
                                            <span>Manused <small>(kuni 3 faili, igaüks kuni <?php echo esc_html(min(5, max(1, absint(get_option('firmale_return_attachment_max_mb', 2))))); ?> MB)</small></span>
                                            <input type="file" name="attachments[]" accept="image/jpeg,image/png,image/webp,application/pdf<?php echo get_option('firmale_return_enable_video_attachments', false) ? ',video/mp4,video/webm' : ''; ?>" multiple>
                                        </label>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                                <label class="firmale-return__return-method" data-withdrawal-options>
                                    <span>Soovitud lahendus</span>
                                    <select name="withdrawal_resolution">
                                        <option value="refund">Raha tagastamine</option>
                                        <?php if ((bool) get_option('firmale_return_enable_exchange', true)) : ?><option value="exchange">Vahetamine</option><?php endif; ?>
                                        <?php if ((bool) get_option('firmale_return_enable_store_credit', false)) : ?><option value="store_credit">Poekrediit</option><?php endif; ?>
                                    </select>
                                </label>
                            <?php if ($methods_enabled) : ?>
                                <label class="firmale-return__return-method">
                                    <span>Soovitud tagastusviis</span>
                                    <select name="return_method" required>
                                        <option value="">Vali tagastusviis</option>
                                        <?php if ((bool) get_option('firmale_return_method_locker', true)) : ?><option value="parcel_locker">Pakiautomaat</option><?php endif; ?>
                                        <?php if ((bool) get_option('firmale_return_method_courier', true)) : ?><option value="courier">Kuller</option><?php endif; ?>
                                        <?php if ((bool) get_option('firmale_return_method_store', true)) : ?><option value="store">Tagastan esindusse</option><?php endif; ?>
                                    </select>
                                </label>
                            <?php endif; ?>

                            <div class="firmale-return__field-grid firmale-return__field-grid--details">
                                <label>
                                    <span>Taganemise põhjus <small>(valikuline)</small></span>
                                    <select name="reason">
                                        <option value="">Vali põhjus</option>
                                        <option value="changed_mind">Muutsin meelt</option>
                                        <option value="not_expected">Toode ei vastanud ootustele</option>
                                        <option value="wrong_size">Vale suurus või mõõt</option>
                                        <option value="defect">Tootel on puudus</option>
                                        <option value="other">Muu põhjus</option>
                                    </select>
                                </label>
                                <label>
                                    <span>Lisainfo <small>(valikuline)</small></span>
                                    <textarea name="details" rows="3" maxlength="2000"></textarea>
                                </label>
                            </div>

                            <label class="firmale-return__confirm">
                                <input type="checkbox" name="confirmation" value="1" required>
                                <span>Kinnitan, et soovin esitada valitud toodete kohta avalduse märgitud lahendusega.</span>
                            </label>

                            <button class="firmale-return__button" type="submit" data-submit-button>
                                Esita avaldus
                            </button>
                        </form>
                    </section>
                </main>

                <aside class="firmale-return__aside">
                    <h2>Kuidas tagastus toimib?</h2>
                    <ol>
                        <li><span>1</span><div><strong>Kontrollime tellimust</strong><p>Sisesta tellimuse number ja tellimuses kasutatud e-post.</p></div></li>
                        <li><span>2</span><div><strong>Vali tagastatavad tooted</strong><p>Näitame tellimusest leitud tooteid ja koguseid.</p></div></li>
                        <li><span>3</span><div><strong>Saadame juhised e-postile</strong><p>Pärast avalduse esitamist saad edasised tagastusjuhised.</p></div></li>
                    </ol>
                    <div class="firmale-return__notice">
                        <strong>Puudusega kaup?</strong>
                        <p>Pretensiooni esitamise õigus ei piirdu 14 päevaga.</p>
                    </div>
                    <p class="firmale-return__privacy">🔒 Sinu andmeid kasutatakse ainult tagastuse menetlemiseks.</p>
                </aside>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    public function ajax_verify_order()
    {
        $this->verify_ajax_request('verify', 12);

        $turnstile_token = isset($_POST['cf-turnstile-response'])
            ? sanitize_text_field(wp_unslash($_POST['cf-turnstile-response']))
            : '';
        $turnstile = Firmale_Return_Features::instance()->verify_turnstile($turnstile_token);
        if (is_wp_error($turnstile)) {
            wp_send_json_error(array('message' => $turnstile->get_error_message()), 403);
        }

        $reference = isset($_POST['order_number'])
            ? sanitize_text_field(wp_unslash($_POST['order_number']))
            : '';
        $email = isset($_POST['billing_email'])
            ? sanitize_email(wp_unslash($_POST['billing_email']))
            : '';

        if ($reference === '' || !is_email($email)) {
            wp_send_json_error(array(
                'message' => 'Sisesta korrektne tellimuse number ja e-posti aadress.',
            ), 400);
        }

        $order = $this->find_order($reference);

        if (!$order || strcasecmp((string) $order->get_billing_email(), $email) !== 0) {
            wp_send_json_error(array(
                'message' => 'Tellimuse andmeid ei õnnestunud kinnitada. Kontrolli numbrit ja e-posti aadressi.',
            ), 404);
        }

        if (in_array($order->get_status(), array('cancelled', 'failed', 'trash'), true)) {
            wp_send_json_error(array(
                'message' => 'Selle tellimuse kohta ei saa taganemisavaldust esitada.',
            ), 400);
        }

        $date_info = $this->get_date_info($order);
        $block_late = (bool) get_option('firmale_return_block_late', false);

        // Evaluate the deadline after selection: individual products may have an extended policy.

        $products = $this->order_products($order);
        if (!$products) {
            wp_send_json_error(array('message' => 'Tellimusest ei leitud tagastatavaid tooteid.'), 400);
        }

        $verification_data = array(
            'order_id'    => $order->get_id(),
            'email_hash'  => hash('sha256', strtolower($email)),
            'date_info'   => $date_info,
            'products'    => $products,
            'created_at'  => time(),
        );

        if (Firmale_Return_Features::instance()->otp_enabled()) {
            $otp = Firmale_Return_Features::instance()->create_otp($order, $verification_data);
            if (is_wp_error($otp)) {
                wp_send_json_error(array('message' => $otp->get_error_message()), 500);
            }
            wp_send_json_success(array(
                'requiresOtp'   => true,
                'otpToken'      => $otp['token'],
                'maskedEmail'   => $otp['masked_email'],
                'message'       => sprintf('Saatsime kinnituskoodi aadressile %s.', $otp['masked_email']),
            ));
        }

        $this->send_verified_order_response($verification_data);
    }

    public function ajax_confirm_otp()
    {
        $this->verify_ajax_request('otp', 10);
        $token = isset($_POST['otp_token']) ? sanitize_text_field(wp_unslash($_POST['otp_token'])) : '';
        $code = isset($_POST['otp_code']) ? preg_replace('/\D+/', '', wp_unslash($_POST['otp_code'])) : '';
        if (!$token || strlen($code) !== 6) {
            wp_send_json_error(array('message' => 'Sisesta korrektne 6-kohaline kinnituskood.'), 400);
        }
        $verification_data = Firmale_Return_Features::instance()->confirm_otp($token, $code);
        if (is_wp_error($verification_data)) {
            wp_send_json_error(array('message' => $verification_data->get_error_message()), 403);
        }
        $this->send_verified_order_response($verification_data);
    }

    private function send_verified_order_response($verification_data)
    {
        $order = wc_get_order((int) $verification_data['order_id']);
        if (!$order || empty($verification_data['products']) || empty($verification_data['date_info'])) {
            wp_send_json_error(array('message' => 'Tellimuse kontrollandmed on puudulikud.'), 400);
        }
        if (!$this->verified_order_is_current($order, $verification_data)) {
            wp_send_json_error(array('message' => 'Tellimuse andmed muutusid. Palun kontrolli tellimus uuesti.'), 403);
        }
        $verification_data['products'] = $this->order_products($order);
        $verification_data['date_info'] = $this->get_date_info($order);
        $token = wp_generate_password(48, false, false);
        $token_data = array(
            'order_id'   => $order->get_id(),
            'email_hash' => $verification_data['email_hash'],
            'date_info'  => $verification_data['date_info'],
            'products'   => wp_list_pluck($verification_data['products'], 'quantity', 'item_id'),
            'created_at' => time(),
        );
        set_transient(self::TOKEN_PREFIX . hash('sha256', $token), $token_data, 30 * MINUTE_IN_SECONDS);
        $date_info = $verification_data['date_info'];
        wp_send_json_success(array(
            'requiresOtp'  => false,
            'token'        => $token,
            'products'     => $verification_data['products'],
            'state'        => $date_info['state'],
            'statusText'   => $date_info['status_text'],
            'receivedDate' => $date_info['received_text'],
            'deadline'     => $date_info['deadline_text'],
            'manualReview' => $date_info['state'] !== 'valid',
            'currency'     => $order->get_currency(),
        ));
    }

    public function ajax_submit_return()
    {
        $this->verify_ajax_request('submit', 8);

        $token = isset($_POST['verification_token'])
            ? sanitize_text_field(wp_unslash($_POST['verification_token']))
            : '';
        $confirmation = !empty($_POST['confirmation']);
        $items_raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
        $items = is_string($items_raw) && strlen($items_raw) <= 20000 ? json_decode($items_raw, true) : null;

        if (!preg_match('/^[a-zA-Z0-9]{48}$/D', $token) || !$confirmation || !is_array($items)) {
            wp_send_json_error(array('message' => 'Avalduse andmed on puudulikud.'), 400);
        }

        $transient_key = self::TOKEN_PREFIX . hash('sha256', $token);
        $submission_lock = Firmale_Return_Lock::acquire('submit-' . hash('sha256', $token));
        if (!$submission_lock) {
            wp_send_json_error(array('message' => 'Avalduse salvestamine juba käib. Palun oota.'), 409);
        }
        $existing_requests = get_posts(array('post_type' => self::CPT, 'post_status' => 'any', 'fields' => 'ids',
            'posts_per_page' => 1, 'meta_key' => '_firmale_submission_hash', 'meta_value' => hash('sha256', $token)));
        if ($existing_requests) {
            wp_send_json_success(array('requestId' => (int) $existing_requests[0], 'message' => 'Avaldus on juba vastu võetud.'));
        }
        $token_data = get_transient($transient_key);

        if (!is_array($token_data) || empty($token_data['order_id'])) {
            wp_send_json_error(array(
                'message' => 'Tellimuse kontroll on aegunud. Palun kontrolli tellimus uuesti.',
            ), 403);
        }

        $order = wc_get_order((int) $token_data['order_id']);
        if (!$order) {
            wp_send_json_error(array('message' => 'Tellimust ei õnnestunud avada.'), 404);
        }
        if (!$this->verified_order_is_current($order, $token_data)) {
            wp_send_json_error(array('message' => 'Tellimuse andmed muutusid. Palun kontrolli tellimus uuesti.'), 403);
        }

        $selected = $this->validate_selected_items($items, $token_data['products'], $order);
        if (!$selected) {
            wp_send_json_error(array('message' => 'Vali vähemalt üks tagastatav toode.'), 400);
        }
        $token_data['date_info'] = $this->get_date_info($order, $selected);

        $reason = isset($_POST['reason']) ? sanitize_key(wp_unslash($_POST['reason'])) : '';
        $details = isset($_POST['details'])
            ? sanitize_textarea_field(wp_unslash($_POST['details']))
            : '';
        if (strlen($details) > 8000) { wp_send_json_error(array('message' => 'Lisainfo on liiga pikk.'), 400); }

        $allowed_reasons = array_keys($this->reason_labels());
        if (!in_array($reason, $allowed_reasons, true)) {
            $reason = '';
        }

        $request_type = isset($_POST['request_type']) ? sanitize_key(wp_unslash($_POST['request_type'])) : 'withdrawal';
        if (!(bool) get_option('firmale_return_enable_defect_flow', true) || $request_type !== 'defect') {
            $request_type = 'withdrawal';
        }
        if ($request_type === 'defect' && trim($details) === '') {
            wp_send_json_error(array('message' => 'Kirjelda palun toote puudust või probleemi.'), 400);
        }
        if ($request_type === 'withdrawal' && (bool) get_option('firmale_return_block_late', false) && $token_data['date_info']['state'] === 'late' && $token_data['date_info']['source'] === 'delivered') {
            wp_send_json_error(array('message' => 'Automaatse arvestuse järgi on taganemistähtaeg möödunud. Puudusega kauba korral vali avalduse liigiks „Puudusega või vale toode”.'), 400);
        }
        $resolution_field = $request_type === 'defect' ? 'resolution' : 'withdrawal_resolution';
        $resolution = isset($_POST[$resolution_field]) ? sanitize_key(wp_unslash($_POST[$resolution_field])) : 'refund';
        $allowed_resolutions = $request_type === 'defect' ? array('refund', 'repair', 'replacement') : array('refund');
        if ((bool) get_option('firmale_return_enable_exchange', true)) {
            $allowed_resolutions[] = 'exchange';
        }
        if ((bool) get_option('firmale_return_enable_store_credit', false)) {
            $allowed_resolutions[] = 'store_credit';
        }
        if (!in_array($resolution, $allowed_resolutions, true)) {
            $resolution = 'refund';
        }
        $return_method = isset($_POST['return_method']) ? sanitize_key(wp_unslash($_POST['return_method'])) : '';
        $allowed_methods = array();
        if ((bool) get_option('firmale_return_method_locker', true)) {
            $allowed_methods[] = 'parcel_locker';
        }
        if ((bool) get_option('firmale_return_method_courier', true)) {
            $allowed_methods[] = 'courier';
        }
        if ((bool) get_option('firmale_return_method_store', true)) {
            $allowed_methods[] = 'store';
        }
        if ((bool) get_option('firmale_return_enable_return_methods', true) && $allowed_methods && !in_array($return_method, $allowed_methods, true)) {
            wp_send_json_error(array('message' => 'Vali korrektne tagastusviis.'), 400);
        }
        if (!(bool) get_option('firmale_return_enable_return_methods', true) || !$allowed_methods) { $return_method = ''; }
        if ($return_method === 'parcel_locker') {
            $weight = 0;
            foreach ($selected as $selected_item) { $weight += $selected_item['weight'] * $selected_item['quantity']; }
            $max_weight = max(0, (float) get_option('firmale_return_locker_max_weight', 30));
            if ($max_weight > 0 && $weight > $max_weight) {
                wp_send_json_error(array('message' => 'Valitud toodete kaal ületab pakiautomaadi piirangu. Vali muu tagastusviis või pöördu klienditeenindusse.'), 400);
            }
        }

        $request_id = wp_insert_post(array(
            'post_type'   => self::CPT,
            'post_status' => 'publish',
            'post_title'  => sprintf(
                'Tagastus #%s – %s',
                $order->get_order_number(),
                current_time('d.m.Y H:i')
            ),
        ), true);

        if (is_wp_error($request_id)) {
            wp_send_json_error(array(
                'message' => 'Avalduse salvestamine ebaõnnestus. Palun proovi uuesti.',
            ), 500);
        }

        $product_manual_review = false;
        foreach ($selected as $selected_item) {
            if (!empty($selected_item['manual_review'])) {
                $product_manual_review = true;
                break;
            }
        }
        $review_state = $token_data['date_info']['state'] === 'valid' && $request_type === 'withdrawal' && !$product_manual_review
            ? 'new'
            : 'manual_review';
        update_post_meta($request_id, '_firmale_order_id', $order->get_id());
        update_post_meta($request_id, '_firmale_customer_email', $order->get_billing_email());
        update_post_meta($request_id, '_firmale_customer_name', $order->get_formatted_billing_full_name());
        update_post_meta($request_id, '_firmale_items', $selected);
        update_post_meta($request_id, '_firmale_reason', $reason);
        update_post_meta($request_id, '_firmale_details', $details);
        update_post_meta($request_id, '_firmale_date_info', $token_data['date_info']);
        update_post_meta($request_id, '_firmale_status', $review_state);
        update_post_meta($request_id, '_firmale_request_type', $request_type);
        update_post_meta($request_id, '_firmale_resolution', $resolution);
        update_post_meta($request_id, '_firmale_return_method', $return_method);
        update_post_meta($request_id, '_firmale_submitted_at', current_time('mysql'));

        if (!empty($_FILES['attachments'])) {
            $attachments = Firmale_Return_Features::instance()->store_attachments($request_id, $_FILES['attachments']);
            if (is_wp_error($attachments)) {
                wp_delete_post($request_id, true);
                wp_send_json_error(array('message' => $attachments->get_error_message()), 400);
            }
        }

        $tracking_token = Firmale_Return_Features::instance()->create_tracking_token($request_id);
        $tracking_url = Firmale_Return_Features::instance()->tracking_url($tracking_token);
        if ($tracking_url) {
            update_post_meta($request_id, '_firmale_tracking_url_customer', $tracking_url);
        }
        update_post_meta($request_id, '_firmale_submission_hash', hash('sha256', $token));
        Firmale_Return_Features::instance()->audit(
            $request_id,
            'request_created',
            'Klient esitas tagastusavalduse.',
            array('type' => $request_type, 'method' => $return_method, 'status' => $review_state)
        );

        $order->add_order_note($this->order_note($request_id, $selected, $token_data['date_info']));
        delete_transient($transient_key);

        $email_sent = $this->send_notifications($request_id, $order, $selected, $reason, $details, $token_data['date_info']);

        wp_send_json_success(array(
            'message' => !$email_sent ? 'Avaldus on salvestatud, kuid kinnituskirja saatmine ebaõnnestus. Palun märgi üles avalduse number ' . $request_id . ' ja võta ühendust klienditeenindusega.' : ($review_state === 'new'
                ? 'Taganemisavaldus on vastu võetud. Saatsime kinnituse sinu e-postile.'
                : 'Avaldus on vastu võetud ja saadetud käsitsi kontrollimiseks.'),
            'requestId' => $request_id,
            'trackingUrl' => $tracking_url,
        ));
    }

    private function verified_order_is_current($order, $data)
    {
        return $order && !in_array($order->get_status(), array('cancelled', 'failed', 'trash'), true)
            && !empty($data['email_hash'])
            && hash_equals((string) $data['email_hash'], hash('sha256', strtolower(trim($order->get_billing_email()))));
    }

    private function verify_ajax_request($bucket, $limit)
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!class_exists('WooCommerce')) {
            wp_send_json_error(array('message' => 'WooCommerce ei ole saadaval.'), 503);
        }

        if (!$this->rate_limit($bucket, $limit, 15 * MINUTE_IN_SECONDS)) {
            wp_send_json_error(array(
                'message' => 'Liiga palju päringuid. Palun oota mõni minut ja proovi uuesti.',
            ), 429);
        }
    }

    private function rate_limit($bucket, $limit, $window)
    {
        $ip = 'unknown';
        if ((bool) get_option('firmale_return_trust_cloudflare_ip', false) && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidate = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
            }
        }
        if ($ip === 'unknown' && !empty($_SERVER['REMOTE_ADDR'])) {
            $candidate = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
            $ip = filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : 'unknown';
        }
        $key = 'firmale_return_rate_' . hash_hmac('sha256', $bucket . '|' . $ip, wp_salt('auth'));
        $rate_lock = Firmale_Return_Lock::acquire($key);
        if (!$rate_lock) { return false; }
        $count = (int) get_transient($key);

        if ($count >= $limit) {
            $rate_lock->release();
            return false;
        }

        set_transient($key, $count + 1, $window);
        $rate_lock->release();
        return true;
    }

    private function find_order($reference)
    {
        $reference = ltrim(trim((string) $reference), '#');

        if (ctype_digit($reference)) {
            $order = wc_get_order((int) $reference);
            if ($order && ltrim((string) $order->get_order_number(), '#') === $reference) {
                return $order;
            }
        }

        foreach (array('_order_number', '_order_number_formatted') as $meta_key) {
            try {
                $orders = wc_get_orders(array(
                    'limit'      => 1,
                    'return'     => 'objects',
                    'meta_query' => array(array(
                        'key'     => $meta_key,
                        'value'   => $reference,
                        'compare' => '=',
                    )),
                ));
                if (!empty($orders[0])) {
                    return $orders[0];
                }
            } catch (Throwable $e) {
                // Kohandatud numbreid saab toetada alloleva filtri kaudu.
            }
        }

        $custom = apply_filters('firmale_return_find_order', null, $reference);
        return $custom instanceof WC_Order ? $custom : null;
    }

    private function get_date_info($order, $selected = array())
    {
        $meta_value = (bool) get_option('firmale_return_enable_shipment_dates', true)
            ? (string) get_option('firmale_return_shipment_meta_keys', (string) get_option('firmale_return_delivered_meta_key', '_delivered_at'))
            : (string) get_option('firmale_return_delivered_meta_key', '_delivered_at');
        $meta_keys = array_filter(array_unique(array_map('trim', explode(',', $meta_value))));
        if (!$meta_keys) {
            $meta_keys = array((string) get_option('firmale_return_delivered_meta_key', '_delivered_at'));
        }
        $received_dates = array();
        foreach ($meta_keys as $meta_key) {
            $this->collect_dates($order->get_meta(sanitize_key($meta_key), true), $received_dates);
        }
        usort($received_dates, function ($first, $second) {
            return $first->getTimestamp() <=> $second->getTimestamp();
        });
        $received = $received_dates ? end($received_dates) : null;
        $source = 'delivered';

        if (!$received && (bool) get_option('firmale_return_completed_fallback', true)) {
            $completed = $order->get_date_completed();
            if ($completed) {
                $received = new DateTimeImmutable($completed->date('Y-m-d H:i:s'), wp_timezone());
                $source = 'completed';
            }
        }

        if (!$received) {
            return array(
                'state'         => 'unknown',
                'source'        => 'none',
                'received'      => '',
                'deadline'      => '',
                'received_text' => 'Vajab kontrolli',
                'deadline_text' => 'Vajab kontrolli',
                'status_text'   => 'Tähtaeg vajab käsitsi kontrolli',
            );
        }

        $period_days = $this->return_period_days($order);
        if ($selected) {
            $item_periods = array();
            foreach ($selected as $selected_item) {
                $item = $order->get_item($selected_item['item_id']);
                $product = $item ? $item->get_product() : null;
                $policy = $product ? Firmale_Return_Features::instance()->product_policy($product->get_id()) : array();
                $item_periods[] = max($period_days, (int) ($policy['period_days'] ?? 0));
            }
            $period_days = min(365, min($item_periods));
        }
        $deadline = $this->deadline($received, $period_days);
        $now = new DateTimeImmutable('now', wp_timezone());
        $late = $now > $deadline;
        $remaining = $late ? 0 : (int) $now->setTime(0, 0)->diff($deadline->setTime(0, 0))->days;

        return array(
            'state'         => $source === 'completed' ? 'unknown' : ($late ? 'late' : 'valid'),
            'source'        => $source,
            'received'      => $received->format(DATE_ATOM),
            'deadline'      => $deadline->format(DATE_ATOM),
            'received_text' => wp_date('d.m.Y', $received->getTimestamp(), wp_timezone()),
            'deadline_text' => wp_date('d.m.Y', $deadline->getTimestamp(), wp_timezone()),
            'period_days'   => $period_days,
            'status_text'   => $source === 'completed' ? 'Hinnang „Täidetud” kuupäevast; tegelik kättesaamine vajab kontrolli' : ($late
                ? sprintf('Automaatse arvestuse järgi on %d-päevane tähtaeg möödunud', $period_days)
                : ($remaining === 0 ? 'Taganemisõigus kehtib täna' : sprintf('Taganemisõigus kehtib veel %d päeva', $remaining))),
        );
    }

    private function collect_dates($value, &$dates)
    {
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                if (is_int($key) || in_array($key, array('delivered_at', 'received_at'), true)) {
                    $this->collect_dates($nested, $dates);
                }
            }
            return;
        }
        $parsed = $this->parse_date($value);
        if ($parsed && $parsed->getTimestamp() >= 946684800 && $parsed->getTimestamp() <= time()) {
            $dates[] = $parsed;
        }
    }

    private function return_period_days($order)
    {
        $days = min(365, max(14, absint(get_option('firmale_return_default_period_days', 14))));
        if ((bool) get_option('firmale_return_enable_campaign_period', false)) {
            $start = (string) get_option('firmale_return_campaign_start', '');
            $end = (string) get_option('firmale_return_campaign_end', '');
            $created = $order->get_date_created();
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $end) && $created) {
                $bought = wp_date('Y-m-d', $created->getTimestamp(), wp_timezone());
                if ($start <= $bought && $bought <= $end) {
                    $days = max($days, min(365, max(14, absint(get_option('firmale_return_campaign_period_days', 30)))));
                }
            }
        }
        if ((bool) get_option('firmale_return_enable_extended_period', false)) {
            $roles = array_filter(array_map('sanitize_key', array_map('trim', explode(',', (string) get_option('firmale_return_extended_roles', '')))));
            $user = $order->get_user();
            if ($user && array_intersect($roles, (array) $user->roles)) {
                $days = max($days, min(365, max(14, absint(get_option('firmale_return_extended_period_days', 30)))));
            }
        }
        return min(365, max(14, (int) apply_filters('firmale_return_period_days', $days, $order)));
    }

    private function parse_date($value)
    {
        if ($value instanceof DateTimeInterface) {
            return new DateTimeImmutable($value->format('Y-m-d H:i:s'), wp_timezone());
        }
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }
        try {
            if (ctype_digit((string) $value)) {
                return (new DateTimeImmutable('@' . (string) $value))->setTimezone(wp_timezone());
            }
            return new DateTimeImmutable((string) $value, wp_timezone());
        } catch (Exception $e) {
            return null;
        }
    }

    private function deadline(DateTimeImmutable $received, $period_days = 14)
    {
        $deadline = $received->setTime(23, 59, 59)->modify('+' . max(1, absint($period_days)) . ' days');
        while ($this->is_non_working_day($deadline)) {
            $deadline = $deadline->modify('+1 day');
        }
        return $deadline;
    }

    private function is_non_working_day(DateTimeImmutable $date)
    {
        if ((int) $date->format('N') >= 6) {
            return true;
        }

        $year = (int) $date->format('Y');
        $fixed = array('01-01', '02-24', '05-01', '06-23', '06-24', '08-20', '12-24', '12-25', '12-26');
        if (in_array($date->format('m-d'), $fixed, true)) {
            return true;
        }

        $easter = $this->easter_sunday($year);
        $movable = array(
            $easter->modify('-2 days')->format('Y-m-d'),
            $easter->format('Y-m-d'),
            $easter->modify('+49 days')->format('Y-m-d'),
        );

        return in_array($date->format('Y-m-d'), $movable, true);
    }

    private function easter_sunday($year)
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 12:00:00', $year, $month, $day), wp_timezone());
    }

    private function order_products($order)
    {
        $products = array();
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $refunded_quantity = abs((int) $order->get_qty_refunded_for_item($item_id));
            $quantity = max(0, (int) $item->get_quantity() - $refunded_quantity);
            if ($quantity < 1) {
                continue;
            }
            $product = $item->get_product();
            $policy = $product
                ? Firmale_Return_Features::instance()->product_policy($product->get_id())
                : array('manual_review' => false, 'period_days' => 0);
            $image = $product ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : '';
            $unit_total = $quantity > 0
                ? (((float) $item->get_total() + (float) $item->get_total_tax()) / max(1, (int) $item->get_quantity()))
                : 0;
            $price = wp_strip_all_tags(html_entity_decode(wc_price($unit_total, array(
                'currency' => $order->get_currency(),
            )), ENT_QUOTES, 'UTF-8'));

            $products[] = array(
                'item_id'  => (int) $item_id,
                'name'     => $item->get_name(),
                'quantity' => $quantity,
                'price'    => $price,
                'unit_amount' => (float) wc_format_decimal($unit_total, wc_get_price_decimals()),
                'image'    => $image ? esc_url_raw($image) : '',
                'manual_review' => !empty($policy['manual_review']),
                'policy_notice' => !empty($policy['manual_review']) ? 'Selle toote tagastus vaadatakse käsitsi üle.' : '',
            );
        }
        return $products;
    }

    private function validate_selected_items($items, $allowed, $order)
    {
        $selected = array();
        $seen = array();
        if (count($items) > 200) { return array(); }
        foreach ($items as $submitted) {
            if (!is_array($submitted) || !isset($submitted['item_id'], $submitted['quantity'])
                || !is_scalar($submitted['item_id']) || !is_scalar($submitted['quantity'])
                || !preg_match('/^[1-9][0-9]*$/D', (string) $submitted['item_id'])
                || !preg_match('/^[1-9][0-9]*$/D', (string) $submitted['quantity'])) { return array(); }
            $item_id = isset($submitted['item_id']) ? absint($submitted['item_id']) : 0;
            $quantity = isset($submitted['quantity']) ? absint($submitted['quantity']) : 0;
            if (!$item_id || !$quantity || !isset($allowed[$item_id]) || $quantity > (int) $allowed[$item_id]) {
                return array();
            }
            $item = $order->get_item($item_id);
            if (!$item || !$item->is_type('line_item') || isset($seen[$item_id])
                || $quantity > max(0, (int) $item->get_quantity() - abs((int) $order->get_qty_refunded_for_item($item_id)))) {
                return array();
            }
            $seen[$item_id] = true;
            $product = $item->get_product();
            $policy = $product
                ? Firmale_Return_Features::instance()->product_policy($product->get_id())
                : array('manual_review' => false);
            $selected[] = array(
                'item_id'  => $item_id,
                'name'     => $item->get_name(),
                'quantity' => $quantity,
                'manual_review' => !empty($policy['manual_review']),
                'weight'    => $product ? (float) wc_get_weight((float) $product->get_weight(), 'kg') : 0,
            );
        }
        return $selected;
    }

    public function get_refund_preview($request_id, $include_shipping = false)
    {
        $request_id = absint($request_id);
        $order_id = absint(get_post_meta($request_id, '_firmale_order_id', true));
        $selected = get_post_meta($request_id, '_firmale_items', true);
        $order = $order_id ? wc_get_order($order_id) : null;

        if (!$order || !is_array($selected)) {
            return new WP_Error('firmale_invalid_refund', 'Tellimuse või avalduse andmed puuduvad.');
        }

        $line_items = array();
        $amount = 0.0;
        $decimals = wc_get_price_decimals();
        $refunded_lines = $this->refunded_amounts_by_original_item($order, 'line_item');
        $seen = array();

        foreach ($selected as $selected_item) {
            $item_id = isset($selected_item['item_id']) ? absint($selected_item['item_id']) : 0;
            if (isset($seen[$item_id])) {
                return new WP_Error('firmale_duplicate_item', 'Avalduses on korduv tellimuserida. Vajalik on käsitsi kontroll.');
            }
            $seen[$item_id] = true;
            $requested_qty = isset($selected_item['quantity']) ? absint($selected_item['quantity']) : 0;
            $item = $item_id ? $order->get_item($item_id) : null;
            if (!$item || !$requested_qty || !$item->is_type('line_item')) {
                continue;
            }

            $ordered_qty = max(1, (int) $item->get_quantity());
            $already_refunded = abs((int) $order->get_qty_refunded_for_item($item_id));
            $available_qty = max(0, $ordered_qty - $already_refunded);
            $qty = $requested_qty;
            if ($qty < 1 || $qty > $available_qty) {
                return new WP_Error('firmale_changed_quantity', 'Tellimuse tagastatav kogus on muutunud. Kontrolli varasemaid tagastusi.');
            }

            $ratio = $qty / $ordered_qty;
            $already = isset($refunded_lines[$item_id]) ? $refunded_lines[$item_id] : array('total' => 0.0, 'taxes' => array());
            $remaining_net = max(0, round((float) $item->get_total() - $already['total'], $decimals));
            $refund_total = $qty === $available_qty ? $remaining_net : min($remaining_net, round((float) $item->get_total() * $ratio, $decimals));
            $refund_tax = array();
            $taxes = $item->get_taxes();
            foreach ((array) $taxes['total'] as $tax_rate_id => $tax_total) {
                $remaining_tax = max(0, round((float) $tax_total - (float) ($already['taxes'][$tax_rate_id] ?? 0), $decimals));
                $refund_tax[$tax_rate_id] = $qty === $available_qty ? $remaining_tax : min($remaining_tax, round((float) $tax_total * $ratio, $decimals));
            }

            $line_items[$item_id] = array(
                'qty'          => $qty,
                'refund_total' => wc_format_decimal($refund_total),
                'refund_tax'   => array_map('wc_format_decimal', $refund_tax),
            );
            $amount += $refund_total + array_sum($refund_tax);
        }

        $shipping_mode = sanitize_key((string) get_option('firmale_return_shipping_refund_mode', 'manual'));
        $shipping_allowed = (bool) get_option('firmale_return_allow_shipping_refund', true) && $shipping_mode !== 'none';
        if ($shipping_mode === 'full_order' && !$this->selected_covers_all_remaining_items($order, $selected)) {
            $shipping_allowed = false;
        }
        if ($include_shipping && $shipping_allowed) {
            $refunded_by_item = $this->refunded_amounts_by_original_item($order, 'shipping');
            $shipping_cap = max(0, (float) wc_format_decimal(get_option('firmale_return_standard_shipping_cap', 0)));
            $shipping_added = 0.0;
            foreach ($refunded_by_item as $prior_shipping) {
                $shipping_added += $prior_shipping['total'] + array_sum($prior_shipping['taxes']);
            }
            foreach ($order->get_items('shipping') as $shipping_id => $shipping_item) {
                $already = isset($refunded_by_item[$shipping_id])
                    ? $refunded_by_item[$shipping_id]
                    : array('total' => 0.0, 'taxes' => array());
                $refund_total = max(0, round((float) $shipping_item->get_total() - (float) $already['total'], $decimals));
                $refund_tax = array();
                $taxes = $shipping_item->get_taxes();
                foreach ((array) $taxes['total'] as $tax_rate_id => $tax_total) {
                    $already_tax = isset($already['taxes'][$tax_rate_id]) ? (float) $already['taxes'][$tax_rate_id] : 0.0;
                    $refund_tax[$tax_rate_id] = max(0, round((float) $tax_total - $already_tax, $decimals));
                }

                $shipping_gross = $refund_total + array_sum($refund_tax);
                if ($shipping_cap > 0 && $shipping_gross > 0) {
                    $remaining_cap = max(0, $shipping_cap - $shipping_added);
                    if ($remaining_cap <= 0) {
                        continue;
                    }
                    if ($shipping_gross > $remaining_cap) {
                        $ratio = $remaining_cap / $shipping_gross;
                        $refund_total = round($refund_total * $ratio, $decimals);
                        foreach ($refund_tax as $tax_rate_id => $tax_amount) {
                            $refund_tax[$tax_rate_id] = round($tax_amount * $ratio, $decimals);
                        }
                        $shipping_gross = $refund_total + array_sum($refund_tax);
                        // Keep the gross cap exact after independently rounding tax components.
                        $overflow = max(0, round($shipping_gross - $remaining_cap, $decimals));
                        $deduct = min($refund_total, $overflow);
                        $refund_total -= $deduct;
                        $overflow -= $deduct;
                        foreach ($refund_tax as $tax_rate_id => $tax_amount) {
                            $deduct = min($tax_amount, $overflow);
                            $refund_tax[$tax_rate_id] -= $deduct;
                            $overflow -= $deduct;
                        }
                        $shipping_gross = $refund_total + array_sum($refund_tax);
                    }
                }

                if ($refund_total > 0 || array_sum($refund_tax) > 0) {
                    $line_items[$shipping_id] = array(
                        'qty'          => 0,
                        'refund_total' => wc_format_decimal($refund_total),
                        'refund_tax'   => array_map('wc_format_decimal', $refund_tax),
                    );
                    $amount += $refund_total + array_sum($refund_tax);
                    $shipping_added += $shipping_gross;
                }
            }
        }

        $remaining = max(0, round((float) $order->get_total() - (float) $order->get_total_refunded(), $decimals));
        $amount = round($amount, $decimals);

        if ($amount <= 0 || !$line_items) {
            return new WP_Error('firmale_nothing_to_refund', 'Valitud toodete eest ei ole enam midagi tagastada.');
        }
        if ($amount > $remaining) {
            return new WP_Error(
                'firmale_refund_amount_mismatch',
                'Valitud toodete summa ületab tellimuse allesjäänud tagastatavat summat. Kontrolli tellimuse varasemaid rahatagastusi.'
            );
        }

        return array(
            'order'       => $order,
            'amount'      => $amount,
            'amount_html' => wc_price($amount, array('currency' => $order->get_currency())),
            'line_items'  => $line_items,
        );
    }

    private function selected_covers_all_remaining_items($order, $selected)
    {
        $selected_quantities = array();
        foreach ((array) $selected as $item) {
            if (!empty($item['item_id'])) {
                $selected_quantities[absint($item['item_id'])] = absint($item['quantity']);
            }
        }
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $remaining = max(0, (int) $item->get_quantity() - abs((int) $order->get_qty_refunded_for_item($item_id)));
            if ($remaining > 0 && (!isset($selected_quantities[$item_id]) || $selected_quantities[$item_id] < $remaining)) {
                return false;
            }
        }
        return true;
    }

    public function can_automatically_refund($order)
    {
        if (!$order instanceof WC_Order || !(bool) get_option('firmale_return_allow_auto_refund', false)) {
            return false;
        }
        $gateway = wc_get_payment_gateway_by_order($order);
        return $gateway
            && $gateway->supports('refunds')
            && (!is_callable(array($gateway, 'can_refund_order')) || $gateway->can_refund_order($order));
    }

    public function process_request_refund($request_id, $mode, $include_shipping, $restock)
    {
        $request_id = absint($request_id);
        $features = Firmale_Return_Features::instance();
        if (!$features->can_process_refunds() || !(bool) get_option('firmale_return_enable_refunds', true)
            || get_post_type($request_id) !== self::CPT || !in_array($mode, array('manual', 'automatic'), true)) {
            return new WP_Error('firmale_refund_permission', 'Rahatagastus ei ole lubatud.');
        }
        $order_id = absint(get_post_meta($request_id, '_firmale_order_id', true));
        // Never expire financial locks: a timeout may still have moved money at the gateway.
        $lock = Firmale_Return_Lock::acquire('payment-order-' . $order_id, false);
        if (!$lock) {
            return new WP_Error('firmale_refund_locked', 'Selle tellimuse rahaline toiming juba käib või vajab makseteenuses kontrolli. Ära korda makset WooCommerce’is enne kontrollimist.');
        }
        if (get_post_meta($request_id, '_firmale_refund_id', true) || get_post_meta($request_id, '_firmale_store_credit_coupon', true)
            || get_post_meta($request_id, '_firmale_credit_reference', true)) {
            $lock->release();
            return new WP_Error('firmale_already_refunded', 'Selle avalduse hüvitis on juba registreeritud.');
        }
        if ((bool) get_option('firmale_return_require_received', true)) {
            $status = get_post_meta($request_id, '_firmale_status', true);
            if (!in_array($status, array('received', 'inspecting', 'accepted'), true)
                || !get_post_meta($request_id, '_firmale_received_evidence', true)) {
                $lock->release();
                return new WP_Error('firmale_return_not_received', 'Kinnita kauba saabumine või saatmise tõend ning lisa tõendi kirjeldus.');
            }
        }
        $preview = $this->get_refund_preview($request_id, $include_shipping);
        if (is_wp_error($preview)) { $lock->release(); return $preview; }
        $order = $preview['order'];
        if (!$order->is_paid()) {
            $lock->release();
            return new WP_Error('firmale_unpaid_order', 'Tasumata tellimusele ei saa rahatagastust teha.');
        }
        $automatic = $mode === 'automatic';
        if ($automatic && !$this->can_automatically_refund($order)) {
            $lock->release();
            return new WP_Error('firmale_gateway_no_refunds', 'Automaatne rahatagastus on välja lülitatud või makseviis seda ei toeta.');
        }
        $plan = hash('sha256', wp_json_encode(array($request_id, $order_id, $mode, (bool) $restock,
            $order->get_currency(), $preview['amount'], $preview['line_items'], get_post_meta($request_id, '_firmale_received_evidence', true))));
        $threshold = max(0, (float) get_option('firmale_return_dual_approval_threshold', 200));
        if ((bool) get_option('firmale_return_enable_dual_approval', false) && $preview['amount'] >= $threshold) {
            $first_user = absint(get_post_meta($request_id, '_firmale_first_refund_approver', true));
            $approved_plan = (string) get_post_meta($request_id, '_firmale_approved_plan', true);
            $approved_at = (int) get_post_meta($request_id, '_firmale_approved_timestamp', true);
            $first_can_refund = $first_user && user_can($first_user, 'manage_woocommerce')
                && (!(bool) get_option('firmale_return_separate_refund_capability', false) || user_can($first_user, Firmale_Return_Features::REFUND_CAP));
            if (!$first_can_refund || !hash_equals($plan, $approved_plan) || $approved_at < time() - DAY_IN_SECONDS) {
                update_post_meta($request_id, '_firmale_first_refund_approver', get_current_user_id());
                update_post_meta($request_id, '_firmale_first_refund_approved_at', current_time('mysql'));
                update_post_meta($request_id, '_firmale_approved_plan', $plan);
                update_post_meta($request_id, '_firmale_approved_timestamp', time());
                $features->audit($request_id, 'refund_first_approval', 'Summa, read, makseviis ja laoseisu taastamine kinnitati 24 tunniks.', array('plan' => $plan, 'amount' => $preview['amount']));
                $lock->release();
                return array('pending_approval' => true, 'amount_html' => $preview['amount_html'], 'amount' => $preview['amount'], 'automatic' => $automatic);
            }
            if ($first_user === get_current_user_id()) {
                $lock->release();
                return new WP_Error('firmale_second_approver_required', 'Sama rahatagastuse peab kinnitama teine õigusega kasutaja.');
            }
        }
        // Persist intent before calling external payment code. An uncertain result keeps the order locked.
        update_post_meta($request_id, '_firmale_order_lock_owner', $lock->owner());
        update_post_meta($request_id, '_firmale_payment_started_at', time());
        update_post_meta($request_id, '_firmale_payment_state', 'processing');
        update_post_meta($request_id, '_firmale_payment_plan', $plan);
        $features->audit($request_id, 'refund_started', 'Rahatagastuse toiming algas.', array('amount' => $preview['amount'], 'mode' => $mode));
        try {
            $refund = wc_create_refund(array(
                'amount' => $preview['amount'], 'reason' => sprintf('Tagastusavaldus #%d', $request_id),
                'order_id' => $order_id, 'line_items' => $preview['line_items'],
                'refund_payment' => $automatic, 'restock_items' => (bool) $restock,
            ));
            if (is_wp_error($refund) || !$refund instanceof WC_Order_Refund) {
                throw new RuntimeException('Makse või tagastuskande tulemus vajab kontrolli.');
            }
            $refund->update_meta_data('_firmale_return_request_id', $request_id);
            $refund->update_meta_data('_firmale_return_plan', $plan);
            $refund->save();
            update_post_meta($request_id, '_firmale_refund_id', $refund->get_id());
            update_post_meta($request_id, '_firmale_refund_amount', $preview['amount']);
            update_post_meta($request_id, '_firmale_refund_currency', $order->get_currency());
            update_post_meta($request_id, '_firmale_refund_mode', $mode);
            update_post_meta($request_id, '_firmale_refunded_at', current_time('mysql'));
            update_post_meta($request_id, '_firmale_payment_state', $automatic ? 'completed' : 'manual_payment_due');
            update_post_meta($request_id, '_firmale_status', $automatic ? 'completed' : 'manual_payment_due');
            if ($automatic) { update_post_meta($request_id, '_firmale_closed_timestamp', time()); }
            $features->audit($request_id, 'refund_recorded', $automatic ? 'Maksekanal kinnitas rahatagastuse.' : 'Loodi WooCommerce kanne. Raha tuleb kanda eraldi.', array('refund_id' => $refund->get_id(), 'amount' => $preview['amount']));
            $order->add_order_note(sprintf('Tagastusavaldus #%d: %s, %s.', $request_id,
                $automatic ? 'maksekanali tagastus' : 'käsitsi maksmist ootav tagastuskanne', wp_strip_all_tags($preview['amount_html'])));
        } catch (Throwable $exception) {
            update_post_meta($request_id, '_firmale_payment_state', 'uncertain');
            $features->audit($request_id, 'refund_uncertain', 'Tulemus on ebaselge; tellimuse rahaline lukk säilitati.');
            return new WP_Error('firmale_refund_uncertain', 'Tulemus vajab kontrolli WooCommerce’is ja makseteenuses. Tellimus on topelttagastuse vältimiseks lukustatud.');
        }
        $lock->release();
        return array('refund' => $refund, 'amount' => $preview['amount'], 'amount_html' => $preview['amount_html'], 'automatic' => $automatic);
    }

    public function reconcile_refund($request_id, $refund_id, $evidence)
    {
        if (!current_user_can('manage_options') || !Firmale_Return_Features::instance()->can_process_refunds()
            || get_post_type($request_id) !== self::CPT || strlen(trim($evidence)) < 20) {
            return new WP_Error('firmale_reconcile_permission', 'Vajalik on administraatori õigus ja põhjalik kontrolli kirjeldus.');
        }
        $reconcile_lock = Firmale_Return_Lock::acquire('reconcile-' . absint($request_id));
        if (!$reconcile_lock) { return new WP_Error('firmale_reconcile_busy', 'Kontrolli salvestamine juba käib.'); }
        $state = get_post_meta($request_id, '_firmale_payment_state', true);
        if (!in_array($state, array('processing', 'uncertain'), true)
            || (int) get_post_meta($request_id, '_firmale_payment_started_at', true) > time() - HOUR_IN_SECONDS) {
            return new WP_Error('firmale_reconcile_wait', 'Kontrollitud taastamist saab teha alles tund pärast ebaselge toimingu algust.');
        }
        $order_id = absint(get_post_meta($request_id, '_firmale_order_id', true));
        $owner = (string) get_post_meta($request_id, '_firmale_order_lock_owner', true);
        if (!$owner) { return new WP_Error('firmale_reconcile_missing', 'Luku tunnus puudub. Vajalik on tehniline kontroll.'); }
        if ($refund_id) {
            $refund = wc_get_order($refund_id);
            if (!$refund instanceof WC_Order_Refund || $refund->get_parent_id() !== $order_id
                || ($refund->get_meta('_firmale_return_request_id', true) && absint($refund->get_meta('_firmale_return_request_id', true)) !== absint($request_id))) {
                return new WP_Error('firmale_reconcile_invalid', 'Tagastuskanne ei kuulu sellele tellimusele või on seotud teise avaldusega.');
            }
            $refund->update_meta_data('_firmale_return_request_id', $request_id);
            $refund->save();
            update_post_meta($request_id, '_firmale_refund_id', $refund_id);
            update_post_meta($request_id, '_firmale_refund_amount', $refund->get_amount());
            update_post_meta($request_id, '_firmale_refund_currency', $refund->get_currency());
            update_post_meta($request_id, '_firmale_status', 'completed');
            update_post_meta($request_id, '_firmale_payment_state', 'reconciled');
        } else {
            update_post_meta($request_id, '_firmale_payment_state', 'verified_not_paid');
        }
        Firmale_Return_Features::instance()->audit($request_id, 'payment_reconciled', 'Administraator kontrollis makseteenuse ja WooCommerce’i tulemust.', array('refund_id' => $refund_id, 'evidence' => sanitize_textarea_field($evidence)));
        if (!Firmale_Return_Lock::release_verified('payment-order-' . $order_id, $owner)) {
            return new WP_Error('firmale_reconcile_lock', 'Kande kontroll salvestati, kuid lukk on muutunud. Ära tee uut makset; vajalik on tehniline kontroll.');
        }
        delete_post_meta($request_id, '_firmale_first_refund_approver');
        return true;
    }

    private function refunded_amounts_by_original_item($order, $item_type)
    {
        $result = array();
        foreach ($order->get_refunds() as $refund) {
            foreach ($refund->get_items($item_type) as $refunded_item) {
                $original_id = absint($refunded_item->get_meta('_refunded_item_id', true));
                if (!$original_id) {
                    continue;
                }
                if (!isset($result[$original_id])) {
                    $result[$original_id] = array('total' => 0.0, 'taxes' => array());
                }
                $result[$original_id]['total'] += abs((float) $refunded_item->get_total());
                $taxes = $refunded_item->get_taxes();
                foreach ((array) $taxes['total'] as $tax_rate_id => $tax_total) {
                    if (!isset($result[$original_id]['taxes'][$tax_rate_id])) {
                        $result[$original_id]['taxes'][$tax_rate_id] = 0.0;
                    }
                    $result[$original_id]['taxes'][$tax_rate_id] += abs((float) $tax_total);
                }
            }
        }
        return $result;
    }

    private function reason_labels()
    {
        return array(
            'changed_mind' => 'Muutsin meelt',
            'not_expected' => 'Toode ei vastanud ootustele',
            'wrong_size'   => 'Vale suurus või mõõt',
            'defect'       => 'Tootel on puudus',
            'other'        => 'Muu põhjus',
        );
    }

    private function order_note($request_id, $selected, $date_info)
    {
        $lines = array();
        foreach ($selected as $item) {
            $lines[] = sprintf('%s × %d', $item['name'], $item['quantity']);
        }
        return sprintf(
            "Taganemisavaldus #%d vastu võetud.\nTooted: %s\nTähtaja olek: %s\nKättesaamine: %s\nTähtaeg: %s",
            $request_id,
            implode(', ', $lines),
            $date_info['status_text'],
            $date_info['received_text'],
            $date_info['deadline_text']
        );
    }

    private function send_notifications($request_id, $order, $selected, $reason, $details, $date_info)
    {
        $admin_email = sanitize_email((string) get_option('firmale_return_admin_email', get_option('admin_email')));
        $request_type = get_post_meta($request_id, '_firmale_request_type', true) ?: 'withdrawal';
        $resolution = get_post_meta($request_id, '_firmale_resolution', true) ?: 'refund';
        $return_method = get_post_meta($request_id, '_firmale_return_method', true);
        $tracking_url = get_post_meta($request_id, '_firmale_tracking_url_customer', true);
        $method_labels = array('parcel_locker' => 'Pakiautomaat', 'courier' => 'Kuller', 'store' => 'Esindus');
        $resolution_labels = array('refund' => 'Raha tagastamine', 'repair' => 'Parandamine', 'replacement' => 'Asendamine', 'exchange' => 'Vahetamine', 'store_credit' => 'Poekrediit');
        $reason_labels = $this->reason_labels();
        $item_lines = array();
        foreach ($selected as $item) {
            $item_lines[] = sprintf('- %s × %d', $item['name'], $item['quantity']);
        }

        $admin_message = implode("\n", array(
            'Uus tagastusavaldus #' . $request_id,
            'Tellimus: #' . $order->get_order_number(),
            'Klient: ' . $order->get_formatted_billing_full_name(),
            'E-post: ' . $order->get_billing_email(),
            'Tähtaja olek: ' . $date_info['status_text'],
            'Avalduse liik: ' . ($request_type === 'defect' ? 'Puudusega või vale toode' : 'Taganemine'),
            'Soovitud lahendus: ' . (isset($resolution_labels[$resolution]) ? $resolution_labels[$resolution] : $resolution),
            'Tagastusviis: ' . (isset($method_labels[$return_method]) ? $method_labels[$return_method] : 'Ei valitud'),
            'Kättesaamine: ' . $date_info['received_text'],
            'Tähtaeg: ' . $date_info['deadline_text'],
            'Põhjus: ' . ($reason && isset($reason_labels[$reason]) ? $reason_labels[$reason] : 'Ei märgitud'),
            'Lisainfo: ' . ($details ?: 'Puudub'),
            '',
            'Tagastatavad tooted:',
            implode("\n", $item_lines),
            '',
            'Ava avaldus: ' . admin_url('post.php?post=' . $request_id . '&action=edit'),
        ));

        if ($admin_email) {
            wp_mail(
                $admin_email,
                'Uus taganemisavaldus – tellimus #' . $order->get_order_number(),
                $admin_message
            );
        }

        $customer_message = implode("\n", array(
            'Tere ' . ($order->get_billing_first_name() ?: '') . '!',
            '',
            'Oleme sinu avalduse #' . $request_id . ' kätte saanud.',
            'Tellimus: #' . $order->get_order_number(),
            'Klient: ' . $order->get_formatted_billing_full_name(),
            'Esitatud: ' . get_post_meta($request_id, '_firmale_submitted_at', true) . ' (' . wp_timezone()->getName() . ')',
            'Avalduse liik: ' . ($request_type === 'defect' ? 'Puudusega või vale toode' : 'Taganemine'),
            'Soovitud lahendus: ' . (isset($resolution_labels[$resolution]) ? $resolution_labels[$resolution] : $resolution),
            'Lisainfo: ' . ($details ?: 'Puudub'),
            '',
            'Tagastatavad tooted:',
            implode("\n", $item_lines),
            '',
            'Vaatame avalduse üle ja saadame edasised tagastusjuhised eraldi e-kirjaga.',
            $return_method === 'store' && get_option('firmale_return_store_address', '') ? 'Esinduse aadress: ' . get_option('firmale_return_store_address', '') : '',
            $tracking_url ? 'Avalduse olekut saad jälgida: ' . $tracking_url : '',
        ));

        $sent = wp_mail(
            $order->get_billing_email(),
            'Avaldus #' . $request_id . ' on vastu võetud',
            $customer_message
        );
        update_post_meta($request_id, '_firmale_receipt_email_sent', (bool) $sent);
        if (!$sent) { Firmale_Return_Features::instance()->audit($request_id, 'receipt_email_failed', 'Avalduse kinnituskirja saatmine ebaõnnestus.'); }
        return (bool) $sent;
    }
}
