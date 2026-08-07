<?php
/**
 * Plugin Name: Meta Dynamic Pixel
 * Plugin URI:  https://example.com/meta-dynamic-pixel
 * Description: Динамический пиксель Meta (Facebook/Instagram): ID + токен Conversions API, авто-проброс UTM-меток, запоминание реферера и origin (откуда пришёл лид), сквозное отслеживание до покупки на странице "Спасибо" (thank you). Серверная отправка событий (CAPI) с дедупликацией.
 * Version:     1.3.1
 * Author:      Degree Team
 * Author URI:  https://example.com/
 * License:     GPLv2 or later
 * Text Domain: meta-dynamic-pixel
 * WC requires at least: 6.0
 * WC tested up to: 9.4
 */

if (!defined('ABSPATH')) {
    exit; // Прямой доступ запрещён
}

define('MDP_VERSION', '1.3.1');
define('MDP_FILE', __FILE__);
define('MDP_PATH', plugin_dir_path(__FILE__));
define('MDP_URL', plugin_dir_url(__FILE__));
define('MDP_OPTION', 'mdp_settings');
define('MDP_GITHUB_REPO', 'propafinder/meta-dynamic-pixel'); // откуда тянуть обновления

/**
 * Значения настроек по умолчанию.
 */
function mdp_default_settings() {
    return array(
        'pixel_id'                 => '',
        'access_token'             => '',   // Токен Conversions API (необязателен)
        'test_event_code'         => '',    // Код тестового события из Events Manager
        'capi_off'                 => 0,    // Аварийное отключение CAPI (обычно не нужно)
        'enable_advanced_matching' => 1,    // Расширенное сопоставление (хэш email/телефона)
        'attribution_days'         => 90,   // Срок хранения атрибуции (cookie), дней
        'track_pageview'           => 1,
        'track_viewcontent'        => 1,
        'track_addtocart'          => 1,
        'track_initiatecheckout'   => 1,
        'track_purchase'           => 1,
        'track_lead'               => 1,    // Lead на страницах "Спасибо" (не WooCommerce)
        'capi_pageview'            => 0,    // Дублировать PageView через сервер (обычно не нужно)
        'thankyou_page_ids'        => '',   // ID страниц "Спасибо" для не-WooCommerce (через запятую)
        'thankyou_value'           => '',   // Значение покупки по умолчанию для таких страниц
        'thankyou_currency'        => 'USD', // Валюта лидов (лендинговые "Спасибо") — доллары
        'enable_logging'           => 1,    // Вести встроенную аналитику (своя таблица в БД)
        'exclude_admins'           => 1,    // Не учитывать авторизованных редакторов/админов
        'retention_days'           => 90,   // Сколько хранить записи аналитики
    );
}

/**
 * Получить все настройки (с дефолтами).
 */
function mdp_get_settings() {
    $saved = get_option(MDP_OPTION, array());
    if (!is_array($saved)) {
        $saved = array();
    }
    return wp_parse_args($saved, mdp_default_settings());
}

/**
 * Получить одну настройку.
 */
function mdp_get($key, $default = '') {
    $s = mdp_get_settings();
    return isset($s[$key]) ? $s[$key] : $default;
}

/**
 * Стабильный external_id для матчинга.
 * Авторизованный пользователь -> его ID WP (кросс-девайс).
 * Иначе -> постоянный first-party cookie mdp_xid (ставится attribution.js).
 */
function mdp_external_id() {
    if (is_user_logged_in()) {
        return 'uid_' . get_current_user_id();
    }
    if (!empty($_COOKIE['mdp_xid'])) {
        return sanitize_text_field(wp_unslash($_COOKIE['mdp_xid']));
    }
    return '';
}

/**
 * Нужно ли исключить текущего пользователя из трекинга/аналитики
 * (чтобы команда не засоряла статистику).
 */
function mdp_is_excluded() {
    if (!mdp_get('exclude_admins')) {
        return false;
    }
    return is_user_logged_in() && current_user_can('edit_posts');
}

/**
 * Совместимость с WooCommerce, чтобы Woo не помечал плагин как несовместимый:
 *  - custom_order_tables (HPOS) — весь код работает только через CRUD заказа
 *    (get_..., update_meta_data, save), без прямого доступа к postmeta, поэтому
 *    совместимость объявляется честно;
 *  - cart_checkout_blocks — блоки корзины/оформления.
 * Декларацию обязательно делать на хуке before_woocommerce_init.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', MDP_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', MDP_FILE, true);
    }
});

// Подключаем классы
require_once MDP_PATH . 'includes/class-updater.php';
require_once MDP_PATH . 'includes/class-settings.php';
require_once MDP_PATH . 'includes/class-attribution.php';
require_once MDP_PATH . 'includes/class-logger.php';
require_once MDP_PATH . 'includes/class-dashboard.php';
require_once MDP_PATH . 'includes/class-capi.php';
require_once MDP_PATH . 'includes/class-pixel.php';
require_once MDP_PATH . 'includes/class-woocommerce.php';

/**
 * Инициализация плагина.
 */
function mdp_init() {
    load_plugin_textdomain('meta-dynamic-pixel', false, dirname(plugin_basename(MDP_FILE)) . '/languages');

    // Обновления плагина прямо из GitHub-релизов (видны в «Плагины» как обычное обновление).
    new MDP_GitHub_Updater(MDP_FILE, MDP_GITHUB_REPO, MDP_VERSION);

    new MDP_Logger();
    new MDP_Dashboard();
    new MDP_Settings();
    new MDP_Attribution();
    $capi = new MDP_CAPI();
    new MDP_Pixel($capi);

    if (class_exists('WooCommerce')) {
        new MDP_WooCommerce($capi);
    }
}
add_action('plugins_loaded', 'mdp_init');

/**
 * Активация: дефолтные настройки, таблица аналитики, ежедневная очистка.
 */
register_activation_hook(__FILE__, function () {
    if (get_option(MDP_OPTION) === false) {
        add_option(MDP_OPTION, mdp_default_settings());
    }
    MDP_Logger::install();
    if (!wp_next_scheduled('mdp_prune_event')) {
        wp_schedule_event(time() + 3600, 'daily', 'mdp_prune_event');
    }
});

/**
 * Деактивация: снять задачу очистки.
 */
register_deactivation_hook(__FILE__, function () {
    $ts = wp_next_scheduled('mdp_prune_event');
    if ($ts) {
        wp_unschedule_event($ts, 'mdp_prune_event');
    }
});
