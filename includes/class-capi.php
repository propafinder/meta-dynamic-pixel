<?php
if (!defined('ABSPATH')) exit;

/**
 * Conversions API — серверная отправка событий в Meta.
 * Дедупликация с браузерным пикселем выполняется по общему event_id.
 */
class MDP_CAPI {

    const ENDPOINT = 'https://graph.facebook.com/v19.0/';

    /** Включён ли CAPI и заданы ли реквизиты. */
    public function is_active() {
        return mdp_get('enable_capi') && mdp_get('pixel_id') && mdp_get('access_token');
    }

    /** SHA-256 хэш с нормализацией (lowercase + trim) — для email, имени и т.п. */
    private function hash($value) {
        $value = trim(mb_strtolower((string) $value, 'UTF-8'));
        return $value === '' ? '' : hash('sha256', $value);
    }

    /** Гео-поля (city/state/zip/country): дополнительно убираем все пробелы. */
    private function hash_geo($value) {
        $value = preg_replace('/\s+/u', '', trim(mb_strtolower((string) $value, 'UTF-8')));
        return $value === '' ? '' : hash('sha256', $value);
    }

    /** Телефон: оставляем только цифры (с кодом страны), затем хэш. */
    private function hash_phone($value) {
        $digits = preg_replace('/\D+/', '', (string) $value);
        return $digits === '' ? '' : hash('sha256', $digits);
    }

    /** IP клиента. */
    private function client_ip() {
        foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $k) {
            if (empty($_SERVER[$k])) {
                continue;
            }
            $ip = trim(explode(',', wp_unslash($_SERVER[$k]))[0]);
            // Принимаем только синтаксически валидный IP — форвард-заголовки можно
            // подделать, и мусор в этом поле только портит матчинг в Meta.
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return '';
    }

    /**
     * Отправить событие на сервер Meta.
     *
     * @param string $event_name  Purchase, PageView, InitiateCheckout ...
     * @param string $event_id    Общий с пикселем ID для дедупликации.
     * @param array  $custom_data value, currency, content_ids, и UTM/origin.
     * @param array  $user_data   email, phone, first_name, last_name (необязательно).
     */
    public function send($event_name, $event_id, $custom_data = array(), $user_data = array()) {
        if (!$this->is_active()) {
            return false;
        }
        if (mdp_is_excluded()) {
            return false; // не трекаем команду
        }

        $am = mdp_get('enable_advanced_matching');

        // Базовые данные пользователя для матчинга
        $ud = array(
            'client_ip_address' => $this->client_ip(),
            'client_user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
        );
        $fbp = MDP_Attribution::get_fbp();
        $fbc = MDP_Attribution::get_fbc();
        if ($fbp) $ud['fbp'] = $fbp;
        if ($fbc) $ud['fbc'] = $fbc;

        // Расширенное сопоставление (нормализуем + хэшируем PII по правилам Meta)
        if ($am) {
            if (!empty($user_data['email']))       $ud['em']      = $this->hash($user_data['email']);
            if (!empty($user_data['phone']))       $ud['ph']      = $this->hash_phone($user_data['phone']);
            if (!empty($user_data['first_name']))  $ud['fn']      = $this->hash($user_data['first_name']);
            if (!empty($user_data['last_name']))   $ud['ln']      = $this->hash($user_data['last_name']);
            if (!empty($user_data['city']))        $ud['ct']      = $this->hash_geo($user_data['city']);
            if (!empty($user_data['state']))       $ud['st']      = $this->hash_geo($user_data['state']);
            if (!empty($user_data['zip']))         $ud['zp']      = $this->hash_geo($user_data['zip']);
            if (!empty($user_data['country']))     $ud['country'] = $this->hash_geo($user_data['country']);
            // external_id: хэширование рекомендовано; хэшируем, чтобы совпадать с пикселем
            if (!empty($user_data['external_id'])) $ud['external_id'] = $this->hash($user_data['external_id']);
        } elseif (!empty($user_data['external_id'])) {
            // даже без расширенного сопоставления external_id полезен для матчинга
            $ud['external_id'] = $this->hash($user_data['external_id']);
        }

        $event = array(
            'event_name'       => $event_name,
            'event_time'       => time(),
            'event_id'         => $event_id,
            'action_source'    => 'website',
            'event_source_url' => $this->current_url(),
            'user_data'        => array_filter($ud),
        );
        if (!empty($custom_data)) {
            $event['custom_data'] = $custom_data;
        }

        $body = array(
            'data'         => array($event),
            'access_token' => mdp_get('access_token'),
        );
        $test = mdp_get('test_event_code');
        if ($test) {
            $body['test_event_code'] = $test;
        }

        $url = self::ENDPOINT . mdp_get('pixel_id') . '/events';

        $response = wp_remote_post($url, array(
            'timeout'  => 6,
            'blocking' => false,            // не тормозим загрузку страницы
            'headers'  => array('Content-Type' => 'application/json'),
            'body'     => wp_json_encode($body),
        ));

        // Запись в встроенную аналитику (матч-ключи = какие PII/ID ушли)
        $match = array_values(array_diff(array_keys(array_filter($ud)), array('client_ip_address', 'client_user_agent')));
        MDP_Logger::record('server', $event_name, array(
            'event_id'   => $event_id,
            'value'      => isset($custom_data['value']) ? $custom_data['value'] : 0,
            'currency'   => isset($custom_data['currency']) ? $custom_data['currency'] : '',
            'url'        => $this->current_url(),
            'match_keys' => implode(',', $match),
            // Отправка неблокирующая ('blocking' => false): мы знаем только, что
            // запрос поставлен в очередь, а не что Meta его приняла. Поэтому 'queued',
            // чтобы статус не вводил в заблуждение.
            'status'     => is_wp_error($response) ? 'error' : 'queued',
        ));

        return !is_wp_error($response);
    }

    private function current_url() {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri    = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        return esc_url_raw($scheme . $host . $uri);
    }
}
