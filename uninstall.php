<?php
/**
 * Полная очистка при удалении плагина: настройки, версия БД, таблица аналитики,
 * cron-задача и временные guard-ключи Purchase. Запускается WordPress только при
 * удалении плагина (не при деактивации).
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Настройки и служебные опции.
delete_option('mdp_settings');
delete_option('mdp_db_version');

// Cron-задача очистки (на случай, если деактивация не отработала).
$ts = wp_next_scheduled('mdp_prune_event');
if ($ts) {
    wp_unschedule_event($ts, 'mdp_prune_event');
}

// Таблица встроенной аналитики.
$table = $wpdb->prefix . 'mdp_events';
$wpdb->query("DROP TABLE IF EXISTS {$table}");

// Транзиенты-guard для не-WooCommerce страниц «Спасибо» (mdp_pp_*).
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_mdp\_pp\_%'
        OR option_name LIKE '\_transient\_timeout\_mdp\_pp\_%'"
);
