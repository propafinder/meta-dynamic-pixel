<?php
if (!defined('ABSPATH')) exit;

/**
 * Атрибуция: подключает JS, который запоминает UTM-метки, реферер, origin лида,
 * fbclid -> _fbc, и отдаёт всё это в cookie, чтобы сервер (CAPI) тоже это видел.
 */
class MDP_Attribution {

    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue'), 1);
    }

    public function enqueue() {
        wp_register_script('mdp-attribution', MDP_URL . 'assets/js/attribution.js', array(), MDP_VERSION, false);
        wp_localize_script('mdp-attribution', 'MDP_ATTR', array(
            'days' => intval(mdp_get('attribution_days', 90)),
        ));
        wp_localize_script('mdp-attribution', 'MDP_LOG', array(
            'enabled' => (mdp_get('enable_logging') && !mdp_is_excluded()) ? 1 : 0,
            'ajax'    => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('mdp_track'),
        ));
        wp_enqueue_script('mdp-attribution');
    }

    /* ---------- Серверные читалки cookie (для CAPI / Purchase) ---------- */

    private static function cookie($name, $default = '') {
        return isset($_COOKIE[$name]) ? sanitize_text_field(wp_unslash($_COOKIE[$name])) : $default;
    }

    /** Все UTM-метки (первое касание + последнее). */
    public static function get_utm() {
        $raw = self::cookie('mdp_utm');
        $data = $raw ? json_decode(stripslashes($raw), true) : array();
        return is_array($data) ? $data : array();
    }

    /** Человекочитаемый origin: откуда пришёл лид (facebook/instagram/google/direct/...). */
    public static function get_origin() {
        return self::cookie('mdp_origin', 'direct');
    }

    /** Исходный реферер (первое касание). */
    public static function get_referrer() {
        return self::cookie('mdp_referrer');
    }

    /** _fbp — браузерный идентификатор пикселя. */
    public static function get_fbp() {
        return self::cookie('_fbp');
    }

    /** _fbc — клик-идентификатор (из fbclid). */
    public static function get_fbc() {
        return self::cookie('_fbc');
    }

    /** Сырой fbclid (для справки/custom_data; для матчинга используется fbc). */
    public static function get_fbclid() {
        return self::cookie('mdp_fbclid');
    }

    /**
     * Данные авторизованного пользователя для расширенного сопоставления
     * (email, телефон, имя, адрес из профиля/WooCommerce) + external_id.
     */
    public static function user_identity() {
        $d = array();
        if (is_user_logged_in()) {
            $u = wp_get_current_user();
            $d['email']      = $u->user_email;
            $d['first_name'] = get_user_meta($u->ID, 'billing_first_name', true) ?: $u->first_name;
            $d['last_name']  = get_user_meta($u->ID, 'billing_last_name', true) ?: $u->last_name;
            $d['phone']      = get_user_meta($u->ID, 'billing_phone', true);
            $d['city']       = get_user_meta($u->ID, 'billing_city', true);
            $d['state']      = get_user_meta($u->ID, 'billing_state', true);
            $d['zip']        = get_user_meta($u->ID, 'billing_postcode', true);
            $d['country']    = get_user_meta($u->ID, 'billing_country', true);
        }
        $xid = mdp_external_id();
        if ($xid) {
            $d['external_id'] = $xid;
        }
        return array_filter($d);
    }

    /**
     * Собрать custom_data атрибуции для добавления в события (и пикселя, и CAPI).
     */
    public static function attribution_payload() {
        $utm = self::get_utm();
        $payload = array(
            'lead_origin'   => self::get_origin(),
            'lead_referrer' => self::get_referrer(),
        );
        if (self::get_fbclid()) {
            $payload['fbclid'] = self::get_fbclid();
        }
        // Последнее касание UTM кладём плоско, чтобы было видно в Events Manager
        $last = isset($utm['last']) && is_array($utm['last']) ? $utm['last'] : array();
        foreach (array('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content') as $k) {
            if (!empty($last[$k])) {
                $payload[$k] = $last[$k];
            }
        }
        return array_filter($payload, function ($v) { return $v !== '' && $v !== null; });
    }
}
