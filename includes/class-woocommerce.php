<?php
if (!defined('ABSPATH')) exit;

/**
 * Интеграция с WooCommerce: ViewContent, AddToCart, InitiateCheckout
 * и Purchase на странице "Спасибо" (order-received) — браузер + сервер (CAPI).
 */
class MDP_WooCommerce {

    /** @var MDP_CAPI */
    private $capi;

    public function __construct(MDP_CAPI $capi) {
        $this->capi = $capi;

        if (mdp_get('track_viewcontent')) {
            add_action('woocommerce_after_single_product', array($this, 'view_content'));
        }
        if (mdp_get('track_addtocart')) {
            add_action('wp_footer', array($this, 'add_to_cart_listener'));
            // Не-AJAX добавление (submit формы с редиректом) не даёт JS-события —
            // запоминаем в сессии Woo и выводим событие на следующей странице.
            add_action('woocommerce_add_to_cart', array($this, 'note_add_to_cart'), 10, 4);
        }
        if (mdp_get('track_initiatecheckout')) {
            add_action('woocommerce_after_checkout_form', array($this, 'initiate_checkout'));
        }
        if (mdp_get('track_purchase')) {
            add_action('woocommerce_thankyou', array($this, 'purchase'), 10, 1);
            // Отложенная/асинхронная оплата (bank transfer, редиректные гейтвеи, IPN):
            // серверный Purchase уходит, когда платёж реально подтверждён.
            add_action('woocommerce_payment_complete', array($this, 'maybe_capi_purchase'), 10, 1);
            add_action('woocommerce_order_status_completed', array($this, 'maybe_capi_purchase'), 10, 1);
            // В момент оформления запоминаем в заказе браузерные идентификаторы и
            // атрибуцию — IPN/webhook шлюза не несёт cookie покупателя.
            add_action('woocommerce_checkout_create_order', array($this, 'stash_attribution'), 10, 1);
            add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'stash_attribution'), 10, 1);
        }
    }

    /**
     * Снимок браузерного контекста покупателя в мету заказа: fbp/fbc, IP, user-agent,
     * external_id гостя и UTM/origin. Используется серверным Purchase, когда событие
     * триггерится не из браузера покупателя (IPN, смена статуса в админке).
     */
    public function stash_attribution($order) {
        if (!$order instanceof WC_Order) return;
        $fbp = MDP_Attribution::get_fbp();
        $fbc = MDP_Attribution::get_fbc();
        if ($fbp) $order->update_meta_data('_mdp_fbp', $fbp);
        if ($fbc) $order->update_meta_data('_mdp_fbc', $fbc);
        $order->update_meta_data('_mdp_ip', $this->capi->client_ip());
        $order->update_meta_data('_mdp_ua', isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '');
        if (!empty($_COOKIE['mdp_xid'])) {
            $order->update_meta_data('_mdp_xid', sanitize_text_field(wp_unslash($_COOKIE['mdp_xid'])));
        }
        $attr = MDP_Attribution::attribution_payload();
        if ($attr) {
            $order->update_meta_data('_mdp_attr', wp_json_encode($attr));
        }
    }

    /**
     * Разрешён ли Purchase для этого заказа.
     * По умолчанию считаем покупкой только оплаченные/обрабатываемые заказы
     * (processing, completed). failed/cancelled/pending/on-hold не шлём, чтобы не
     * засчитывать неоплаченные. Набор статусов можно переопределить фильтром.
     */
    private function purchase_status_ok($order) {
        if (!$order instanceof WC_Order) {
            return false;
        }
        $allowed = apply_filters('mdp_purchase_statuses', array('processing', 'completed'), $order);
        return $order->has_status($allowed);
    }

    /** custom_data покупки из заказа (общее для браузера и сервера). */
    private function purchase_custom($order) {
        $content_ids = array();
        foreach ($order->get_items() as $item) {
            $content_ids[] = (string) $item->get_product_id();
        }
        // Атрибуция: приоритет — снимок на момент оформления (в IPN-запросе cookie
        // покупателя нет, а снимок точнее отражает источник именно этой покупки).
        $attr = json_decode((string) $order->get_meta('_mdp_attr'), true);
        if (!is_array($attr) || !$attr) {
            $attr = MDP_Attribution::attribution_payload();
        }
        return array_merge(array(
            'value'        => floatval($order->get_total()),
            'currency'     => $order->get_currency(),
            'content_ids'  => $content_ids,
            'content_type' => 'product',
            'order_id'     => (string) $order->get_id(),
        ), $attr);
    }

    /**
     * Серверный Purchase (CAPI) — строго один раз на заказ и только для оплаченного
     * статуса. Вызывается и со «Спасибо», и по событиям подтверждения оплаты.
     */
    public function maybe_capi_purchase($order_id) {
        if (!$order_id) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$this->purchase_status_ok($order)) {
            return;
        }
        if ($order->get_meta('_mdp_capi_sent')) {
            return;
        }

        $user = array(
            'email'       => $order->get_billing_email(),
            'phone'       => $order->get_billing_phone(),
            'first_name'  => $order->get_billing_first_name(),
            'last_name'   => $order->get_billing_last_name(),
            'city'        => $order->get_billing_city(),
            'state'       => $order->get_billing_state(),
            'zip'         => $order->get_billing_postcode(),
            'country'     => $order->get_billing_country(),
            'external_id' => $order->get_customer_id()
                ? 'uid_' . $order->get_customer_id()
                : ($order->get_meta('_mdp_xid')
                    ?: (isset($_COOKIE['mdp_xid']) ? sanitize_text_field(wp_unslash($_COOKIE['mdp_xid'])) : '')),
            // Страница, где произошло событие, — «Спасибо» заказа (а не URL вебхука)
            'event_source_url' => $order->get_checkout_order_received_url(),
        );
        // Браузерные идентификаторы из снимка на момент оформления: IPN-запрос шлюза
        // не несёт cookie покупателя, его IP/user-agent — это IP шлюза, не клиента.
        foreach (array('fbp' => '_mdp_fbp', 'fbc' => '_mdp_fbc', 'client_ip_address' => '_mdp_ip', 'client_user_agent' => '_mdp_ua') as $key => $meta) {
            $v = $order->get_meta($meta);
            if ($v) {
                $user[$key] = $v;
            }
        }

        $this->capi->send('Purchase', 'purchase.' . $order->get_id(), $this->purchase_custom($order), $user);
        $order->update_meta_data('_mdp_capi_sent', '1');
        $order->save();
    }

    private function attr_js() {
        return wp_json_encode(MDP_Attribution::attribution_payload() ?: new stdClass());
    }

    /** Просмотр товара. */
    public function view_content() {
        if (mdp_is_excluded()) return;
        global $product;
        if (!$product instanceof WC_Product) return;
        ?>
        <script>
        // event_id в браузере: страницы товаров кэшируются, «запечённый» PHP-id
        // раздавался бы всем посетителям и Meta склеила бы их в одно событие.
        var mdpVcId = window.mdpEventId ? mdpEventId('vc') : 'vc.' + Date.now() + '.' + Math.random().toString(16).slice(2);
        if (window.fbq) fbq('track', 'ViewContent', Object.assign({
            content_ids: ['<?php echo esc_js($product->get_id()); ?>'],
            content_name: <?php echo wp_json_encode($product->get_name()); ?>,
            content_type: 'product',
            value: <?php echo floatval($product->get_price()); ?>,
            currency: '<?php echo esc_js(get_woocommerce_currency()); ?>'
        }, <?php echo $this->attr_js(); ?>), {eventID: mdpVcId});
        if (window.mdpTrack) mdpTrack('ViewContent', <?php echo floatval($product->get_price()); ?>, '<?php echo esc_js(get_woocommerce_currency()); ?>', mdpVcId);
        </script>
        <?php
    }

    /**
     * Не-AJAX добавление в корзину: сохраняем данные товара в сессии Woo,
     * событие выведем на следующей загрузке страницы (add_to_cart_listener).
     * AJAX-кнопки сюда не попадают — их ловит jQuery-событие added_to_cart.
     */
    public function note_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id = 0) {
        if (wp_doing_ajax() || isset($_REQUEST['wc-ajax'])) return;
        if (mdp_is_excluded()) return;
        if (!function_exists('WC') || !WC()->session) return;

        $pid     = $variation_id ? $variation_id : $product_id;
        $product = wc_get_product($pid);
        WC()->session->set('mdp_atc', array(
            'id'       => (string) $pid,
            'value'    => $product ? (float) $product->get_price() * max(1, (int) $quantity) : 0,
            'currency' => get_woocommerce_currency(),
        ));
    }

    /**
     * Добавление в корзину — оба сценария:
     *  - AJAX-кнопки: Woo кидает jQuery-событие added_to_cart; нативный
     *    addEventListener его НЕ видит (jQuery.trigger не диспатчит DOM-событие),
     *    поэтому слушаем через jQuery.
     *  - Обычный submit с редиректом: берём данные из сессии (note_add_to_cart).
     */
    public function add_to_cart_listener() {
        if (mdp_is_excluded()) return;

        $atc = (function_exists('WC') && WC()->session) ? WC()->session->get('mdp_atc') : null;
        if ($atc) {
            WC()->session->set('mdp_atc', null); // показываем один раз
        }
        $event_id = MDP_Pixel::event_id('atc');
        ?>
        <script>
        (function () {
            function mdpSendATC(data, eventId) {
                if (window.fbq) fbq('track', 'AddToCart', Object.assign(data, window.mdpAttr || {}), eventId ? {eventID: eventId} : undefined);
                if (window.mdpTrack) mdpTrack('AddToCart', data.value || 0, data.currency || '', eventId || '');
            }
            <?php if (!empty($atc)) : ?>
            mdpSendATC({
                content_ids: [<?php echo wp_json_encode($atc['id']); ?>],
                content_type: 'product',
                value: <?php echo floatval($atc['value']); ?>,
                currency: <?php echo wp_json_encode($atc['currency']); ?>
            }, <?php echo wp_json_encode($event_id); ?>);
            <?php endif; ?>
            if (window.jQuery) {
                jQuery(document.body).on('added_to_cart', function (e, fragments, hash, button) {
                    var pid = (button && button.data) ? String(button.data('product_id') || '') : '';
                    mdpSendATC(pid ? {content_ids: [pid], content_type: 'product'} : {});
                });
            } else {
                document.body.addEventListener('added_to_cart', function () { mdpSendATC({}); });
            }
        })();
        </script>
        <?php
    }

    /** Начало оформления заказа. */
    public function initiate_checkout() {
        if (mdp_is_excluded()) return;
        if (!function_exists('WC') || !WC()->cart) return;
        $total = WC()->cart->get_total('edit');
        ?>
        <script>
        var mdpIcId = window.mdpEventId ? mdpEventId('ic') : 'ic.' + Date.now() + '.' + Math.random().toString(16).slice(2);
        if (window.fbq) fbq('track', 'InitiateCheckout', Object.assign({
            value: <?php echo floatval($total); ?>,
            currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
            num_items: <?php echo intval(WC()->cart->get_cart_contents_count()); ?>
        }, <?php echo $this->attr_js(); ?>), {eventID: mdpIcId});
        if (window.mdpTrack) mdpTrack('InitiateCheckout', <?php echo floatval($total); ?>, '<?php echo esc_js(get_woocommerce_currency()); ?>', mdpIcId);
        </script>
        <?php
    }

    /**
     * Покупка на странице "Спасибо". Браузерное событие + дублирование на сервере.
     * Purchase шлётся только для оплаченных статусов (см. purchase_status_ok),
     * дедуп браузер/сервер — по общему event_id 'purchase.ORDER_ID'.
     */
    public function purchase($order_id) {
        if (mdp_is_excluded()) return;
        if (!$order_id) return;
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Неоплаченный/отменённый заказ (failed, cancelled, pending, on-hold) не
        // засчитываем как покупку. Для on-hold/async серверное событие уйдёт позже
        // по хуку woocommerce_payment_complete, когда оплата подтвердится.
        if (!$this->purchase_status_ok($order)) {
            return;
        }

        // Серверное событие — один раз, с расширенным сопоставлением из заказа.
        $this->maybe_capi_purchase($order_id);

        // Браузерное событие
        $event_id    = 'purchase.' . $order_id;
        $custom      = $this->purchase_custom($order);
        $value       = $custom['value'];
        $currency    = $custom['currency'];
        $content_ids = $custom['content_ids'];
        ?>
        <script>
        if (window.fbq) fbq('track', 'Purchase', Object.assign({
            value: <?php echo $value; ?>,
            currency: '<?php echo esc_js($currency); ?>',
            content_ids: <?php echo wp_json_encode($content_ids); ?>,
            content_type: 'product',
            num_items: <?php echo intval($order->get_item_count()); ?>
        }, <?php echo $this->attr_js(); ?>), {eventID: '<?php echo esc_js($event_id); ?>'});
        if (window.mdpTrack) mdpTrack('Purchase', <?php echo $value; ?>, '<?php echo esc_js($currency); ?>', '<?php echo esc_js($event_id); ?>');
        </script>
        <?php
    }
}
