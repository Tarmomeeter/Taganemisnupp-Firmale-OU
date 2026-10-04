<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Firmale_Return_Updater
{
    const OWNER = 'Tarmomeeter';
    const REPOSITORY = 'Taganemisnupp-Firmale-OU';
    const UPDATE_URI = 'https://github.com/Tarmomeeter/Taganemisnupp-Firmale-OU';
    const ASSET_NAME = 'firmale-taganemisvorm.zip';
    const CACHE_KEY = 'firmale_return_github_release';

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
        add_filter('update_plugins_github.com', array($this, 'check_for_update'), 10, 4);
        add_filter('plugins_api', array($this, 'plugin_information'), 20, 3);
        add_filter('http_request_args', array($this, 'authenticate_asset_download'), 10, 2);
    }

    public function check_for_update($update, $plugin_data, $plugin_file, $locales)
    {
        unset($locales);

        if ($plugin_file !== plugin_basename(FIRMALE_RETURN_FILE)) {
            return $update;
        }

        $release = $this->latest_release();
        if (is_wp_error($release)) {
            return false;
        }

        $version = ltrim((string) $release['tag_name'], 'vV');
        if (!$version || version_compare($version, (string) $plugin_data['Version'], '<=')) {
            return false;
        }

        return array(
            'slug'         => dirname(plugin_basename(FIRMALE_RETURN_FILE)),
            'version'      => $version,
            'new_version'  => $version,
            'url'          => self::UPDATE_URI,
            'package'      => $this->package_url($release),
            'requires_php' => isset($plugin_data['RequiresPHP']) ? $plugin_data['RequiresPHP'] : '7.4',
            'tested'       => get_bloginfo('version'),
        );
    }

    public function plugin_information($result, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== dirname(plugin_basename(FIRMALE_RETURN_FILE))) {
            return $result;
        }

        $release = $this->latest_release();
        if (is_wp_error($release)) {
            return $result;
        }

        $version = ltrim((string) $release['tag_name'], 'vV');
        return (object) array(
            'name'          => 'Firmale OÜ – WooCommerce taganemisvorm',
            'slug'          => dirname(plugin_basename(FIRMALE_RETURN_FILE)),
            'version'       => $version,
            'author'        => 'Firmale OÜ',
            'homepage'      => self::UPDATE_URI,
            'requires'      => '6.4',
            'requires_php'  => '7.4',
            'download_link' => $this->package_url($release),
            'sections'      => array(
                'description' => 'WooCommerce’i tellimustega seotud taganemisavalduste turvaline vastuvõtt ja menetlemine.',
                'changelog'   => !empty($release['body']) ? wp_kses_post(wpautop($release['body'])) : 'Vaata muudatusi GitHub Releases lehelt.',
            ),
        );
    }

    public function authenticate_asset_download($args, $url)
    {
        $asset_prefix = sprintf(
            'https://api.github.com/repos/%s/%s/releases/assets/',
            rawurlencode(self::OWNER),
            rawurlencode(self::REPOSITORY)
        );

        if (strpos($url, $asset_prefix) !== 0) {
            return $args;
        }

        $token = $this->token();
        if (!$token) {
            return $args;
        }

        $args['headers']['Authorization'] = 'Bearer ' . $token;
        $args['headers']['Accept'] = 'application/octet-stream';
        $args['headers']['X-GitHub-Api-Version'] = '2022-11-28';
        return $args;
    }

    private function latest_release()
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached) && !empty($cached['tag_name'])) {
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            rawurlencode(self::OWNER),
            rawurlencode(self::REPOSITORY)
        );
        $headers = array(
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        );
        $token = $this->token();
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 15,
        ));
        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $release = json_decode(wp_remote_retrieve_body($response), true);
        if ($status !== 200 || !is_array($release) || empty($release['tag_name'])) {
            return new WP_Error('firmale_github_release', 'GitHubi uusima versiooni infot ei õnnestunud laadida.');
        }

        set_site_transient(self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS);
        return $release;
    }

    private function package_url($release)
    {
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!empty($asset['name']) && $asset['name'] === self::ASSET_NAME && !empty($asset['url'])) {
                    return esc_url_raw($asset['url']);
                }
            }
        }

        return !empty($release['zipball_url']) ? esc_url_raw($release['zipball_url']) : '';
    }

    private function token()
    {
        $token = defined('FIRMALE_GITHUB_TOKEN') ? FIRMALE_GITHUB_TOKEN : '';
        return trim((string) apply_filters('firmale_github_token', $token));
    }
}
