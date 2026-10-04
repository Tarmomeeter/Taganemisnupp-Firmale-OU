<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Firmale_Return_Admin
{
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
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_' . Firmale_Return_Plugin::CPT, array($this, 'save_request'));
        add_filter('manage_' . Firmale_Return_Plugin::CPT . '_posts_columns', array($this, 'columns'));
        add_action('manage_' . Firmale_Return_Plugin::CPT . '_posts_custom_column', array($this, 'column_content'), 10, 2);
        add_action('admin_notices', array($this, 'woocommerce_notice'));
        add_action('admin_notices', array($this, 'refund_notice'));
        add_action('admin_notices', array($this, 'configuration_notice'));
    }

    public function admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            'Tagastusavaldused',
            'Tagastusavaldused',
            'manage_woocommerce',
            'edit.php?post_type=' . Firmale_Return_Plugin::CPT
        );

        add_submenu_page(
            'woocommerce',
            'Tagastusvormi seaded',
            'Tagastusvormi seaded',
            'manage_options',
            'firmale-return-settings',
            array($this, 'settings_page')
        );

        if ((bool) get_option('firmale_return_enable_analytics', true)) {
            add_submenu_page(
                'woocommerce',
                'Tagastuste analüütika',
                'Tagastuste analüütika',
                'manage_woocommerce',
                'firmale-return-analytics',
                array($this, 'analytics_page')
            );
        }
    }

    public function register_settings()
    {
        register_setting('firmale_return_settings', 'firmale_return_admin_email', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default'           => get_option('admin_email'),
        ));

        register_setting('firmale_return_settings', 'firmale_return_delivered_meta_key', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_meta_key'),
            'default'           => '_delivered_at',
        ));

        register_setting('firmale_return_settings', 'firmale_return_completed_fallback', array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => true,
        ));

        register_setting('firmale_return_settings', 'firmale_return_block_late', array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => false,
        ));

        register_setting('firmale_return_settings', 'firmale_return_show_estimate', array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => true,
        ));

        register_setting('firmale_return_settings', 'firmale_return_use_site_styles', array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => false,
        ));

        register_setting('firmale_return_settings', 'firmale_return_enable_refunds', array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => true,
        ));

        register_setting('firmale_return_settings', 'firmale_return_allow_auto_refund', array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => false,
        ));

        register_setting('firmale_return_settings', 'firmale_return_default_refund_mode', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_refund_mode'),
            'default'           => 'manual',
        ));

        register_setting('firmale_return_settings', 'firmale_return_allow_shipping_refund', array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => true,
        ));

        register_setting('firmale_return_settings', 'firmale_return_refund_shipping_default', array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => false,
        ));

        register_setting('firmale_return_settings', 'firmale_return_default_restock', array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => true,
        ));

        $boolean_settings = array(
            'firmale_return_enable_turnstile'           => false,
            'firmale_return_trust_cloudflare_ip'        => false,
            'firmale_return_enable_email_otp'           => true,
            'firmale_return_require_received'           => true,
            'firmale_return_enable_defect_flow'         => true,
            'firmale_return_enable_attachments'         => true,
            'firmale_return_enable_video_attachments'   => false,
            'firmale_return_status_emails'              => true,
            'firmale_return_enable_tracking'            => true,
            'firmale_return_enable_print_document'      => true,
            'firmale_return_enable_exchange'            => true,
            'firmale_return_enable_store_credit'        => false,
            'firmale_return_enable_extended_period'     => false,
            'firmale_return_enable_category_period'     => false,
            'firmale_return_enable_campaign_period'     => false,
            'firmale_return_enable_product_rules'       => true,
            'firmale_return_enable_shipment_dates'      => true,
            'firmale_return_enable_return_methods'      => true,
            'firmale_return_method_locker'              => true,
            'firmale_return_method_courier'             => true,
            'firmale_return_method_store'               => true,
            'firmale_return_enable_carrier_connector'   => false,
            'firmale_return_enable_privacy_tools'       => true,
            'firmale_return_enable_retention'           => false,
            'firmale_return_enable_analytics'           => true,
            'firmale_return_enable_audit_log'           => true,
            'firmale_return_separate_refund_capability' => false,
            'firmale_return_refund_shop_managers'       => true,
            'firmale_return_enable_dual_approval'       => false,
        );
        foreach ($boolean_settings as $option => $default) {
            register_setting('firmale_return_settings', $option, array(
                'type'              => 'boolean',
                'sanitize_callback' => array($this, 'sanitize_checkbox'),
                'default'           => $default,
            ));
        }

        $text_settings = array(
            'firmale_return_turnstile_site_key'        => '',
            'firmale_return_turnstile_secret_key'      => '',
            'firmale_return_tracking_page_url'         => '',
            'firmale_return_extended_roles'            => '',
            'firmale_return_extended_categories'       => '',
            'firmale_return_campaign_start'            => '',
            'firmale_return_campaign_end'              => '',
            'firmale_return_shipment_meta_keys'        => '',
            'firmale_return_store_address'              => '',
            'firmale_return_carrier_webhook_url'        => '',
            'firmale_return_carrier_webhook_secret'     => '',
        );
        foreach ($text_settings as $option => $default) {
            register_setting('firmale_return_settings', $option, array(
                'type'              => 'string',
                'sanitize_callback' => $option === 'firmale_return_tracking_page_url' || $option === 'firmale_return_carrier_webhook_url'
                    ? 'esc_url_raw'
                    : 'sanitize_text_field',
                'default'           => $default,
            ));
        }

        $number_settings = array(
            'firmale_return_otp_expiry'                => 10,
            'firmale_return_attachment_max_mb'         => 2,
            'firmale_return_default_period_days'       => 14,
            'firmale_return_extended_period_days'      => 30,
            'firmale_return_category_period_days'      => 30,
            'firmale_return_campaign_period_days'      => 30,
            'firmale_return_retention_days'            => 730,
        );
        foreach ($number_settings as $option => $default) {
            register_setting('firmale_return_settings', $option, array(
                'type'              => 'integer',
                'sanitize_callback' => function ($value) use ($option) {
                    $limits = array('firmale_return_otp_expiry' => array(5, 30), 'firmale_return_attachment_max_mb' => array(1, 5),
                        'firmale_return_default_period_days' => array(14, 365), 'firmale_return_extended_period_days' => array(14, 365),
                        'firmale_return_category_period_days' => array(14, 365), 'firmale_return_campaign_period_days' => array(14, 365),
                        'firmale_return_retention_days' => array(30, 3650));
                    return min($limits[$option][1], max($limits[$option][0], absint($value)));
                },
                'default'           => $default,
            ));
        }

        register_setting('firmale_return_settings', 'firmale_return_carrier_provider', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_carrier_provider'),
            'default'           => 'omniva',
        ));
        register_setting('firmale_return_settings', 'firmale_return_shipping_refund_mode', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_shipping_mode'),
            'default'           => 'manual',
        ));
        register_setting('firmale_return_settings', 'firmale_return_dual_approval_threshold', array(
            'type'              => 'number',
            'sanitize_callback' => 'wc_format_decimal',
            'default'           => 200,
        ));
        register_setting('firmale_return_settings', 'firmale_return_standard_shipping_cap', array(
            'type'              => 'number',
            'sanitize_callback' => 'wc_format_decimal',
            'default'           => 0,
        ));
        register_setting('firmale_return_settings', 'firmale_return_locker_max_weight', array(
            'type'              => 'number',
            'sanitize_callback' => 'wc_format_decimal',
            'default'           => 30,
        ));
    }

    public function sanitize_meta_key($value)
    {
        $value = sanitize_key($value);
        return $value ?: '_delivered_at';
    }

    public function sanitize_checkbox($value)
    {
        return !empty($value);
    }

    public function sanitize_refund_mode($value)
    {
        return $value === 'automatic' ? 'automatic' : 'manual';
    }

    public function sanitize_carrier_provider($value)
    {
        $value = sanitize_key($value);
        return in_array($value, array('omniva', 'dpd', 'smartpost', 'custom'), true) ? $value : 'omniva';
    }

    public function sanitize_shipping_mode($value)
    {
        $value = sanitize_key($value);
        return in_array($value, array('none', 'manual', 'full_order'), true) ? $value : 'manual';
    }

    public function settings_page()
    {
        if (!current_user_can('manage_options') || !class_exists('WooCommerce')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Tagastusvormi seaded</h1>
            <p>Lisa tagastusvorm Breakdance’i Shortcode-elemendiga: <code>[firmale_tagastusvorm]</code></p>

            <form method="post" action="options.php">
                <?php settings_fields('firmale_return_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="firmale_return_admin_email">Teavituste e-post</label></th>
                        <td>
                            <input class="regular-text" type="email" id="firmale_return_admin_email"
                                   name="firmale_return_admin_email"
                                   value="<?php echo esc_attr(get_option('firmale_return_admin_email', get_option('admin_email'))); ?>">
                            <p class="description">Sellele aadressile saadetakse uued taganemisavaldused.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="firmale_return_delivered_meta_key">Kättesaamisaja metaväli</label></th>
                        <td>
                            <input class="regular-text" type="text" id="firmale_return_delivered_meta_key"
                                   name="firmale_return_delivered_meta_key"
                                   value="<?php echo esc_attr(get_option('firmale_return_delivered_meta_key', '_delivered_at')); ?>">
                            <p class="description">Vedaja integratsiooni salvestatud tegeliku üleandmisaja tellimuse meta-võti.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Varukuupäev</th>
                        <td>
                            <label>
                                <input type="checkbox" name="firmale_return_completed_fallback" value="1"
                                    <?php checked((bool) get_option('firmale_return_completed_fallback', true)); ?>>
                                Kasuta puuduva kättesaamisaja korral WooCommerce’i „Täidetud” kuupäeva
                            </label>
                            <p class="description">Lülita sisse ainult siis, kui „Täidetud” tähendab paki tegelikku kättesaamist.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Hilinenud avaldused</th>
                        <td>
                            <label>
                                <input type="checkbox" name="firmale_return_block_late" value="1"
                                    <?php checked((bool) get_option('firmale_return_block_late', false)); ?>>
                                Blokeeri automaatse arvestuse järgi hilinenud avaldused
                            </label>
                            <p class="description">Soovitus: jäta välja lülitatuks ja vaata sellised avaldused käsitsi üle.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Saidi kujundus</th>
                        <td>
                            <label>
                                <input type="checkbox" name="firmale_return_use_site_styles" value="1"
                                    <?php checked((bool) get_option('firmale_return_use_site_styles', false)); ?>>
                                Kasuta saidi globaalseid värve ja fonte
                            </label>
                            <p class="description">Seob vormi Elementori Global Colors/Fonts või Breakdance’i Global Colors/Typography seadetega. Väljalülitatuna kasutatakse plugina enda rohelist kujundust.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Kliendi summaprognoos</th>
                        <td>
                            <label>
                                <input type="checkbox" name="firmale_return_show_estimate" value="1"
                                    <?php checked((bool) get_option('firmale_return_show_estimate', true)); ?>>
                                Näita kliendile valitud toodete hinnangulist tagastussummat
                            </label>
                            <p class="description">Arvestus sisaldab toote allahindlust ja makse. Saatmiskulu lisatakse ainult administraatori otsusel.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Rahatagastuse tööriist</th>
                        <td>
                            <label>
                                <input type="checkbox" name="firmale_return_enable_refunds" value="1"
                                    <?php checked((bool) get_option('firmale_return_enable_refunds', true)); ?>>
                                Luba tagastusavalduse vaates WooCommerce’i rahatagastuse loomine
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Automaatne rahatagastus</th>
                        <td>
                            <label>
                                <input type="checkbox" name="firmale_return_allow_auto_refund" value="1"
                                    <?php checked((bool) get_option('firmale_return_allow_auto_refund', false)); ?>>
                                Luba raha saatmine kliendile makselüüsi kaudu
                            </label>
                            <p class="description"><strong>Ettevaatust:</strong> automaatne valik saadab raha päriselt tagasi, kui tellimuse makseviis seda toetab.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="firmale_return_default_refund_mode">Vaikimisi tagastusviis</label></th>
                        <td>
                            <select id="firmale_return_default_refund_mode" name="firmale_return_default_refund_mode">
                                <option value="manual" <?php selected(get_option('firmale_return_default_refund_mode', 'manual'), 'manual'); ?>>Käsitsi – märgi WooCommerce’is, raha kannan ise</option>
                                <option value="automatic" <?php selected(get_option('firmale_return_default_refund_mode', 'manual'), 'automatic'); ?>>Automaatne – saada raha makselüüsi kaudu</option>
                            </select>
                            <p class="description">Turvaline vaikeseade on käsitsi. Automaatset valikut kasutatakse ainult siis, kui see on ülal lubatud ja makseviis toetab tagastusi.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Saatmiskulu</th>
                        <td>
                            <label>
                                <input type="checkbox" name="firmale_return_allow_shipping_refund" value="1"
                                    <?php checked((bool) get_option('firmale_return_allow_shipping_refund', true)); ?>>
                                Luba administraatoril lisada tagastusele allesjäänud saatmiskulu
                            </label><br>
                            <label>
                                <input type="checkbox" name="firmale_return_refund_shipping_default" value="1"
                                    <?php checked((bool) get_option('firmale_return_refund_shipping_default', false)); ?>>
                                Vali saatmiskulu tagastamine avalduse vaates vaikimisi
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Laoseis</th>
                        <td>
                            <label>
                                <input type="checkbox" name="firmale_return_default_restock" value="1"
                                    <?php checked((bool) get_option('firmale_return_default_restock', true)); ?>>
                                Lisa tagastatud toodete kogus vaikimisi lattu tagasi
                            </label>
                            <p class="description">Administraator saab seda iga avalduse juures eraldi muuta.</p>
                        </td>
                    </tr>
                    <?php $this->settings_section('Turvalisus'); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_turnstile', 'Cloudflare Turnstile', 'Kaitse tellimuse kontrolli Cloudflare Turnstile’iga', 'Kui see on sisse lülitatud, peab lisama ka saidi- ja salajase võtme.', false); ?>
                    <?php $this->settings_checkbox_row('firmale_return_trust_cloudflare_ip', 'Cloudflare külastaja IP', 'Kasuta päringupiirangus CF-Connecting-IP päist', 'Lülita sisse ainult siis, kui origin võtab vastu üksnes Cloudflare’i liiklust. Turnstile ei vaja seda valikut.', false); ?>
                    <tr>
                        <th scope="row">Turnstile võtmed</th>
                        <td>
                            <label>Site key<br><input class="regular-text" type="text" name="firmale_return_turnstile_site_key" value="<?php echo esc_attr(get_option('firmale_return_turnstile_site_key', '')); ?>"></label><br><br>
                            <label>Secret key<br><input class="regular-text" type="password" autocomplete="new-password" name="firmale_return_turnstile_secret_key" value="<?php echo esc_attr(get_option('firmale_return_turnstile_secret_key', '')); ?>"></label>
                            <p class="description">Võtmed leiad Cloudflare’i juhtpaneelist: Turnstile → Add widget. Server kontrollib vastust Cloudflare’i Siteverify teenusega.</p>
                        </td>
                    </tr>
                    <?php $this->settings_checkbox_row('firmale_return_enable_email_otp', 'E-posti kinnituskood', 'Nõua enne tellimuse sisu kuvamist 6-kohalist e-posti koodi', 'Soovituslik külaliste tellimuste kaitseks.', true); ?>
                    <?php $this->settings_number_row('firmale_return_otp_expiry', 'Koodi kehtivus', 'minutit', 10, 5, 30); ?>
                    <?php $this->settings_checkbox_row('firmale_return_separate_refund_capability', 'Eraldi tagastusõigus', 'Nõua rahatagastuseks eraldi firmale_process_refunds õigust', 'Administraatorile lisatakse õigus alati; alloleva valikuga saab selle anda ka poehaldurile.', false); ?>
                    <?php $this->settings_checkbox_row('firmale_return_refund_shop_managers', 'Poehalduri õigus', 'Luba Shop Manager rollil rahatagastusi kinnitada', '', true); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_dual_approval', 'Kahe inimese kinnitus', 'Nõua suure rahatagastuse jaoks kahte erinevat kinnitajat', '', false); ?>
                    <tr>
                        <th scope="row">Kahe kinnituse piirmäär</th>
                        <td><input type="number" min="0" step="0.01" name="firmale_return_dual_approval_threshold" value="<?php echo esc_attr(get_option('firmale_return_dual_approval_threshold', 200)); ?>"> <?php echo esc_html(get_woocommerce_currency_symbol()); ?></td>
                    </tr>

                    <?php $this->settings_section('Menetlus ja kliendivaade'); ?>
                    <?php $this->settings_checkbox_row('firmale_return_require_received', 'Rahatagastuse turvalukk', 'Luba rahatagastus alles pärast kauba saabumise või kontrolli olekut', 'Soovituslik: väldib raha tagastamist enne kauba või saatmistõendi kontrolli.', true); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_defect_flow', 'Puudusega kauba voog', 'Erista puudusega või vale toote avaldus tavalisest taganemisest', '', true); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_attachments', 'Turvalised manused', 'Luba puuduse fotod ja PDF-dokumendid', 'Failid salvestatakse avalduse metaandmetesse ning neid saab avada ainult haldusest.', true); ?>
                    <?php $this->settings_number_row('firmale_return_attachment_max_mb', 'Ühe manuse suurus', 'MB', 2, 1, 5); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_video_attachments', 'Videomanused', 'Luba lisaks MP4- ja WebM-videod sama suuruspiiriga', 'Failid on privaatsed, kuid plugin ei tee viirusekontrolli. Pikad videod tuleb edastada klienditeenindusele eraldi.', false); ?>
                    <?php $this->settings_checkbox_row('firmale_return_status_emails', 'Oleku e-kirjad', 'Saada kliendile e-kiri iga menetluse oleku muutumisel', '', true); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_tracking', 'Kliendi jälgimisleht', 'Luba turvatokeniga tagastuse olekuvaade', 'Loo leht shortcode’iga [firmale_tagastuse_staatus] ja sisesta selle URL allpool.', true); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_print_document', 'Prinditav tagastusleht', 'Luba kliendil avada printimisvaade ja salvestada juhend PDF-ina', '', true); ?>
                    <tr>
                        <th scope="row">Jälgimislehe URL</th>
                        <td><input class="regular-text" type="url" name="firmale_return_tracking_page_url" placeholder="https://example.ee/tagastuse-olek/" value="<?php echo esc_attr(get_option('firmale_return_tracking_page_url', '')); ?>"></td>
                    </tr>
                    <?php $this->settings_checkbox_row('firmale_return_enable_exchange', 'Vahetamine', 'Luba kliendil soovida toote vahetamist', '', true); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_store_credit', 'Poekrediidi soov', 'Luba kliendil soovida raha asemel poekrediiti', 'Salvestab kliendi soovi. Saldo väljastamine vajab eraldi poekrediidi integratsiooni; sooduskupongi ei looda.', false); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_product_rules', 'Tootepõhised reeglid', 'Lisa tootele käsitsi kontrolli ja pikema perioodi väljad', '', true); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_audit_log', 'Auditilogi', 'Salvesta avalduse olulised toimingud, kasutaja ja kellaaeg', '', true); ?>

                    <?php $this->settings_section('Tähtajad'); ?>
                    <?php $this->settings_number_row('firmale_return_default_period_days', 'Vaikimisi periood', 'päeva', 14, 14, 365); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_extended_period', 'Pikendatud periood', 'Luba valitud kasutajarollidele pikem tagastusperiood', '', false); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_shipment_dates', 'Mitme saadetise kuupäevad', 'Kasuta mitmest metaväljast leitud viimast kättesaamiskuupäeva', '', true); ?>
                    <?php $this->settings_number_row('firmale_return_extended_period_days', 'Pikendatud periood', 'päeva', 30, 14, 365); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_category_period', 'Kategooria pikem periood', 'Rakenda valitud tootekategooriatele pikem periood', '', false); ?>
                    <?php $this->settings_number_row('firmale_return_category_period_days', 'Kategooria periood', 'päeva', 30, 14, 365); ?>
                    <tr><th>Kategooriate ID-d</th><td><input class="regular-text" name="firmale_return_extended_categories" value="<?php echo esc_attr(get_option('firmale_return_extended_categories', '')); ?>"><p class="description">Komadega eraldatud täpsed WooCommerce tootekategooriate ID-d. Alamkategooriad lisa eraldi.</p></td></tr>
                    <?php $this->settings_checkbox_row('firmale_return_enable_campaign_period', 'Kampaania pikem periood', 'Pikenda allolevas ostukuupäevade vahemikus tehtud tellimuste perioodi', '', false); ?>
                    <?php $this->settings_number_row('firmale_return_campaign_period_days', 'Kampaania periood', 'päeva', 30, 14, 365); ?>
                    <tr><th>Kampaania ostukuupäevad</th><td><input type="date" name="firmale_return_campaign_start" value="<?php echo esc_attr(get_option('firmale_return_campaign_start', '')); ?>"> kuni <input type="date" name="firmale_return_campaign_end" value="<?php echo esc_attr(get_option('firmale_return_campaign_end', '')); ?>"><p class="description">Mõlemad päevad kaasa arvatud, poe ajavööndis. Pikendatud tagastusperiood algab endiselt kättesaamisest.</p></td></tr>
                    <tr>
                        <th scope="row">Pikendatud perioodi rollid</th>
                        <td><input class="regular-text" type="text" name="firmale_return_extended_roles" placeholder="customer,vip_customer" value="<?php echo esc_attr(get_option('firmale_return_extended_roles', '')); ?>"><p class="description">Komadega eraldatud WordPressi rollide võtmed.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Saadetiste kuupäevaväljad</th>
                        <td><input class="regular-text" type="text" name="firmale_return_shipment_meta_keys" value="<?php echo esc_attr(get_option('firmale_return_shipment_meta_keys', '_delivered_at')); ?>"><p class="description">Komadega eraldatud tellimuse metaväljad. Mitme kuupäeva korral kasutatakse viimast kättesaamist.</p></td>
                    </tr>

                    <?php $this->settings_section('Logistika'); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_return_methods', 'Tagastusviisid', 'Lase kliendil valida tagastusviis', '', true); ?>
                    <?php $this->settings_checkbox_row('firmale_return_method_locker', 'Pakiautomaat', 'Näita pakiautomaadi valikut', '', true); ?>
                    <?php $this->settings_checkbox_row('firmale_return_method_courier', 'Kuller', 'Näita kulleri valikut', '', true); ?>
                    <?php $this->settings_checkbox_row('firmale_return_method_store', 'Esindus', 'Näita esindusse tagastamise valikut', '', true); ?>
                    <tr>
                        <th scope="row">Pakiautomaadi kaalupiir</th>
                        <td><input type="number" min="0" step="0.1" name="firmale_return_locker_max_weight" value="<?php echo esc_attr(get_option('firmale_return_locker_max_weight', 30)); ?>"> kg<p class="description">0 eemaldab automaatse kaalupiiri. Piiri ületav tagastus suunatakse kullerile või esindusse.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Esinduse aadress</th>
                        <td><input class="regular-text" type="text" name="firmale_return_store_address" value="<?php echo esc_attr(get_option('firmale_return_store_address', '')); ?>"></td>
                    </tr>
                    <?php $this->settings_checkbox_row('firmale_return_enable_carrier_connector', 'Vedaja ühendus', 'Luba tagastuskoodi loomine turvalise välise HTTPS-liidese kaudu', 'Toetab Omniva, DPD, Smartposti või kohandatud adapterit. Vajab sinu ärikliendi liidese vahepunkti ja võtmeid.', false); ?>
                    <tr>
                        <th scope="row">Vedaja</th>
                        <td><select name="firmale_return_carrier_provider">
                            <?php foreach (array('omniva' => 'Omniva', 'dpd' => 'DPD', 'smartpost' => 'Smartpost', 'custom' => 'Kohandatud') as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected(get_option('firmale_return_carrier_provider', 'omniva'), $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select></td>
                    </tr>
                    <tr>
                        <th scope="row">Vedaja HTTPS vahepunkt</th>
                        <td><input class="regular-text" type="url" name="firmale_return_carrier_webhook_url" value="<?php echo esc_attr(get_option('firmale_return_carrier_webhook_url', '')); ?>"><p class="description">Plugin saadab allkirjastatud JSON-päringu. Vastus peab sisaldama return_code ning võib sisaldada tracking_url ja label_url välju.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Ühenduse salavõti</th>
                        <td><input class="regular-text" type="password" autocomplete="new-password" name="firmale_return_carrier_webhook_secret" value="<?php echo esc_attr(get_option('firmale_return_carrier_webhook_secret', '')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Saatmiskulu tagastamine</th>
                        <td><select name="firmale_return_shipping_refund_mode">
                            <option value="none" <?php selected(get_option('firmale_return_shipping_refund_mode', 'manual'), 'none'); ?>>Keelatud</option>
                            <option value="manual" <?php selected(get_option('firmale_return_shipping_refund_mode', 'manual'), 'manual'); ?>>Administraatori valikul</option>
                            <option value="full_order" <?php selected(get_option('firmale_return_shipping_refund_mode', 'manual'), 'full_order'); ?>>Ainult kogu allesjäänud tellimuse tagastamisel</option>
                        </select></td>
                    </tr>
                    <tr>
                        <th scope="row">Tavapärase tarne ülempiir</th>
                        <td><input type="number" min="0" step="0.01" name="firmale_return_standard_shipping_cap" value="<?php echo esc_attr(get_option('firmale_return_standard_shipping_cap', 0)); ?>"> <?php echo esc_html(get_woocommerce_currency_symbol()); ?><p class="description">0 tähendab, et ülempiiri ei rakendata. Määra siia poe odavaima tavapärase tarne maksimaalne hüvitatav summa.</p></td>
                    </tr>

                    <?php $this->settings_section('Privaatsus ja aruandlus'); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_privacy_tools', 'WordPressi privaatsustööriistad', 'Lisa tagastusandmed isikuandmete ekspordi ja kustutamise tööriistadesse', '', true); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_retention', 'Kontaktandmete automaatne eemaldamine', 'Eemalda lõpetatud avalduste kontaktandmed, vaba tekst, manused ja jälgimislingid', 'Aeg algab menetluse lõpetamisest. Tellimusseos ja tehingukirjed säilivad; see ei ole täielik anonümiseerimine.', false); ?>
                    <?php $this->settings_number_row('firmale_return_retention_days', 'Säilitusaeg', 'päeva', 730, 30, 3650); ?>
                    <?php $this->settings_checkbox_row('firmale_return_enable_analytics', 'Tagastusanalüütika', 'Näita WooCommerce’i menüüs tagastuste koondaruannet', '', true); ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function settings_section($title)
    {
        echo '<tr><th colspan="2" style="padding-top:32px"><h2 style="margin:0">' . esc_html($title) . '</h2></th></tr>';
    }

    private function settings_checkbox_row($option, $title, $label, $description = '', $default = false)
    {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($title); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr($option); ?>" value="1" <?php checked((bool) get_option($option, $default)); ?>>
                    <?php echo esc_html($label); ?>
                </label>
                <?php if ($description) : ?><p class="description"><?php echo esc_html($description); ?></p><?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function settings_number_row($option, $title, $suffix, $default, $min, $max)
    {
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($option); ?>"><?php echo esc_html($title); ?></label></th>
            <td>
                <input type="number" id="<?php echo esc_attr($option); ?>" name="<?php echo esc_attr($option); ?>"
                       min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>"
                       value="<?php echo esc_attr(get_option($option, $default)); ?>">
                <?php echo esc_html($suffix); ?>
            </td>
        </tr>
        <?php
    }

    public function analytics_page()
    {
        if (!current_user_can('manage_woocommerce') || !class_exists('WooCommerce') || !get_option('firmale_return_enable_analytics', true)) {
            return;
        }
        $ids = get_posts(array(
            'post_type'      => Firmale_Return_Plugin::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1000,
            'date_query'     => array(array('after' => '12 months ago')),
            'fields'         => 'ids',
        ));
        $status_counts = array();
        $reason_counts = array();
        $product_counts = array();
        $refunded_total = array();
        foreach ($ids as $request_id) {
            $status = get_post_meta($request_id, '_firmale_status', true) ?: 'new';
            $reason = get_post_meta($request_id, '_firmale_reason', true) ?: 'not_given';
            $status_counts[$status] = isset($status_counts[$status]) ? $status_counts[$status] + 1 : 1;
            $reason_counts[$reason] = isset($reason_counts[$reason]) ? $reason_counts[$reason] + 1 : 1;
            $currency = (string) get_post_meta($request_id, '_firmale_refund_currency', true);
            if (!$currency) {
                $refund_order = wc_get_order(absint(get_post_meta($request_id, '_firmale_order_id', true)));
                $currency = $refund_order ? $refund_order->get_currency() : 'EUR';
            }
            $refunded_total[$currency] = ($refunded_total[$currency] ?? 0) + (float) get_post_meta($request_id, '_firmale_refund_amount', true);
            foreach ((array) get_post_meta($request_id, '_firmale_items', true) as $item) {
                $name = isset($item['name']) ? $item['name'] : 'Tundmatu toode';
                $quantity = isset($item['quantity']) ? absint($item['quantity']) : 0;
                $product_counts[$name] = isset($product_counts[$name]) ? $product_counts[$name] + $quantity : $quantity;
            }
        }
        arsort($status_counts);
        arsort($reason_counts);
        arsort($product_counts);
        $statuses = $this->status_labels();
        $reasons = $this->reason_labels();
        ?>
        <div class="wrap">
            <h1>Tagastuste analüütika</h1>
            <p>Viimase 12 kuu kuni 1000 uusima avalduse koondvaade. Tootekogused tähistavad esitatud soove, mitte kinnitatud tagastusi. Summad sisaldavad ka käsitsi maksmist ootavaid WooCommerce’i kandeid.</p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;max-width:1100px;margin:20px 0">
                <div class="card"><h2>Avaldusi</h2><p style="font-size:30px;margin:0"><strong><?php echo esc_html(count($ids)); ?></strong></p></div>
                <div class="card"><h2>Tagastuskannete summa</h2><p style="font-size:30px;margin:0"><strong><?php foreach ($refunded_total as $currency => $total) { echo wp_kses_post(wc_price($total, array('currency' => $currency))) . '<br>'; } ?></strong></p></div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;max-width:1100px">
                <div><h2>Olekud</h2><table class="widefat striped"><tbody><?php foreach ($status_counts as $key => $count) : ?><tr><td><?php echo esc_html(isset($statuses[$key]) ? $statuses[$key] : $key); ?></td><td><?php echo esc_html($count); ?></td></tr><?php endforeach; ?></tbody></table></div>
                <div><h2>Põhjused</h2><table class="widefat striped"><tbody><?php foreach ($reason_counts as $key => $count) : ?><tr><td><?php echo esc_html(isset($reasons[$key]) ? $reasons[$key] : ($key === 'not_given' ? 'Ei märgitud' : $key)); ?></td><td><?php echo esc_html($count); ?></td></tr><?php endforeach; ?></tbody></table></div>
                <div><h2>Enim tagastatud tooted</h2><table class="widefat striped"><tbody><?php foreach (array_slice($product_counts, 0, 20, true) as $name => $count) : ?><tr><td><?php echo esc_html($name); ?></td><td><?php echo esc_html($count); ?></td></tr><?php endforeach; ?></tbody></table></div>
            </div>
        </div>
        <?php
    }

    public function add_meta_boxes()
    {
        add_meta_box(
            'firmale_return_details',
            'Taganemisavalduse andmed',
            array($this, 'details_meta_box'),
            Firmale_Return_Plugin::CPT,
            'normal',
            'high'
        );
    }

    public function details_meta_box($post)
    {
        $order_id = absint(get_post_meta($post->ID, '_firmale_order_id', true));
        $name = get_post_meta($post->ID, '_firmale_customer_name', true);
        $email = get_post_meta($post->ID, '_firmale_customer_email', true);
        $items = get_post_meta($post->ID, '_firmale_items', true);
        $reason = get_post_meta($post->ID, '_firmale_reason', true);
        $details = get_post_meta($post->ID, '_firmale_details', true);
        $date_info = get_post_meta($post->ID, '_firmale_date_info', true);
        $status = get_post_meta($post->ID, '_firmale_status', true) ?: 'new';
        $status_message = get_post_meta($post->ID, '_firmale_status_message', true);
        $statuses = $this->status_labels();
        $reasons = $this->reason_labels();
        $order = $order_id ? wc_get_order($order_id) : null;
        $order_url = $order ? $order->get_edit_order_url() : '';
        $refunds_enabled = (bool) get_option('firmale_return_enable_refunds', true);
        $refund_id = absint(get_post_meta($post->ID, '_firmale_refund_id', true));
        $refund_amount = (float) get_post_meta($post->ID, '_firmale_refund_amount', true);
        $refund_mode = get_post_meta($post->ID, '_firmale_refund_mode', true);
        $refunded_at = get_post_meta($post->ID, '_firmale_refunded_at', true);
        $allow_shipping = (bool) get_option('firmale_return_allow_shipping_refund', true)
            && get_option('firmale_return_shipping_refund_mode', 'manual') !== 'none';
        $shipping_default = $allow_shipping && (bool) get_option('firmale_return_refund_shipping_default', false);
        $restock_default = (bool) get_option('firmale_return_default_restock', true);
        $default_mode = get_option('firmale_return_default_refund_mode', 'manual') === 'automatic' ? 'automatic' : 'manual';
        $features = Firmale_Return_Features::instance();
        $can_auto_refund = $order && Firmale_Return_Plugin::instance()->can_automatically_refund($order);
        $can_process_refunds = $features->can_process_refunds();
        $request_type = get_post_meta($post->ID, '_firmale_request_type', true) ?: 'withdrawal';
        $resolution = get_post_meta($post->ID, '_firmale_resolution', true) ?: 'refund';
        $return_method = get_post_meta($post->ID, '_firmale_return_method', true);
        $return_code = get_post_meta($post->ID, '_firmale_return_code', true);
        $return_tracking_url = get_post_meta($post->ID, '_firmale_return_tracking_url', true);
        $return_label_url = get_post_meta($post->ID, '_firmale_return_label_url', true);
        $store_credit_coupon = get_post_meta($post->ID, '_firmale_store_credit_coupon', true);
        if ($store_credit_coupon) {
            $refunds_enabled = false;
        }
        $attachment_links = $features->attachment_links($post->ID);
        $audit_log = $features->audit_entries($post->ID);
        $refund_gate_open = !(bool) get_option('firmale_return_require_received', true)
            || (in_array($status, array('received', 'inspecting', 'accepted'), true) && get_post_meta($post->ID, '_firmale_received_evidence', true));
        $first_approver = absint(get_post_meta($post->ID, '_firmale_first_refund_approver', true));
        if (!$can_auto_refund) {
            $default_mode = 'manual';
        }
        $preview_products = $refunds_enabled && !$refund_id
            ? Firmale_Return_Plugin::instance()->get_refund_preview($post->ID, false)
            : null;
        $preview_shipping = $refunds_enabled && !$refund_id && $allow_shipping && get_option('firmale_return_shipping_refund_mode', 'manual') !== 'none'
            ? Firmale_Return_Plugin::instance()->get_refund_preview($post->ID, true)
            : null;

        wp_nonce_field('firmale_return_save_request', 'firmale_return_nonce');
        ?>
        <table class="widefat striped" style="max-width:900px">
            <tbody>
                <tr><th style="width:220px">WooCommerce’i tellimus</th><td><?php if ($order_url) : ?><a href="<?php echo esc_url($order_url); ?>">#<?php echo esc_html($order->get_order_number()); ?></a><?php else : ?>–<?php endif; ?></td></tr>
                <tr><th>Klient</th><td><?php echo esc_html($name); ?></td></tr>
                <tr><th>E-post</th><td><a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></td></tr>
                <tr><th>Tähtaja olek</th><td><?php echo esc_html(isset($date_info['status_text']) ? $date_info['status_text'] : 'Vajab kontrolli'); ?></td></tr>
                <tr><th>Kättesaamine</th><td><?php echo esc_html(isset($date_info['received_text']) ? $date_info['received_text'] : '–'); ?></td></tr>
                <tr><th>Tähtaeg</th><td><?php echo esc_html(isset($date_info['deadline_text']) ? $date_info['deadline_text'] : '–'); ?></td></tr>
                <tr><th>Põhjus</th><td><?php echo esc_html(isset($reasons[$reason]) ? $reasons[$reason] : 'Ei märgitud'); ?></td></tr>
                <tr><th>Lisainfo</th><td><?php echo nl2br(esc_html($details ?: 'Puudub')); ?></td></tr>
                <tr><th>Avalduse liik</th><td><?php echo esc_html($request_type === 'defect' ? 'Puudusega või vale toode' : '14-päevane taganemine'); ?></td></tr>
                <tr><th>Soovitud lahendus</th><td><?php echo esc_html($resolution); ?></td></tr>
                <tr><th>Tagastusviis</th><td><?php echo esc_html($return_method ?: 'Ei valitud'); ?></td></tr>
                <?php if ($attachment_links) : ?>
                    <tr><th>Turvalised manused</th><td><?php foreach ($attachment_links as $attachment) : ?><a class="button" href="<?php echo esc_url($attachment['url']); ?>"><?php echo esc_html($attachment['name']); ?></a> <?php endforeach; ?></td></tr>
                <?php endif; ?>
                <tr>
                    <th>Tagastatavad tooted</th>
                    <td>
                        <?php if (is_array($items)) : ?>
                            <ul>
                                <?php foreach ($items as $item) : ?>
                                    <li><?php echo esc_html($item['name'] . ' × ' . $item['quantity']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="firmale_return_status">Menetluse olek</label></th>
                    <td>
                        <select id="firmale_return_status" name="firmale_return_status">
                            <?php foreach ($statuses as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="firmale_status_message">Sõnum kliendile</label></th>
                    <td><textarea class="large-text" id="firmale_status_message" name="firmale_status_message" rows="3" placeholder="Näiteks tagasilükkamise põhjus või järgmised juhised"><?php echo esc_textarea($status_message); ?></textarea></td>
                </tr>
                <?php if ($can_process_refunds) : ?>
                <tr><th><label for="firmale_received_evidence">Kauba saabumise või saatmise tõend</label></th>
                    <td><textarea class="large-text" id="firmale_received_evidence" name="firmale_received_evidence" rows="2" placeholder="Näiteks lao vastuvõtt, kuupäev ja vastuvõtja või kontrollitud saatmiskviitung"><?php echo esc_textarea(get_post_meta($post->ID, '_firmale_received_evidence', true)); ?></textarea>
                    <p class="description">Kirjelduse salvestamisel talletatakse kinnitaja ja aeg. See ei tähenda automaatset vedaja kinnitust.</p></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if (current_user_can('manage_options') && $can_process_refunds && in_array(get_post_meta($post->ID, '_firmale_payment_state', true), array('processing', 'uncertain'), true)) : ?>
            <div class="notice notice-error inline" style="max-width:900px;padding:16px">
                <h3>Ebaselge maksetulemuse käsitsi kontroll</h3>
                <p>Kontrolli kõigepealt makseteenuse haldusest ja WooCommerce’ist, kas raha liiguti. Veendu, et eelmine päring ei ole enam töös. Ära eemalda lukku ainult veateate tõttu. Taastamine on lubatud tund pärast toimingu algust.</p>
                <p><label>Olemasoleva, kontrollitud rahatagastuse ID (0 ainult siis, kui ei tehtud makset ega tagastuskannet)<br><input type="number" min="0" name="firmale_reconcile_refund_id" value="0"></label></p>
                <p><label>Kontrolli kirjeldus / teenuse tehingutunnus<br><textarea class="large-text" name="firmale_reconcile_evidence" rows="3"></textarea></label></p>
                <p><label><input type="checkbox" name="firmale_reconcile_confirm" value="1"> Kontrollisin mõlemat süsteemi ja kinnitan, et topeltmakse ohtu ei ole.</label></p>
                <button class="button" type="submit" name="firmale_reconcile_payment" value="1">Salvesta kontroll ja vabasta tellimuse lukk</button>
            </div>
        <?php endif; ?>
        <div style="max-width:900px;margin-top:20px;padding:20px;border:1px solid #c3c4c7;background:#fff">
            <h2 style="margin-top:0">Tagastuslogistika</h2>
            <p>
                <label><strong>Tagastuskood</strong><br><input class="regular-text" type="text" name="firmale_return_code" value="<?php echo esc_attr($return_code); ?>"></label>
            </p>
            <p>
                <label><strong>Saadetise jälgimise URL</strong><br><input class="large-text" type="url" name="firmale_return_tracking_url" value="<?php echo esc_attr($return_tracking_url); ?>"></label>
            </p>
            <p>
                <label><strong>Pakisildi URL</strong><br><input class="large-text" type="url" name="firmale_return_label_url" value="<?php echo esc_attr($return_label_url); ?>"></label>
            </p>
            <?php if ((bool) get_option('firmale_return_enable_carrier_connector', false)) : ?>
                <p><button class="button" type="submit" name="firmale_generate_carrier_code" value="1">Loo vedaja tagastuskood</button></p>
            <?php endif; ?>
            <?php if ($return_tracking_url) : ?><p><a href="<?php echo esc_url($return_tracking_url); ?>" target="_blank" rel="noopener">Ava saadetise jälgimine</a></p><?php endif; ?>
            <?php if ($return_label_url) : ?><p><a href="<?php echo esc_url($return_label_url); ?>" target="_blank" rel="noopener">Ava või prindi pakisilt / salvesta PDF-ina</a></p><?php endif; ?>
        </div>
        <?php if ($resolution === 'store_credit' && (bool) get_option('firmale_return_enable_store_credit', false)) : ?>
            <div style="max-width:900px;margin-top:20px;padding:20px;border:1px solid #c3c4c7;background:#fff">
                <h2 style="margin-top:0">Poekrediit</h2>
                <?php if ($store_credit_coupon) : ?>
                    <p>Loodud kupong: <strong><?php echo esc_html($store_credit_coupon); ?></strong></p>
                <?php else : ?>
                    <p>Kliendi poekrediidi soov on salvestatud. Krediidi väljastamine vajab eraldi saldopõhise poekrediidi lahenduse ühendust. See plugin ei loo raha asemel sooduskupongi.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($refunds_enabled) : ?>
            <div style="max-width:900px;margin-top:20px;padding:20px;border:1px solid #c3c4c7;background:#fff">
                <h2 style="margin-top:0">Osaline rahatagastus</h2>
                <?php if ($refund_id) : ?>
                    <p><strong>WooCommerce’i tagastuskanne on loodud.</strong> Käsitsi kande puhul kontrolli eraldi raha ülekandmist.</p>
                    <table class="widefat striped">
                        <tbody>
                            <tr><th style="width:220px">WooCommerce’i tagastus</th><td>#<?php echo esc_html($refund_id); ?></td></tr>
                            <tr><th>Summa</th><td><?php echo $order ? wp_kses_post(wc_price($refund_amount, array('currency' => $order->get_currency()))) : esc_html($refund_amount); ?></td></tr>
                            <tr><th>Viis</th><td><?php echo esc_html($refund_mode === 'automatic' ? 'Automaatne makselüüsi kaudu' : 'Käsitsi märgitud'); ?></td></tr>
                            <tr><th>Tehtud</th><td><?php echo esc_html($refunded_at ?: '–'); ?></td></tr>
                        </tbody>
                    </table>
                <?php elseif (is_wp_error($preview_products)) : ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html($preview_products->get_error_message()); ?></p></div>
                <?php elseif (is_array($preview_products)) : ?>
                    <p>
                        Valitud toodete arvutatud summa:
                        <strong data-firmale-product-refund><?php echo wp_kses_post($preview_products['amount_html']); ?></strong>
                        <?php if (is_array($preview_shipping)) : ?>
                            <br>Saatmiskuluga summa: <strong><?php echo wp_kses_post($preview_shipping['amount_html']); ?></strong>
                        <?php endif; ?>
                    </p>
                    <?php if (!$can_process_refunds) : ?>
                        <div class="notice notice-error inline"><p>Sul puudub eraldi rahatagastuse tegemise õigus.</p></div>
                    <?php elseif (!$refund_gate_open) : ?>
                        <div class="notice notice-warning inline"><p>Rahatagastuse turvalukk on aktiivne. Muuda menetluse olekuks esmalt „Kaup saabunud”, „Kaup kontrollimisel” või „Kinnitatud” ja salvesta avaldus.</p></div>
                    <?php endif; ?>
                    <?php if ($first_approver) : $approver = get_userdata($first_approver); ?>
                        <div class="notice notice-info inline"><p>Esimene kinnitus on antud kasutaja <?php echo esc_html($approver ? $approver->display_name : ('#' . $first_approver)); ?> poolt. Lõpetamiseks peab kinnitama teine õigusega kasutaja.</p></div>
                    <?php endif; ?>
                    <p>
                        <label for="firmale_refund_mode"><strong>Tagastusviis</strong></label><br>
                        <select id="firmale_refund_mode" name="firmale_refund_mode">
                            <option value="manual" <?php selected($default_mode, 'manual'); ?>>Käsitsi – loo WooCommerce’i tagastus, raha kannan eraldi</option>
                            <option value="automatic" <?php selected($default_mode, 'automatic'); ?> <?php disabled(!$can_auto_refund); ?>>Automaatne – saada raha algse makseviisi kaudu</option>
                        </select>
                    </p>
                    <?php if (!$can_auto_refund) : ?>
                        <p class="description">Automaatne tagastus ei ole lubatud või tellimuse makseviis ei toeta seda. Käsitsi valik ei kanna raha kliendile.</p>
                    <?php else : ?>
                        <p class="description"><strong>Automaatne valik saadab raha kohe kliendi algsele makseviisile.</strong></p>
                    <?php endif; ?>

                    <?php if ($allow_shipping) : ?>
                        <p>
                            <label>
                                <input type="checkbox" name="firmale_refund_shipping" value="1" <?php checked($shipping_default); ?> <?php disabled(!is_array($preview_shipping)); ?>>
                                Lisa allesjäänud saatmiskulu tagastusele
                            </label>
                        </p>
                    <?php endif; ?>
                    <p>
                        <label>
                            <input type="checkbox" name="firmale_refund_restock" value="1" <?php checked($restock_default); ?>>
                            Lisa tagastatud kogus lattu tagasi
                        </label>
                    </p>
                    <?php if ($can_process_refunds && $refund_gate_open) : ?>
                        <p>
                            <button type="submit" class="button button-primary" name="firmale_process_refund" value="1"
                                    onclick="return window.confirm('Kas oled kindel, et soovid selle rahatagastuse kinnitada? Automaatse valiku korral võidakse raha kohe kliendile saata.');">
                                <?php echo $first_approver ? 'Teine kinnitus ja rahatagastus' : 'Kinnita ja loo rahatagastus'; ?>
                            </button>
                        </p>
                    <?php endif; ?>
                    <p class="description">Topelt-tagastuse kaitse lubab selle avalduse kaudu rahatagastuse teha ainult ühe korra.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ((bool) get_option('firmale_return_enable_audit_log', true) && is_array($audit_log) && $audit_log) : ?>
            <div style="max-width:900px;margin-top:20px">
                <h2>Auditilogi</h2>
                <table class="widefat striped">
                    <thead><tr><th>Aeg</th><th>Kasutaja</th><th>Sündmus</th><th>Kirjeldus</th></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($audit_log) as $entry) : $audit_user = !empty($entry['user_id']) ? get_userdata($entry['user_id']) : null; ?>
                        <tr>
                            <td><?php echo esc_html(isset($entry['time']) ? $entry['time'] : ''); ?></td>
                            <td><?php echo esc_html($audit_user ? $audit_user->display_name : 'Süsteem / klient'); ?></td>
                            <td><?php echo esc_html(isset($entry['event']) ? $entry['event'] : ''); ?></td>
                            <td><?php echo esc_html(isset($entry['message']) ? $entry['message'] : ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php
    }

    public function save_request($post_id)
    {
        if (
            !isset($_POST['firmale_return_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['firmale_return_nonce'])), 'firmale_return_save_request') ||
            !current_user_can('manage_woocommerce') ||
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        ) {
            return;
        }

        $features = Firmale_Return_Features::instance();
        $action_count = (int) !empty($_POST['firmale_process_refund']) + (int) !empty($_POST['firmale_create_store_credit']) + (int) !empty($_POST['firmale_generate_carrier_code']) + (int) !empty($_POST['firmale_reconcile_payment']);
        if ($action_count > 1) { $this->set_refund_notice('error', 'Tee korraga ainult üks toiming.'); return; }
        if (!empty($_POST['firmale_reconcile_payment'])) {
            if (empty($_POST['firmale_reconcile_confirm'])) { $this->set_refund_notice('error', 'Kinnita mõlema süsteemi kontroll.'); return; }
            $result = Firmale_Return_Plugin::instance()->reconcile_refund($post_id, absint($_POST['firmale_reconcile_refund_id'] ?? 0), sanitize_textarea_field(wp_unslash($_POST['firmale_reconcile_evidence'] ?? '')));
            $this->set_refund_notice(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : 'Kontroll salvestati ja tellimuse lukk vabastati.');
            return;
        }
        if (isset($_POST['firmale_received_evidence']) && $features->can_process_refunds()) {
            $evidence = sanitize_textarea_field(wp_unslash($_POST['firmale_received_evidence']));
            if ($evidence !== (string) get_post_meta($post_id, '_firmale_received_evidence', true)) {
                update_post_meta($post_id, '_firmale_received_evidence', $evidence);
                $features->audit($post_id, 'receipt_evidence_changed', 'Kauba saabumise / saatmise tõendi kirjeldust muudeti.');
            }
        }
        if (isset($_POST['firmale_status_message'])) {
            update_post_meta($post_id, '_firmale_status_message', sanitize_textarea_field(wp_unslash($_POST['firmale_status_message'])));
        }
        $old_status = get_post_meta($post_id, '_firmale_status', true) ?: 'new';
        $status = isset($_POST['firmale_return_status'])
            ? sanitize_key(wp_unslash($_POST['firmale_return_status']))
            : '';
        $statuses = $this->status_labels();
        if (isset($statuses[$status])) {
            update_post_meta($post_id, '_firmale_status', $status);
            if ($old_status !== $status) {
                $features->audit($post_id, 'status_changed', sprintf('Olek muudeti: %s → %s.', $old_status, $status));
                $features->send_status_email($post_id, $old_status, $status);
            }
        } else {
            $status = $old_status;
        }

        $logistics_fields = array(
            '_firmale_return_code'         => 'firmale_return_code',
            '_firmale_return_tracking_url' => 'firmale_return_tracking_url',
            '_firmale_return_label_url'    => 'firmale_return_label_url',
        );
        foreach ($logistics_fields as $meta_key => $field_name) {
            if (isset($_POST[$field_name])) {
                $value = wp_unslash($_POST[$field_name]);
                $value = substr($field_name, -4) === '_url' ? esc_url_raw($value) : sanitize_text_field($value);
                update_post_meta($post_id, $meta_key, $value);
            }
        }

        if (!empty($_POST['firmale_generate_carrier_code'])) {
            $carrier_result = $features->request_carrier_code($post_id);
            if (is_wp_error($carrier_result)) {
                $this->set_refund_notice('error', 'Tagastuskoodi loomine ebaõnnestus: ' . $carrier_result->get_error_message());
            } else {
                $this->set_refund_notice('success', 'Vedaja tagastuskood loodi: ' . sanitize_text_field($carrier_result['return_code']));
            }
        }

        if (!empty($_POST['firmale_create_store_credit'])) {
            $credit_result = $features->create_store_credit($post_id);
            if (is_wp_error($credit_result)) {
                $this->set_refund_notice('error', 'Poekrediidi loomine ebaõnnestus: ' . $credit_result->get_error_message());
            } else {
                $this->set_refund_notice('success', 'Poekrediit ' . sanitize_text_field($credit_result['code']) . ' summas ' . wp_strip_all_tags($credit_result['amount_html']) . ' loodi ja saadeti kliendile.');
            }
        }

        if (empty($_POST['firmale_process_refund']) || !(bool) get_option('firmale_return_enable_refunds', true)) {
            return;
        }

        $mode = isset($_POST['firmale_refund_mode'])
            ? sanitize_key(wp_unslash($_POST['firmale_refund_mode']))
            : 'manual';
        if ($mode !== 'automatic') {
            $mode = 'manual';
        }
        if ($mode === 'automatic' && !(bool) get_option('firmale_return_allow_auto_refund', false)) {
            $this->set_refund_notice('error', 'Automaatne rahatagastus on välja lülitatud. Käsitsi kannet ei loodud.');
            return;
        }

        $include_shipping = !empty($_POST['firmale_refund_shipping'])
            && (bool) get_option('firmale_return_allow_shipping_refund', true);
        $restock = !empty($_POST['firmale_refund_restock']);

        $result = Firmale_Return_Plugin::instance()->process_request_refund(
            $post_id,
            $mode,
            $include_shipping,
            $restock
        );

        if (is_wp_error($result)) {
            $this->set_refund_notice('error', 'Rahatagastus ebaõnnestus: ' . $result->get_error_message());
            return;
        }

        if (!empty($result['pending_approval'])) {
            $this->set_refund_notice('success', 'Esimene kinnitus summale ' . wp_strip_all_tags($result['amount_html']) . ' salvestati. Rahatagastuse peab lõpetama teine õigusega administraator.');
            return;
        }

        $message = sprintf(
            '%1$s rahatagastus summas %2$s on loodud.',
            !empty($result['automatic']) ? 'Automaatne' : 'Käsitsi märgitud',
            wp_strip_all_tags($result['amount_html'])
        );
        if (empty($result['automatic'])) {
            $message .= ' Kanna raha kliendile eraldi, sest käsitsi märgitud tagastus ise makset ei liiguta.';
        }
        $features->send_status_email($post_id, $status, !empty($result['automatic']) ? 'completed' : 'manual_payment_due');
        $this->set_refund_notice('success', $message);
    }

    public function columns($columns)
    {
        return array(
            'cb'              => isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox">',
            'title'           => 'Avaldus',
            'firmale_order'   => 'Tellimus',
            'firmale_customer'=> 'Klient',
            'firmale_status'  => 'Olek',
            'date'            => 'Esitatud',
        );
    }

    public function column_content($column, $post_id)
    {
        if ($column === 'firmale_order') {
            $order_id = absint(get_post_meta($post_id, '_firmale_order_id', true));
            $order = $order_id ? wc_get_order($order_id) : null;
            if ($order) {
                echo '<a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a>';
            } else {
                echo '–';
            }
        } elseif ($column === 'firmale_customer') {
            echo esc_html(get_post_meta($post_id, '_firmale_customer_name', true));
            echo '<br><small>' . esc_html(get_post_meta($post_id, '_firmale_customer_email', true)) . '</small>';
        } elseif ($column === 'firmale_status') {
            $status = get_post_meta($post_id, '_firmale_status', true) ?: 'new';
            $labels = $this->status_labels();
            echo esc_html(isset($labels[$status]) ? $labels[$status] : $status);
        }
    }

    public function woocommerce_notice()
    {
        if (!current_user_can('activate_plugins') || class_exists('WooCommerce')) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>Firmale OÜ tagastusvorm:</strong> plugin vajab töötamiseks WooCommerce’i.</p></div>';
    }

    public function refund_notice()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $key = 'firmale_return_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient($key);
        $type = isset($notice['type']) && $notice['type'] === 'success' ? 'success' : 'error';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p><strong>Firmale OÜ tagastusvorm:</strong> ' . esc_html($notice['message']) . '</p></div>';
    }

    public function configuration_notice()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if ((bool) get_option('firmale_return_enable_turnstile', false) && !Firmale_Return_Features::instance()->turnstile_ready()) {
            $url = admin_url('admin.php?page=firmale-return-settings');
            echo '<div class="notice notice-warning"><p><strong>Firmale OÜ tagastusvorm:</strong> Cloudflare Turnstile on sisse lülitatud, kuid site key või secret key puudub. <a href="' . esc_url($url) . '">Ava seaded</a>.</p></div>';
        }
    }

    private function set_refund_notice($type, $message)
    {
        set_transient(
            'firmale_return_notice_' . get_current_user_id(),
            array(
                'type'    => $type === 'success' ? 'success' : 'error',
                'message' => (string) $message,
            ),
            5 * MINUTE_IN_SECONDS
        );
    }

    private function status_labels()
    {
        return Firmale_Return_Features::instance()->status_labels();
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
}
