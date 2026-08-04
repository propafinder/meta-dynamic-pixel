<?php
if (!defined('ABSPATH')) exit;

/**
 * Логирование событий в собственную таблицу для встроенной аналитики.
 * Браузерные события приходят beacon-ом на admin-ajax, серверные — из CAPI.
 */
class MDP_Logger {

    const DB_VERSION = '1.0';

    public function __construct() {
        add_action('wp_ajax_mdp_track', array($this, 'ajax'));
        add_action('wp_ajax_nopriv_mdp_track', array($this, 'ajax'));
        add_action('mdp_prune_event', array($this, 'prune'));
        add_action('admin_init', array(__CLASS__, 'maybe_upgrade'));
    }

    /** Создать таблицу, если её ещё нет (например, после обновления плагина). */
    public static function maybe_upgrade() {
        if (get_option('mdp_db_version') !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'mdp_events';
    }

    /** Создание/обновление таблицы (вызывается при активации). */
    public static function install() {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_name VARCHAR(40) NOT NULL DEFAULT '',
            event_id VARCHAR(64) NOT NULL DEFAULT '',
            channel VARCHAR(10) NOT NULL DEFAULT '',
            value DECIMAL(14,2) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT '',
            origin VARCHAR(60) NOT NULL DEFAULT '',
            utm_source VARCHAR(120) NOT NULL DEFAULT '',
            utm_medium VARCHAR(120) NOT NULL DEFAULT '',
            utm_campaign VARCHAR(150) NOT NULL DEFAULT '',
            url VARCHAR(255) NOT NULL DEFAULT '',
            match_keys VARCHAR(120) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT '',
            created_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY event_name (event_name),
            KEY channel (channel)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('mdp_db_version', self::DB_VERSION);
    }

    /**
     * Записать событие. origin/UTM подтягиваются из cookie автоматически.
     */
    public static function record($channel, $event_name, $args = array()) {
        if (!mdp_get('enable_logging')) {
            return;
        }
        global $wpdb;

        $a = array_merge(array(
            'event_id' => '', 'value' => 0, 'currency' => '',
            'match_keys' => '', 'status' => 'ok', 'url' => '',
        ), $args);

        $utm  = MDP_Attribution::get_utm();
        $last = isset($utm['last']) && is_array($utm['last']) ? $utm['last'] : array();

        $wpdb->insert($wpdb->prefix . 'mdp_events', array(
            'event_name'   => substr((string) $event_name, 0, 40),
            'event_id'     => substr((string) $a['event_id'], 0, 64),
            'channel'      => substr((string) $channel, 0, 10),
            'value'        => floatval($a['value']),
            'currency'     => substr((string) $a['currency'], 0, 8),
            'origin'       => substr((string) MDP_Attribution::get_origin(), 0, 60),
            'utm_source'   => substr((string) (isset($last['utm_source']) ? $last['utm_source'] : ''), 0, 120),
            'utm_medium'   => substr((string) (isset($last['utm_medium']) ? $last['utm_medium'] : ''), 0, 120),
            'utm_campaign' => substr((string) (isset($last['utm_campaign']) ? $last['utm_campaign'] : ''), 0, 150),
            'url'          => substr((string) $a['url'], 0, 255),
            'match_keys'   => substr((string) $a['match_keys'], 0, 120),
            'status'       => substr((string) $a['status'], 0, 20),
            // Храним в UTC: границы диапазонов и группировка по дням считаются
            // через gmdate(), а вывод конвертируется get_date_from_gmt(). Так все
            // три места согласованы независимо от часового пояса сайта.
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ), array('%s','%s','%s','%f','%s','%s','%s','%s','%s','%s','%s','%s','%s'));
    }

    /** Приём браузерных событий (beacon). */
    public function ajax() {
        if (!check_ajax_referer('mdp_track', 'nonce', false)) {
            wp_die('', '', array('response' => 403));
        }
        if (mdp_is_excluded() || !mdp_get('enable_logging')) {
            wp_die('skip');
        }

        $name = isset($_POST['event_name']) ? sanitize_text_field(wp_unslash($_POST['event_name'])) : '';
        // Принимаем только известные стандартные события — отсекает случайный мусор
        // и спам в таблицу аналитики.
        $allowed = array('PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Lead', 'Purchase');
        if (!in_array($name, $allowed, true)) {
            wp_die('badname');
        }

        // Какие cookie-идентификаторы доступны (грубый показатель качества матчинга)
        $keys = array();
        foreach (array('_fbp' => 'fbp', '_fbc' => 'fbc', 'mdp_xid' => 'external_id') as $c => $label) {
            if (!empty($_COOKIE[$c])) {
                $keys[] = $label;
            }
        }

        self::record('browser', $name, array(
            'event_id'   => isset($_POST['event_id']) ? sanitize_text_field(wp_unslash($_POST['event_id'])) : '',
            'value'      => isset($_POST['value']) ? floatval($_POST['value']) : 0,
            'currency'   => isset($_POST['currency']) ? sanitize_text_field(wp_unslash($_POST['currency'])) : '',
            'url'        => isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '',
            'match_keys' => implode(',', $keys),
            'status'     => 'ok',
        ));

        wp_die('ok');
    }

    /** Удаление старых записей. */
    public function prune() {
        global $wpdb;
        $days  = max(1, intval(mdp_get('retention_days', 90)));
        $table = self::table();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE created_at < %s",
            gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)
        ));
    }

    /* ===================== Запросы для дашборда ===================== */

    private static function since($days) {
        return gmdate('Y-m-d H:i:s', time() - intval($days) * DAY_IN_SECONDS);
    }

    /** Сводка KPI за период. */
    public static function totals($days) {
        global $wpdb;
        $t = self::table();
        $since = self::since($days);

        $events = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT event_id) FROM $t WHERE created_at >= %s", $since
        ));
        $purchases = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT event_id) FROM $t WHERE event_name='Purchase' AND created_at >= %s", $since
        ));
        $leads = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT event_id) FROM $t WHERE event_name='Lead' AND created_at >= %s", $since
        ));

        // Выручка и средний чек — РАЗДЕЛЬНО по валютам (покупки в £, лиды в $ и т.п.).
        // По одной строке на event_id (чтобы браузер+сервер не удваивали).
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT currency, COUNT(*) AS purchases, COALESCE(SUM(v),0) AS revenue FROM (
                SELECT event_id, currency, MAX(value) AS v FROM $t
                WHERE event_name='Purchase' AND created_at >= %s
                GROUP BY event_id, currency
            ) x GROUP BY currency ORDER BY revenue DESC", $since
        ));
        $by_currency = array();
        foreach ((array) $rows as $r) {
            $p   = (int) $r->purchases;
            $rev = (float) $r->revenue;
            $by_currency[] = array(
                'currency'  => $r->currency !== '' ? $r->currency : '—',
                'purchases' => $p,
                'revenue'   => $rev,
                'aov'       => $p ? $rev / $p : 0,
            );
        }

        $browser = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE channel='browser' AND created_at >= %s", $since
        ));
        $server = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE channel='server' AND created_at >= %s", $since
        ));

        return array(
            'events'      => $events,
            'purchases'   => $purchases,
            'leads'       => $leads,
            'by_currency' => $by_currency,
            'browser'     => $browser,
            'server'      => $server,
        );
    }

    /** Воронка по ключевым событиям. */
    public static function funnel($days) {
        global $wpdb;
        $t = self::table();
        $since = self::since($days);
        $steps = array('PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Lead', 'Purchase');
        $out = array();
        foreach ($steps as $s) {
            $out[$s] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT event_id) FROM $t WHERE event_name=%s AND created_at >= %s",
                $s, $since
            ));
        }
        return $out;
    }

    /** События по дням (для графика). */
    public static function by_day($days) {
        global $wpdb;
        $t = self::table();
        $since = self::since($days);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) d, COUNT(DISTINCT event_id) c
             FROM $t WHERE created_at >= %s GROUP BY DATE(created_at)", $since
        ), OBJECT_K);

        $series = array();
        for ($i = intval($days) - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
            $series[$day] = isset($rows[$day]) ? (int) $rows[$day]->c : 0;
        }
        return $series;
    }

    /** Топ по столбцу (origin / utm_source / utm_campaign / event_name). */
    public static function top($column, $days, $limit = 8) {
        global $wpdb;
        $allowed = array('origin', 'utm_source', 'utm_campaign', 'event_name');
        if (!in_array($column, $allowed, true)) {
            return array();
        }
        $t = self::table();
        $since = self::since($days);
        return $wpdb->get_results($wpdb->prepare(
            "SELECT $column AS label, COUNT(DISTINCT event_id) AS c
             FROM $t WHERE created_at >= %s AND $column <> ''
             GROUP BY $column ORDER BY c DESC LIMIT %d", $since, $limit
        ));
    }

    /**
     * Главный отчёт: по каждому источнику — визиты, лиды, покупки, выручка и
     * конверсия. Именно этот срез нужен, чтобы понимать, какой трафик окупается.
     */
    public static function by_source($column, $days, $limit = 20) {
        global $wpdb;
        $allowed = array('origin', 'utm_source', 'utm_campaign');
        if (!in_array($column, $allowed, true)) {
            return array();
        }
        $t = self::table();
        $since = self::since($days);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT $column AS label,
                    COUNT(DISTINCT CASE WHEN event_name='PageView' THEN event_id END) AS visits,
                    COUNT(DISTINCT CASE WHEN event_name='Lead' THEN event_id END) AS leads,
                    COUNT(DISTINCT CASE WHEN event_name='Purchase' THEN event_id END) AS purchases
             FROM $t WHERE created_at >= %s AND $column <> ''
             GROUP BY $column
             ORDER BY purchases DESC, leads DESC, visits DESC
             LIMIT %d", $since, $limit
        ), OBJECT_K);

        if (empty($rows)) {
            return array();
        }
        foreach ($rows as $r) {
            $r->revenue = array(); // [ ['currency'=>'GBP','sum'=>123.0], ... ]
        }

        // Выручка: одна строка на event_id (браузер+сервер не удваиваем), по валютам.
        $rev = $wpdb->get_results($wpdb->prepare(
            "SELECT label, currency, SUM(v) AS revenue FROM (
                SELECT $column AS label, event_id, currency, MAX(value) AS v
                FROM $t
                WHERE event_name='Purchase' AND created_at >= %s AND $column <> ''
                GROUP BY $column, event_id, currency
             ) x GROUP BY label, currency", $since
        ));
        foreach ((array) $rev as $r) {
            if (isset($rows[$r->label])) {
                $rows[$r->label]->revenue[] = array(
                    'currency' => $r->currency,
                    'sum'      => (float) $r->revenue,
                );
            }
        }
        return $rows;
    }

    /** Последние события. */
    public static function recent($limit = 25) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT event_name, channel, value, currency, origin, utm_source, status, created_at
             FROM $t ORDER BY id DESC LIMIT %d", $limit
        ));
    }

    /** Полная очистка таблицы. */
    public static function truncate() {
        global $wpdb;
        $t = self::table();
        $wpdb->query("TRUNCATE TABLE $t");
    }
}
