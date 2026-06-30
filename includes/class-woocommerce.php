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
        }
        if (mdp_get('track_initiatecheckout')) {
            add_action('woocommerce_after_checkout_form', array($this, 'initiate_checkout'));
        }
        if (mdp_get('track_purchase')) {
            add_action('woocommerce_thankyou', array($this, 'purchase'), 10, 1);
        }
    }

    private function attr_js() {
        return wp_json_encode(MDP_Attribution::attribution_payload() ?: new stdClass());
    }

    /** Просмотр товара. */
    public function view_content() {
        if (mdp_is_excluded()) return;
        global $product;
        if (!$product instanceof WC_Product) return;
        $id = MDP_Pixel::event_id('vc');
        ?>
        <script>
        if (window.fbq) fbq('track', 'ViewContent', Object.assign({
            content_ids: ['<?php echo esc_js($product->get_id()); ?>'],
            content_name: <?php echo wp_json_encode($product->get_name()); ?>,
            content_type: 'product',
            value: <?php echo floatval($product->get_price()); ?>,
            currency: '<?php echo esc_js(get_woocommerce_currency()); ?>'
        }, <?php echo $this->attr_js(); ?>), {eventID: '<?php echo esc_js($id); ?>'});
        if (window.mdpTrack) mdpTrack('ViewContent', <?php echo floatval($product->get_price()); ?>, '<?php echo esc_js(get_woocommerce_currency()); ?>', '<?php echo esc_js($id); ?>');
        </script>
        <?php
    }

    /** Добавление в корзину (через AJAX-кнопки Woo). */
    public function add_to_cart_listener() {
        if (mdp_is_excluded()) return;
        ?>
        <script>
        document.body.addEventListener('added_to_cart', function () {
            if (window.fbq) fbq('track', 'AddToCart', window.mdpAttr || {});
            if (window.mdpTrack) mdpTrack('AddToCart', 0, '');
        });
        </script>
        <?php
    }

    /** Начало оформления заказа. */
    public function initiate_checkout() {
        if (mdp_is_excluded()) return;
        if (!function_exists('WC') || !WC()->cart) return;
        $id = MDP_Pixel::event_id('ic');
        $total = WC()->cart->get_total('edit');
        ?>
        <script>
        if (window.fbq) fbq('track', 'InitiateCheckout', Object.assign({
            value: <?php echo floatval($total); ?>,
            currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
            num_items: <?php echo intval(WC()->cart->get_cart_contents_count()); ?>
        }, <?php echo $this->attr_js(); ?>), {eventID: '<?php echo esc_js($id); ?>'});
        if (window.mdpTrack) mdpTrack('InitiateCheckout', <?php echo floatval($total); ?>, '<?php echo esc_js(get_woocommerce_currency()); ?>', '<?php echo esc_js($id); ?>');
        </script>
        <?php
    }

    /**
     * Покупка на странице "Спасибо". Браузерное событие + дублирование на сервере.
     * Защита от повтора: помечаем заказ мета-ключом.
     */
    public function purchase($order_id) {
        if (mdp_is_excluded()) return;
        if (!$order_id) return;
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Один event_id на заказ -> браузер и сервер дедуплицируются
        $event_id = 'purchase.' . $order_id;

        $content_ids = array();
        foreach ($order->get_items() as $item) {
            $content_ids[] = (string) $item->get_product_id();
        }

        $value    = floatval($order->get_total());
        $currency = $order->get_currency();

        $custom = array_merge(array(
            'value'        => $value,
            'currency'     => $currency,
            'content_ids'  => $content_ids,
            'content_type' => 'product',
            'order_id'     => (string) $order_id,
        ), MDP_Attribution::attribution_payload());

        // Серверное событие (с расширенным сопоставлением из данных заказа) — один раз
        if (!$order->get_meta('_mdp_capi_sent')) {
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
                    : (isset($_COOKIE['mdp_xid']) ? sanitize_text_field(wp_unslash($_COOKIE['mdp_xid'])) : ''),
            );
            $this->capi->send('Purchase', $event_id, $custom, $user);
            $order->update_meta_data('_mdp_capi_sent', '1');
            $order->save();
        }

        // Браузерное событие
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
