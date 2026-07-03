<?php
if (!defined('ABSPATH')) exit;

/**
 * Браузерный пиксель: базовый код, PageView и Purchase для не-WooCommerce
 * страниц "Спасибо". Передаёт атрибуцию (UTM/origin) в каждое событие.
 */
class MDP_Pixel {

    /** @var MDP_CAPI */
    private $capi;

    public function __construct(MDP_CAPI $capi) {
        $this->capi = $capi;
        add_action('wp_head', array($this, 'render'), 20);
    }

    /** Сгенерировать уникальный event_id (для дедупликации браузер+сервер). */
    public static function event_id($prefix = 'ev') {
        return $prefix . '.' . wp_generate_uuid4();
    }

    /**
     * Собрать Advanced Matching (plaintext) для fbq('init', ...).
     * Пиксель сам нормализует и хэширует значения перед отправкой.
     * Ключи соответствуют формату Meta: em, ph, fn, ln, ct, st, zp, country, external_id.
     */
    private function advanced_matching() {
        $external_id = mdp_external_id();

        if (!mdp_get('enable_advanced_matching')) {
            // Даже без AM передаём external_id — это безопасно и повышает матчинг
            return $external_id ? array('external_id' => $external_id) : array();
        }

        $am = MDP_Attribution::user_identity();

        // Страница "Спасибо" WooCommerce — берём данные из заказа
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
            $order_id = absint(get_query_var('order-received'));
            if ($order_id && function_exists('wc_get_order')) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $am['email']      = $order->get_billing_email();
                    $am['phone']      = $order->get_billing_phone();
                    $am['first_name'] = $order->get_billing_first_name();
                    $am['last_name']  = $order->get_billing_last_name();
                    $am['city']       = $order->get_billing_city();
                    $am['state']      = $order->get_billing_state();
                    $am['zip']        = $order->get_billing_postcode();
                    $am['country']    = $order->get_billing_country();
                    if ($order->get_customer_id()) {
                        $am['external_id'] = 'uid_' . $order->get_customer_id();
                    }
                }
            }
        }

        // Маппинг наших ключей -> короткие ключи пикселя
        $map = array(
            'email' => 'em', 'phone' => 'ph', 'first_name' => 'fn', 'last_name' => 'ln',
            'city' => 'ct', 'state' => 'st', 'zip' => 'zp', 'country' => 'country',
            'external_id' => 'external_id',
        );
        $out = array();
        foreach ($map as $src => $dst) {
            if (!empty($am[$src])) {
                $out[$dst] = $am[$src];
            }
        }
        return $out;
    }

    public function render() {
        $pixel_id = mdp_get('pixel_id');
        if (!$pixel_id) {
            return; // нет ID — ничего не выводим
        }
        if (mdp_is_excluded()) {
            return; // не трекаем команду
        }

        $attr = MDP_Attribution::attribution_payload();
        // JSON для передачи в события пикселя
        $attr_json = wp_json_encode($attr ?: new stdClass());

        // Advanced Matching: те же параметры, что уйдут на сервер (CAPI)
        $am = $this->advanced_matching();
        $am_json = $am ? wp_json_encode($am) : '';

        // Определяем, не страница ли это "Спасибо" (не WooCommerce)
        $purchase = $this->maybe_thankyou_purchase();
        ?>
        <!-- Meta Dynamic Pixel -->
        <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');

        window.mdpAttr = <?php echo $attr_json; ?>;
        fbq('init', '<?php echo esc_js($pixel_id); ?>'<?php echo $am_json ? ', ' . $am_json : ''; ?>);

        <?php if (mdp_get('track_pageview')) :
            $pv_id = self::event_id('pv');
            if (mdp_get('capi_pageview')) {
                $this->capi->send('PageView', $pv_id, MDP_Attribution::attribution_payload(), MDP_Attribution::user_identity());
            }
        ?>
        fbq('track', 'PageView', window.mdpAttr, {eventID: '<?php echo esc_js($pv_id); ?>'});
        if (window.mdpTrack) mdpTrack('PageView', 0, '', '<?php echo esc_js($pv_id); ?>');
        <?php endif; ?>

        <?php if ($purchase) : ?>
        fbq('track', 'Purchase',
            Object.assign({
                value: <?php echo floatval($purchase['value']); ?>,
                currency: '<?php echo esc_js($purchase['currency']); ?>'
            }, window.mdpAttr),
            {eventID: '<?php echo esc_js($purchase['event_id']); ?>'}
        );
        if (window.mdpTrack) mdpTrack('Purchase', <?php echo floatval($purchase['value']); ?>, '<?php echo esc_js($purchase['currency']); ?>', '<?php echo esc_js($purchase['event_id']); ?>');
        <?php endif; ?>
        </script>
        <noscript><img height="1" width="1" style="display:none"
            src="https://www.facebook.com/tr?id=<?php echo esc_attr($pixel_id); ?>&ev=PageView&noscript=1"/></noscript>
        <!-- /Meta Dynamic Pixel -->
        <?php
    }

    /**
     * Если текущая страница помечена как "Спасибо" (без WooCommerce) и Purchase включён —
     * подготовить данные покупки и отправить серверное событие.
     */
    private function maybe_thankyou_purchase() {
        if (!mdp_get('track_purchase')) {
            return null;
        }
        // WooCommerce обрабатывается отдельно
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
            return null;
        }

        $ids = array_filter(array_map('intval', explode(',', mdp_get('thankyou_page_ids'))));
        if (empty($ids) || !is_page($ids)) {
            return null;
        }

        $page_id = get_queried_object_id();

        // value/currency можно переопределить через URL: ?value=1990&currency=RUB
        $value = isset($_GET['value']) ? floatval($_GET['value']) : floatval(mdp_get('thankyou_value'));
        $currency = isset($_GET['currency']) ? sanitize_text_field(wp_unslash($_GET['currency'])) : mdp_get('thankyou_currency');

        // Стабильный event_id: одинаковый при перезагрузке/повторном заходе того же
        // посетителя, чтобы Meta и встроенная аналитика дедуплицировали покупку
        // (без этого каждый рефреш = новая «конверсия»). Привязка к external_id, а
        // если его ещё нет (JS не успел поставить cookie) — к дню как запасной вариант.
        $xid   = mdp_external_id();
        $token = $xid !== '' ? substr(md5($xid), 0, 16) : gmdate('Ymd');
        $event_id = 'purchase-page-' . $page_id . '.' . $token;

        // Серверное дублирование — строго один раз на (посетитель|день)+страницу,
        // чтобы рефреш «Спасибо» не плодил серверные Purchase.
        $guard = 'mdp_pp_' . md5($event_id);
        if (false === get_transient($guard)) {
            $custom = array_merge(array('value' => $value, 'currency' => $currency), MDP_Attribution::attribution_payload());
            $this->capi->send('Purchase', $event_id, $custom, MDP_Attribution::user_identity());
            set_transient($guard, 1, DAY_IN_SECONDS);
        }

        return array(
            'value'    => $value,
            'currency' => $currency,
            'event_id' => $event_id,
        );
    }
}
