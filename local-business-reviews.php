<?php
/**
 * Plugin Name: Google Business Profile Reviews for WordPress
 * Description: Connect one authorized Google Business Profile per WordPress site and display its periodically updated rating, recent reviews and business information.
 * Version: 1.3.5
 * Author: Ali Pourgholi
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: ap-local-reviews
 */
if (!defined('ABSPATH')) { exit; }

final class AP_Local_Reviews {
    const VERSION = '1.3.5';
    const SETTINGS = 'aplr_settings';
    const VAULT = 'aplr_vault';
    const DATA = 'aplr_public_cache';
    const CRON = 'aplr_sync';
    const MASK = 'name,title,websiteUri,phoneNumbers,regularHours,metadata';
    const SCOPE = 'https://www.googleapis.com/auth/business.manage';

    public static function boot() {
        add_filter('cron_schedules', array(__CLASS__, 'schedules'));
        add_action(self::CRON, array(__CLASS__, 'scheduled_sync'));
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_aplr_action', array(__CLASS__, 'action'));
        add_action('admin_post_aplr_callback', array(__CLASS__, 'callback'));
        add_action('admin_post_nopriv_aplr_callback', array(__CLASS__, 'logged_out_callback'));
        add_action('init', array(__CLASS__, 'ensure_schedule'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_ajax_aplr_public', array(__CLASS__, 'public_display'));
        add_action('wp_ajax_nopriv_aplr_public', array(__CLASS__, 'public_display'));
        add_action('admin_init', array(__CLASS__, 'privacy'));
        add_shortcode('ap_google_business', array(__CLASS__, 'shortcode'));
        add_shortcode('ap_google_rating', array(__CLASS__, 'rating_shortcode'));
    }
    public static function schedules($s) {
        $s['aplr_quarter_hour'] = array('interval' => 900, 'display' => 'Every 15 minutes (Google Business)');
        return $s;
    }
    public static function ensure_schedule() {
        if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 900, 'aplr_quarter_hour', self::CRON); }
    }
    public static function deactivate() { wp_clear_scheduled_hook(self::CRON); }
    public static function settings() {
        $s = (array) get_option(self::SETTINGS, array());
        // Preserve the colors selected in earlier releases.
        $legacy = array('mason'=>'red', 'shiraz'=>'green', 'neutral'=>'blue');
        if (isset($legacy[$s['preset'] ?? ''])) { $s['preset'] = $legacy[$s['preset']]; }
        return $s;
    }
    private static function put($key, $value) { update_option($key, $value, false); }
    public static function callback_url() { return admin_url('admin-post.php?action=aplr_callback', 'https'); }
    private static function admin_url() { return admin_url('options-general.php?page=ap-local-reviews'); }
    public static function crypto_ready() {
        return function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }
    private static function crypto_key() { return hash('sha256', wp_salt('auth') . wp_salt('secure_auth') . home_url('/'), true); }
    public static function seal($value) {
        if (!self::crypto_ready()) { throw new RuntimeException('OpenSSL AES-256-GCM is required.'); }
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt(wp_json_encode($value), 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) { throw new RuntimeException('Could not encrypt connection credentials.'); }
        return base64_encode($iv . $tag . $cipher);
    }
    public static function unseal($encoded) {
        if (!$encoded) { return array(); }
        if (!self::crypto_ready()) { throw new RuntimeException('OpenSSL AES-256-GCM is required.'); }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) { throw new RuntimeException('Connection data is invalid. Reconnect Google.'); }
        $json = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        $value = $json === false ? null : json_decode($json, true);
        if (!is_array($value)) { throw new RuntimeException('Connection data cannot be decrypted. Re-enter credentials after a site move or WordPress salt change.'); }
        return $value;
    }
    private static function vault() { return self::unseal(get_option(self::VAULT, '')); }
    private static function save_vault($v) { self::put(self::VAULT, self::seal($v)); }
    private static function epoch() { return (string) get_option('aplr_epoch', 'initial'); }
    private static function enum($v, $allowed, $fallback) { return is_string($v) && in_array($v, $allowed, true) ? $v : $fallback; }
    private static function flash($message, $error = false) {
        set_transient('aplr_notice_' . get_current_user_id(), array('message' => $message, 'error' => $error), 120);
    }
    private static function finish($message, $error = false) {
        self::flash($message, $error); wp_safe_redirect(self::admin_url()); exit;
    }
    private static function guard() {
        if (!current_user_can('manage_options')) { wp_die('Administrator access is required.', '', array('response' => 403)); }
    }
    private static function post($name) {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? wp_unslash($_POST[$name]) : '';
    }
    public static function menu() {
        add_options_page('Google Business Profile Reviews', 'Google Business Profile Reviews', 'manage_options', 'ap-local-reviews', array(__CLASS__, 'page'));
    }
    public static function assets() {
        // Local assets only; no external fonts or trackers.
        wp_enqueue_style('ap-local-reviews', plugins_url('assets/reviews.css', __FILE__), array(), self::VERSION);
        wp_enqueue_script('ap-local-reviews', plugins_url('assets/reviews.js', __FILE__), array(), self::VERSION, true);
    }
    public static function privacy() {
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content('Google Business Profile Reviews for WordPress', '<p>This website may display public reviews from its authorized Google Business Profile, including reviewer names, profile photos, review text, dates and business replies. The server connects to Google APIs using administrator-authorized OAuth. The plugin does not send visitor analytics to Google. Displayed photos may load from Google when the reviews become visible. Business and review data are refreshed periodically, cached locally for up to 24 hours, and deleted on disconnect. Connection credentials are encrypted in the WordPress database.</p>');
        }
    }
    private static function form_start($op) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="aplr_action"><input type="hidden" name="op" value="' . esc_attr($op) . '">';
        wp_nonce_field('aplr_' . $op);
    }
    public static function page() {
        self::guard(); $s = self::settings(); $connected = false; $secret_set = false;
        try { $v = self::vault(); $connected = !empty($v['refresh_token']); $secret_set = !empty($v['client_secret']); }
        catch (Exception $e) { echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>'; }
        $notice = get_transient('aplr_notice_' . get_current_user_id());
        if ($notice) { delete_transient('aplr_notice_' . get_current_user_id()); echo '<div class="notice notice-' . ($notice['error'] ? 'error' : 'success') . '"><p>' . esc_html($notice['message']) . '</p></div>'; }
        $cache = self::cache();
        echo '<div class="wrap"><h1>Google Business Profile Reviews</h1><p>Connect one authorized Google Business Profile on any WordPress website. Each installation has its own credentials, selected business and appearance.</p>';
        echo '<p><strong>Connection: </strong>' . ($connected ? 'Google connected' : 'Not connected') . '</p>';
        if (!empty($s['selected']['title'])) { echo '<p><strong>Selected profile: </strong>' . esc_html($s['selected']['title']) . ' &mdash; ' . esc_html($s['selected']['website'] ?? '') . '</p>'; }
        if ($cache) { echo '<p>Last successful sync: ' . esc_html(wp_date('Y-m-d H:i T', $cache['fetched'])) . '. Cached data expires after 24 hours if Google cannot be reached.</p>'; }
        $status = get_option('aplr_status', array());
        if (!empty($status['error'])) { echo '<div class="notice notice-warning inline"><p>' . esc_html($status['error']) . ' &mdash; ' . esc_html(wp_date('Y-m-d H:i T', $status['time'])) . '</p></div>'; }
        echo '<h2>1. Google Cloud credentials</h2><p>Requires approved GBP API access and enabled Account Management, Business Information and Google My Business (reviews) APIs. In Google Cloud create a <strong>Web application</strong> OAuth client.</p><p>Exact authorized redirect URI:</p><p><code>' . esc_html(self::callback_url()) . '</code></p><p>Google only offers the <code>business.manage</code> scope for these endpoints. This plugin reads business information and reviews; it does not edit listings or publish replies.</p>';
        self::form_start('save');
        echo '<table class="form-table"><tr><th><label for="aplr-client">Client ID</label></th><td><input id="aplr-client" class="large-text" name="client_id" value="' . esc_attr($s['client_id'] ?? '') . '" autocomplete="off"></td></tr><tr><th><label for="aplr-secret">Client secret</label></th><td><input id="aplr-secret" type="password" class="regular-text" name="client_secret" value="" autocomplete="new-password"><p class="description">' . ($secret_set ? 'A secret is stored securely. Leave blank to keep it.' : 'Paste the client secret from Google Cloud.') . '</p></td></tr><tr><th>Color preset</th><td><select name="preset">';
        foreach (array('red' => 'Red', 'green' => 'Green', 'blue' => 'Blue') as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($s['preset'] ?? 'blue', $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        foreach (array('style' => array('grid'=>'Classic cards','minimal'=>'Minimal list','spotlight'=>'Spotlight','carousel'=>'Horizontal slider','wall'=>'Review wall'), 'theme'=>array('auto'=>'Automatic / system','light'=>'Light','dark'=>'Dark'), 'layout'=>array('default'=>'Style default','carousel'=>'Carousel for any style'), 'autoplay'=>array('no'=>'Manual carousel','yes'=>'Auto carousel — every 3 seconds')) as $field=>$options) {
            echo '<tr><th>' . esc_html(ucfirst($field)) . '</th><td><select name="' . esc_attr($field) . '">';
            foreach ($options as $key=>$label) { echo '<option value="' . esc_attr($key) . '" ' . selected($s[$field] ?? ($field === 'theme' ? 'auto' : 'grid'), $key, false) . '>' . esc_html($label) . '</option>'; }
            echo '</select></td></tr>';
        }
        echo '<tr><th>Heading</th><td><input class="regular-text" name="heading" value="' . esc_attr($s['heading'] ?? 'What our customers say') . '"></td></tr></table>';
        echo '<p><label>Business logo URL <input type="url" name="logo_url" class="large-text" value="' . esc_attr($s['logo_url'] ?? '') . '"></label></p>';
        submit_button('Save settings'); echo '</form>';
        echo '<h2>2. Connect and select the business</h2>';
        self::form_start('connect'); submit_button($connected ? 'Reconnect Google' : 'Connect Google', 'primary'); echo '</form>';
        if ($connected) {
            self::form_start('discover'); submit_button('Load my business profiles', 'secondary'); echo '</form>';
            $stored = get_transient('aplr_choices_' . get_current_user_id()); $choices = is_array($stored) && ($stored['epoch'] ?? '') === self::epoch() ? $stored['items'] : array();
            if (is_array($choices) && $choices) {
                self::form_start('select'); echo '<select name="choice" required><option value="">Select the correct business for this website</option>';
                foreach ($choices as $key => $choice) { echo '<option value="' . esc_attr($key) . '">' . esc_html($choice['title'] . ' — ' . ($choice['website'] ?: 'No website listed') . ' — ' . $choice['location']) . '</option>'; }
                echo '</select>'; submit_button('Use this profile', 'secondary'); echo '</form>';
            }
            self::form_start('refresh'); submit_button('Sync now', 'secondary'); echo '</form>';
            self::form_start('disconnect'); echo '<p>Disconnect revokes this site’s Google token and deletes locally cached Google data. It does not delete the Google Business Profile.</p>'; submit_button('Disconnect and remove cached data', 'delete'); echo '</form>';
        }
        echo '<p><a href="' . esc_url(admin_url('options-general.php?page=aplr-matching')) . '">Configure reviews for each page / post</a></p>';
        echo '<h2>3. Display on your site</h2><p>Full review section: <code>[ap_google_business count="6" info="yes"]</code></p><p>Choose style: <code>grid</code>, <code>minimal</code>, <code>spotlight</code>, <code>carousel</code>, <code>wall</code>. Theme: <code>auto</code>, <code>light</code>, <code>dark</code>.</p><p>Example: <code>[ap_google_business style="carousel" theme="auto" count="9" info="yes"]</code></p><p>Any style can use carousel layout: <code>[ap_google_business style="minimal" layout="carousel" autoplay="yes"]</code>.</p><p>Automatic carousel every 3 seconds: <code>[ap_google_business style="carousel" autoplay="yes"]</code>. Pauses on hover, touch, keyboard focus and when offscreen. Reduced-motion preferences disable automatic movement.</p><p>Compact rating badge: <code>[ap_google_rating]</code></p><p>Use a Shortcode block, Jannah shortcode element, or Impreza Text block. No data appears until a profile is selected and successfully synced. Latest reviews are displayed in Google’s update order, without filtering by star rating.</p><p>WP-Cron checks every 15 minutes when the site receives traffic. For reliable scheduling on quiet sites, configure a real server cron to run WordPress scheduled events. The plugin adds no review or aggregate-rating structured data.</p></div>';
    }
    public static function action() {
        self::guard(); $op = sanitize_key(self::post('op')); check_admin_referer('aplr_' . $op);
        try {
            if ($op === 'save') {
                $s = self::settings(); $id = trim(self::post('client_id')); $secret = trim(self::post('client_secret'));
                if ($id && !preg_match('/^[a-zA-Z0-9._-]+\.apps\.googleusercontent\.com$/D', $id)) { throw new RuntimeException('Enter a valid Google OAuth Client ID.'); }
                $v = self::vault();
                if (($s['client_id'] ?? '') !== $id) {
                    if (!$secret && $id) { throw new RuntimeException('Provide the matching client secret when changing Client ID.'); }
                    self::revoke($v); $v = array(); unset($s['selected']); self::clear_data();
                } elseif ($secret && $secret !== ($v['client_secret'] ?? '')) {
                    self::revoke($v); $v = array(); unset($s['selected']); self::clear_data();
                }
                if ($secret) { $v['client_secret'] = $secret; }
                self::save_vault($v); $s['client_id'] = $id;
                $preset = self::post('preset'); $s['preset'] = in_array($preset, array('red', 'green', 'blue'), true) ? $preset : 'blue';
                $s['heading'] = sanitize_text_field(self::post('heading'));
                $s['logo_url'] = esc_url_raw(self::post('logo_url'), array('https')); 
                $s['style'] = self::enum(self::post('style'), array('grid','minimal','spotlight','carousel','wall'), 'grid');
                $s['layout'] = self::post('layout') === 'carousel' ? 'carousel' : 'default';
                $s['autoplay'] = self::post('autoplay') === 'yes' ? 'yes' : 'no';
                $s['theme'] = self::enum(self::post('theme'), array('auto','light','dark'), 'auto');
                self::put(self::SETTINGS, $s); self::finish('Settings saved.');
            }
            if ($op === 'connect') { self::connect(); }
            if ($op === 'discover') {
                $choices = self::discover(); set_transient('aplr_choices_' . get_current_user_id(), array('epoch'=>self::epoch(), 'items'=>$choices), 900);
                self::finish($choices ? 'Profiles loaded. Select the correct business below.' : 'No accessible profiles found. Check the Google account and its manager invitation.');
            }
            if ($op === 'select') {
                $stored = get_transient('aplr_choices_' . get_current_user_id()); $choices = is_array($stored) && ($stored['epoch'] ?? '') === self::epoch() ? $stored['items'] : array(); $key = self::post('choice');
                if (!is_array($choices) || !isset($choices[$key])) { throw new RuntimeException('Reload your business profiles before selecting one.'); }
                $s = self::settings(); self::clear_data(); $s['selected'] = $choices[$key]; self::put(self::SETTINGS, $s);
                self::sync(); self::finish('Business selected and synced.');
            }
            if ($op === 'refresh') { self::sync(); self::finish('Google data refreshed.'); }
            if ($op === 'disconnect') {
                $v = self::vault(); $failed = !self::revoke($v);
                $secret = $v['client_secret'] ?? ''; self::save_vault($secret ? array('client_secret' => $secret) : array());
                $s = self::settings(); unset($s['selected']); self::put(self::SETTINGS, $s); self::clear_data();
                delete_transient('aplr_state_' . get_current_user_id());
                self::finish($failed ? 'Local connection and cached data removed. Google revocation could not be confirmed; remove the app under your Google Account > Third-party connections.' : 'Disconnected. Google access revoked and local cached data removed.', $failed);
            }
            throw new RuntimeException('Unknown action.');
        } catch (Exception $e) {
            if (in_array($op, array('select', 'refresh'), true)) { self::record_error($e); }
            self::finish($e->getMessage(), true);
        }
    }
    private static function b64url($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private static function connect() {
        $s = self::settings(); $v = self::vault();
        if (empty($s['client_id']) || empty($v['client_secret'])) { throw new RuntimeException('Save Client ID and client secret first.'); }
        if (wp_parse_url(home_url('/'), PHP_URL_SCHEME) !== 'https') { throw new RuntimeException('HTTPS is required for Google connections.'); }
        $state = self::b64url(random_bytes(32)); $verifier = self::b64url(random_bytes(48));
        set_transient('aplr_state_' . get_current_user_id(), array('state' => $state, 'verifier' => $verifier, 'client' => $s['client_id'], 'epoch'=>self::epoch()), 600);
        $args = array('client_id' => $s['client_id'], 'redirect_uri' => self::callback_url(), 'response_type' => 'code', 'scope' => self::SCOPE, 'access_type' => 'offline', 'prompt' => 'consent select_account', 'state' => $state, 'code_challenge' => self::b64url(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256');
        wp_redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986)); exit;
    }
    public static function logged_out_callback() {
        nocache_headers(); wp_die('Sign in to WordPress as the administrator who started the connection, then start Connect Google again. This authorization code was not used.', 'Connection requires administrator login', array('response' => 403));
    }
    public static function callback() {
        nocache_headers(); header('Referrer-Policy: no-referrer'); self::guard();
        try {
            $pending = get_transient('aplr_state_' . get_current_user_id());
            $state = isset($_GET['state']) && is_string($_GET['state']) ? wp_unslash($_GET['state']) : '';
            if (!$pending || ($pending['epoch'] ?? '') !== self::epoch() || !$state || !hash_equals($pending['state'], $state) || $pending['client'] !== (self::settings()['client_id'] ?? '')) { throw new RuntimeException('Connection state expired or invalid. Start Connect Google again.'); }
            // Consume once, only after matching the initiating administrator and client.
            delete_transient('aplr_state_' . get_current_user_id());
            if (isset($_GET['error'])) { throw new RuntimeException('Google authorization was cancelled or denied.'); }
            $code = isset($_GET['code']) && is_string($_GET['code']) ? wp_unslash($_GET['code']) : '';
            if (!$code) { throw new RuntimeException('Google returned no authorization code.'); }
            $s = self::settings(); $v = self::vault();
            $token = self::token_request(array('client_id' => $s['client_id'], 'client_secret' => $v['client_secret'], 'code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => self::callback_url(), 'code_verifier' => $pending['verifier']));
            if (empty($token['refresh_token'])) { throw new RuntimeException('Google did not return offline access. Start Connect Google again and approve access.'); }
            if (!empty($token['scope']) && !in_array(self::SCOPE, explode(' ', $token['scope']), true)) { throw new RuntimeException('Google Business access was not granted.'); }
            $new = array('client_secret' => $v['client_secret'], 'refresh_token' => $token['refresh_token'], 'access_token' => $token['access_token'], 'expires' => time() + (int) ($token['expires_in'] ?? 3600));
            if (($pending['epoch'] ?? '') !== self::epoch()) { throw new RuntimeException('Connection changed during authorization. Start again.'); }
            self::save_vault($new); unset($s['selected']); self::put(self::SETTINGS, $s); self::clear_data();
            self::finish('Google connected. Load your business profiles and select the correct one for this site.');
        } catch (Exception $e) { self::finish($e->getMessage(), true); }
    }
    private static function token_request($body) {
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array('timeout' => 20, 'redirection' => 0, 'body' => $body));
        if (is_wp_error($response)) { throw new RuntimeException('Could not contact Google authorization server.'); }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || !is_array($data) || empty($data['access_token'])) {
            $reason = is_array($data) ? ($data['error'] ?? '') : '';
            if ($reason === 'invalid_grant' && ($body['grant_type'] ?? '') === 'refresh_token') {
                $v = self::vault();
                if (($v['refresh_token'] ?? '') === ($body['refresh_token'] ?? null)) { unset($v['access_token'], $v['refresh_token'], $v['expires']); self::save_vault($v); self::clear_data(); }
            }
            throw new RuntimeException($reason === 'invalid_client' ? 'Google rejected the Client ID or secret. Check the Web application credentials.' : 'Google authorization failed. Reconnect Google; if the app is in Testing, refresh tokens may expire after seven days.');
        }
        if (!is_string($data['access_token']) || (isset($data['refresh_token']) && !is_string($data['refresh_token']))) { throw new RuntimeException('Google returned invalid authorization data.'); }
        return $data;
    }
    private static function access_token($force = false) {
        $epoch = self::epoch(); $v = self::vault(); $s = self::settings();
        if (!$force && !empty($v['access_token']) && ($v['expires'] ?? 0) > time() + 90) { return $v['access_token']; }
        if (empty($v['refresh_token']) || empty($v['client_secret']) || empty($s['client_id'])) { throw new RuntimeException('Connect Google first.'); }
        $r = self::token_request(array('client_id' => $s['client_id'], 'client_secret' => $v['client_secret'], 'refresh_token' => $v['refresh_token'], 'grant_type' => 'refresh_token'));
        $v['access_token'] = $r['access_token']; $v['expires'] = time() + (int) ($r['expires_in'] ?? 3600);
        if (!empty($r['refresh_token'])) { $v['refresh_token'] = $r['refresh_token']; }
        if (self::epoch() !== $epoch) { throw new RuntimeException('Connection changed. Please retry.'); }
        self::save_vault($v); return $v['access_token'];
    }
    private static function revoke($v) {
        $token = $v['refresh_token'] ?? ($v['access_token'] ?? '');
        if (!$token) { return true; }
        $r = wp_remote_post('https://oauth2.googleapis.com/revoke', array('timeout' => 15, 'redirection' => 0, 'body' => array('token' => $token)));
        return !is_wp_error($r) && in_array(wp_remote_retrieve_response_code($r), array(200, 400), true);
    }
    public static function api($service, $path, $query = array()) {
        $bases = array('accounts' => 'https://mybusinessaccountmanagement.googleapis.com/v1/', 'info' => 'https://mybusinessbusinessinformation.googleapis.com/v1/', 'reviews' => 'https://mybusiness.googleapis.com/v4/');
        if (!isset($bases[$service]) || !preg_match('~^(?:accounts(?:/[A-Za-z0-9_-]+)?(?:/locations(?:/[A-Za-z0-9_-]+)?(?:/reviews)?)?|locations/[A-Za-z0-9_-]+)$~D', $path)) { throw new RuntimeException('Invalid Google API resource.'); }
        $url = $bases[$service] . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $r = wp_remote_get($url, array('timeout' => 20, 'redirection' => 0, 'headers' => array('Authorization' => 'Bearer ' . self::access_token($attempt === 1), 'Accept' => 'application/json')));
            if (is_wp_error($r)) { throw new RuntimeException('Google connection failed. Check server outbound HTTPS access.'); }
            $status = wp_remote_retrieve_response_code($r);
            if ($status === 401 && $attempt === 0) { continue; }
            $data = json_decode(wp_remote_retrieve_body($r), true);
            if ($status >= 200 && $status < 300 && is_array($data)) { return $data; }
            if ($status === 403) { throw new RuntimeException('Google denied access (403). Check GBP API approval, enabled APIs, and the connected account’s access to this business.'); }
            if ($status === 429) { throw new RuntimeException('Google quota is unavailable or exhausted (429). A zero quota requires GBP API approval.'); }
            if ($status === 404) { throw new RuntimeException('Google business resource was not found (404). Load profiles and select it again.'); }
            throw new RuntimeException('Google API returned HTTP ' . (int) $status . '. Retry later or reconnect Google.');
        }
        throw new RuntimeException('Google authorization failed.');
    }
    private static function pages($service, $path, $field, $query = array()) {
        $rows = array(); $seen = array(); $token = '';
        for ($page = 0; $page < 100; $page++) {
            if ($token) { $query['pageToken'] = $token; }
            $r = self::api($service, $path, $query);
            if (isset($r[$field]) && is_array($r[$field])) { $rows = array_merge($rows, $r[$field]); }
            $token = $r['nextPageToken'] ?? '';
            if (!$token) { return $rows; }
            if (isset($seen[$token])) { throw new RuntimeException('Google returned a repeated page token. Retry loading profiles.'); }
            $seen[$token] = true;
        }
        throw new RuntimeException('Profile list exceeded the safe page limit. Use a Google account with access only to the required businesses.');
    }
    private static function discover() {
        $accounts = self::pages('accounts', 'accounts', 'accounts', array('pageSize' => 20)); $choices = array();
        foreach ($accounts as $account) {
            $name = $account['name'] ?? '';
            if (!preg_match('~^accounts/[A-Za-z0-9_-]+$~D', $name)) { continue; }
            $locations = self::pages('info', $name . '/locations', 'locations', array('readMask' => 'name,title,websiteUri', 'pageSize' => 100));
            foreach ($locations as $location) {
                $loc = $location['name'] ?? '';
                if (!preg_match('~^locations/[A-Za-z0-9_-]+$~D', $loc)) { continue; }
                $choices[hash('sha256', $name . '/' . $loc)] = array('account' => $name, 'location' => $loc, 'title' => sanitize_text_field($location['title'] ?? ''), 'website' => esc_url_raw($location['websiteUri'] ?? '', array('https', 'http')));
            }
        }
        return $choices;
    }
    private static function clear_data() {
        self::put('aplr_epoch', wp_generate_uuid4());
        delete_transient(self::DATA); delete_transient('aplr_choices_' . get_current_user_id()); delete_option('aplr_status');
    }
    public static function cache() {
        $data = get_transient(self::DATA); $s = self::settings();
        if (!is_array($data) || empty($s['selected']) || ($data['location'] ?? '') !== $s['selected']['location'] || ($data['account'] ?? '') !== $s['selected']['account'] || empty($data['fetched']) || time() - $data['fetched'] >= DAY_IN_SECONDS) { return array(); }
        return $data;
    }
    public static function sync() {
        $epoch = self::epoch(); $s = self::settings(); $chosen = $s['selected'] ?? array();
        if (empty($chosen['account']) || empty($chosen['location'])) { throw new RuntimeException('Select a business profile first.'); }
        $lock = get_option('aplr_sync_lock');
        if ($lock && time() - (int) $lock > 300) { delete_option('aplr_sync_lock'); }
        if (!add_option('aplr_sync_lock', time(), '', false)) { throw new RuntimeException('A sync is already running. Try again shortly.'); }
        try {
            $location = self::api('info', $chosen['location'], array('readMask' => self::MASK));
            $reviews = self::api('reviews', $chosen['account'] . '/' . $chosen['location'] . '/reviews', array('pageSize' => 50, 'orderBy' => 'updateTime desc'));
            if ($reviews === array()) { $reviews = array('totalReviewCount'=>0, 'reviews'=>array()); }
            if (!isset($reviews['totalReviewCount']) || (!isset($reviews['averageRating']) && (int) $reviews['totalReviewCount'] > 0)) { throw new RuntimeException('Google returned incomplete review totals; existing cache was not replaced.'); }
            if (!is_array($location) || (isset($reviews['reviews']) && !is_array($reviews['reviews'])) || !is_numeric($reviews['totalReviewCount']) || (int)$reviews['totalReviewCount'] < 0 || (isset($reviews['averageRating']) && (!is_numeric($reviews['averageRating']) || $reviews['averageRating'] < 0 || $reviews['averageRating'] > 5))) { throw new RuntimeException('Google returned invalid review data.'); }
            // Load a bounded, paginated review inventory without losing Google's original aggregate.
            $rows = (array)($reviews['reviews'] ?? array()); $next = $reviews['nextPageToken'] ?? ''; $seen = array();
            for ($page = 1; $next && $page < 10; $page++) {
                if (!is_string($next) || isset($seen[$next])) { throw new RuntimeException('Google returned an invalid or repeated review page token.'); }
                $seen[$next] = true;
                $more = self::api('reviews', $chosen['account'] . '/' . $chosen['location'] . '/reviews', array('pageSize'=>50, 'orderBy'=>'updateTime desc', 'pageToken'=>$next));
                if (isset($more['reviews']) && !is_array($more['reviews'])) { throw new RuntimeException('Google returned an invalid review page.'); }
                $rows = array_merge($rows,(array)($more['reviews'] ?? array()));
                $next = $more['nextPageToken'] ?? '';
            }
            $unique = array(); foreach ($rows as $row) { if (!is_array($row)) { throw new RuntimeException('Google returned invalid review content.'); } $unique[APLR_Matching::id($row)] = $row; }
            $reviews['reviews'] = array_slice(array_values($unique),0,500);
            // Keep the original Google content and aggregate rating; never infer totals from this recent page.
            $data = array('truncated' => !empty($next), 'fetched' => time(), 'account' => $chosen['account'], 'location' => $chosen['location'], 'business' => $location, 'reviews_response' => $reviews);
            // A disconnect/profile change while HTTP requests run must not restore an old connection’s data.
            if (self::epoch() !== $epoch || (self::settings()['selected'] ?? array()) !== $chosen) { throw new RuntimeException('Selected business changed during sync. Retry.'); }
            set_transient(self::DATA, $data, DAY_IN_SECONDS);
            self::put('aplr_status', array('time' => time(), 'error' => ''));
        } finally { delete_option('aplr_sync_lock'); }
    }
    private static function record_error($e) { self::put('aplr_status', array('time' => time(), 'error' => sanitize_text_field($e->getMessage()))); }
    public static function scheduled_sync() {
        if (empty(self::settings()['selected'])) { return; }
        try { self::sync(); } catch (Exception $e) { self::record_error($e); }
    }
    private static function stars($number) {
        $n = max(0, min(5, (float) $number));
        return '<span class="aplr-stars" role="img" aria-label="' . esc_attr(number_format_i18n($n, 1) . ' out of 5 stars') . '">' . str_repeat('★', (int) round($n)) . str_repeat('☆', 5 - (int) round($n)) . '</span>';
    }
    private static function empty_display() { return current_user_can('manage_options') ? '<p class="aplr-setup">Google reviews are not available yet. Check Settings → Google Business Profile Reviews.</p>' : ''; }
    public static function rating_shortcode($atts = array()) { return self::shortcode(array_merge((array)$atts, array('compact' => 'yes'))); }
    public static function public_display() {
        nocache_headers(); header('Cache-Control: no-store, max-age=0');
        $args = array();
        foreach (array('count','info','compact','style','theme','autoplay','layout','related','page_id') as $key) { if (isset($_POST[$key]) && is_string($_POST[$key])) { $args[$key] = sanitize_text_field(wp_unslash($_POST[$key])); } }
        // Public endpoint exposes only the already selected, fresh public review cache. No Google request.
        $data = self::cache();
        wp_send_json_success(array('html'=>self::render($args), 'ttl'=>300, 'expires'=>$data ? $data['fetched'] + DAY_IN_SECONDS : 0));
    }
    public static function shortcode($atts = array()) {
        $atts = (array)$atts;
        if (!isset($atts['page_id']) && is_singular(array('page','post'))) { $atts['page_id'] = get_queried_object_id(); }
        $args = self::display_args($atts);
        $data = self::cache();
        return '<div data-aplr-expires="' . esc_attr($data ? $data['fetched'] + DAY_IN_SECONDS : 0) . '" class="aplr-mount" data-aplr-endpoint="' . esc_url(admin_url('admin-ajax.php')) . '" data-aplr-options="' . esc_attr(wp_json_encode($args)) . '" aria-live="polite">' . self::render($args) . '</div>';
    }
    private static function display_args($atts) {
        $s = self::settings();
        $a = shortcode_atts(array('count'=>6,'info'=>'no','compact'=>'no','style'=>$s['style'] ?? 'grid','theme'=>$s['theme'] ?? 'auto','autoplay'=>$s['autoplay'] ?? 'no','layout'=>$s['layout'] ?? 'default','related'=>'auto','page_id'=>0), $atts, 'ap_google_business');
        $a['style'] = self::enum($a['style'], array('grid','minimal','spotlight','carousel','wall'), 'grid');
        $a['theme'] = self::enum($a['theme'], array('auto','light','dark'), 'auto');
        $a['layout'] = $a['layout'] === 'carousel' || $a['style'] === 'carousel' ? 'carousel' : 'default';
        $a['autoplay'] = $a['layout'] === 'carousel' && $a['autoplay'] === 'yes' ? 'yes' : 'no';
        $a['page_id'] = absint($a['page_id']);
        $a['related'] = $a['related'] === 'no' ? 'no' : 'auto';
        $a['count'] = max(1,min(50,(int)$a['count']));
        $a['info'] = $a['info'] === 'yes' ? 'yes' : 'no';
        $a['compact'] = $a['compact'] === 'yes' ? 'yes' : 'no';
        return $a;
    }
    public static function render($atts = array()) {
        $a = self::display_args($atts);
        $data = self::cache(); if (!$data) { return self::empty_display(); }
        $s = self::settings(); $b = $data['business']; $r = $data['reviews_response'];
        $rating = (float) ($r['averageRating'] ?? 0); $total = max(0, (int) ($r['totalReviewCount'] ?? 0));
        $maps = esc_url($b['metadata']['mapsUri'] ?? '', array('https')); $write = esc_url($b['metadata']['newReviewUri'] ?? '', array('https'));
        $preset = in_array($s['preset'] ?? '', array('red','green','blue'), true) ? $s['preset'] : 'blue';
        $compact = $a['compact'] === 'yes'; $match_mode = '';
        if (!$compact && $a['related'] !== 'no' && $a['page_id']) {
            $rule = APLR_Matching::rule($a['page_id']);
            if ($rule) {
                $match = APLR_Matching::choose((array)($r['reviews'] ?? array()), $rule);
                if (!$match['reviews']) { return ''; }
                $r['reviews'] = $match['reviews']; $match_mode = $match['mode'];
            }
        }
        ob_start(); ?>
        <section class="aplr aplr--<?php echo esc_attr($preset); ?> aplr-style--<?php echo esc_attr($a['style']); ?> aplr-theme--<?php echo esc_attr($a['theme']); ?> aplr-layout--<?php echo esc_attr($a['layout']); ?> <?php echo $compact ? 'aplr--compact' : ''; ?>" data-aplr-autoplay="<?php echo esc_attr($a['autoplay']); ?>" aria-label="Google reviews" lang="en" dir="ltr">
            <div class="aplr-summary">
                <div><?php $logo = esc_url($s['logo_url'] ?? wp_get_attachment_image_url(get_theme_mod('custom_logo'), 'medium'), array('https')); if ($logo): ?><img class="aplr-business-logo" src="<?php echo $logo; ?>" alt="<?php echo esc_attr($b['title'] ?? 'Business logo'); ?>" width="140" height="64" decoding="async"><?php endif; ?><span class="aplr-source">Reviews on Google</span><?php if (!$compact): ?><h2><?php echo esc_html(($s['heading'] ?? '') ?: 'What our customers say'); ?></h2><?php endif; ?>

                </div>
                <div class="aplr-actions"><?php if ($maps): ?><a class="aplr-button aplr-button--outline" href="<?php echo $maps; ?>" target="_blank" rel="noopener noreferrer">Read Reviews</a><?php endif; ?><?php if ($write && !$compact): ?><a class="aplr-button" href="<?php echo $write; ?>" target="_blank" rel="noopener noreferrer">Write a Review</a><?php endif; ?></div>
            </div>
            <div class="aplr-summary-bottom">
                <div class="aplr-business-stats"><?php if (!$compact): ?><p class="aplr-business-name"><?php echo esc_html($b['title'] ?? $s['selected']['title']); ?></p><?php endif; ?>
                    <div class="aplr-rating"><?php if ($total): ?><strong><?php echo esc_html(number_format_i18n($rating, 1)); ?></strong><?php echo self::stars($rating); ?><?php endif; ?><span><?php echo esc_html(number_format_i18n($total)); ?> Google reviews</span></div>
                </div>
                <?php if (!$compact && $a['layout'] === 'carousel'): ?><div class="aplr-navigation"><button type="button" data-aplr-move="-1" aria-label="Previous reviews">←</button><button type="button" data-aplr-move="1" aria-label="Next reviews">→</button></div><?php endif; ?>
            </div>
            <?php if (!$compact): ?>
            <?php if ($match_mode): ?><p class="aplr-match-note"><?php echo $match_mode === 'related' ? 'Reviews matching this page’s service. Rating and total above are for the entire business.' : 'General feedback about this business. Rating and total above are for the entire business.'; ?></p><?php endif; ?>

            <div class="aplr-grid" <?php echo $a['layout'] === 'carousel' ? 'tabindex="0" aria-label="Scrollable reviews"' : ''; ?>>
            <?php $values = array('ONE'=>1,'TWO'=>2,'THREE'=>3,'FOUR'=>4,'FIVE'=>5); foreach (array_slice((array) ($r['reviews'] ?? array()), 0, $a['count']) as $review):
                $author = $review['reviewer'] ?? array(); $name = $author['displayName'] ?? 'Google reviewer'; $photo = esc_url($author['profilePhotoUrl'] ?? '', array('https')); $date = $review['createTime'] ?? ''; $ts = strtotime($date); ?>
                <article class="aplr-card"><header><?php if ($photo): ?><img src="<?php echo $photo; ?>" width="44" height="44" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"><?php endif; ?><div><strong><?php echo esc_html($name); ?></strong><?php if ($ts): ?><time datetime="<?php echo esc_attr(gmdate('c', $ts)); ?>"><?php echo esc_html(wp_date('M j, Y', $ts)); ?></time><?php endif; ?></div><span class="aplr-google-label" role="img" aria-label="Google"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.39-.18-2.05H12v3.88h5.38a4.6 4.6 0 0 1-2 3.01v2.51h3.24c1.89-1.74 2.98-4.3 2.98-7.35z"/><path fill="#34A853" d="M12 22c2.7 0 4.96-.9 6.62-2.42l-3.24-2.51c-.9.6-2.04.96-3.38.96-2.6 0-4.8-1.76-5.59-4.12H3.07v2.59A10 10 0 0 0 12 22z"/><path fill="#FBBC05" d="M6.41 13.91A6 6 0 0 1 6.1 12c0-.66.11-1.3.51-1.91V7.5H3.07A10 10 0 0 0 2 12c0 1.61.39 3.14 1.07 4.5l3.34-2.59z"/><path fill="#EA4335" d="M12 5.97c1.47 0 2.79.51 3.83 1.51l2.87-2.87A9.62 9.62 0 0 0 12 2a10 10 0 0 0-8.93 5.5l3.34 2.59C7.2 7.73 9.4 5.97 12 5.97z"/></svg></span></header>
                    <?php echo self::stars($values[$review['starRating'] ?? ''] ?? 0); ?>
                    <?php if (!empty($review['comment'])): ?><div class="aplr-review-text" dir="auto"><?php echo nl2br(esc_html($review['comment'])); ?></div><?php else: ?><p class="aplr-muted">Rating without a written review.</p><?php endif; ?>
                    <?php if (!empty($review['reviewReply']['comment'])): ?><details class="aplr-reply"><summary>Response from the business</summary><div><?php echo nl2br(esc_html($review['reviewReply']['comment'])); ?></div></details><?php endif; ?>
                </article>
            <?php endforeach; ?>
            </div>
            <?php if ($a['info'] === 'yes' && $a['layout'] !== 'carousel'): ?><div class="aplr-info"><?php if (!empty($b['phoneNumbers']['primaryPhone'])): $phone = $b['phoneNumbers']['primaryPhone']; ?><p><strong>Call us</strong><a href="tel:<?php echo esc_attr(preg_replace('/[^+0-9]/', '', $phone)); ?>"><?php echo esc_html($phone); ?></a></p><?php endif; ?>
                <?php if (!empty($b['regularHours']['periods'])): ?><details><summary>Regular business hours</summary><ul><?php foreach ($b['regularHours']['periods'] as $period): ?><li><?php echo esc_html(self::hours($period)); ?></li><?php endforeach; ?></ul><small>Holiday hours may differ. Check our Google profile.</small></details><?php endif; ?></div><?php endif; ?>
            <p class="aplr-updated">Recent reviews from Google · Updated <?php echo esc_html(wp_date('M j, Y', $data['fetched'])); ?></p>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }
    public static function hours($p) {
        $open = sprintf('%02d:%02d', (int) ($p['openTime']['hours'] ?? 0), (int) ($p['openTime']['minutes'] ?? 0));
        $close = sprintf('%02d:%02d', (int) ($p['closeTime']['hours'] ?? 0), (int) ($p['closeTime']['minutes'] ?? 0));
        $day = ucfirst(strtolower($p['openDay'] ?? '')); $end = ucfirst(strtolower($p['closeDay'] ?? ''));
        if ($open === '00:00' && $close === '24:00') { return $day . ': Open 24 hours'; }
        return $day . ': ' . $open . '–' . ($end !== $day ? $end . ' ' : '') . $close;
    }
}
require_once __DIR__ . '/includes/matching.php';
AP_Local_Reviews::boot();
register_activation_hook(__FILE__, array('AP_Local_Reviews', 'ensure_schedule'));
register_deactivation_hook(__FILE__, array('AP_Local_Reviews', 'deactivate'));

