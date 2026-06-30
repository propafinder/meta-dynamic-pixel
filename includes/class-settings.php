<?php
if (!defined('ABSPATH')) exit;

/**
 * Страница настроек в админке: ID пикселя, токен CAPI, какие события отслеживать и т.д.
 */
class MDP_Settings {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register'));
        add_filter('plugin_action_links_' . plugin_basename(MDP_FILE), array($this, 'action_links'));
    }

    public function action_links($links) {
        $url = admin_url('admin.php?page=mdp-settings');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Настройки', 'meta-dynamic-pixel') . '</a>');
        return $links;
    }

    public function add_menu() {
        add_submenu_page(
            'meta-dynamic-pixel',
            'Настройки',
            'Настройки',
            'manage_options',
            'mdp-settings',
            array($this, 'render')
        );
    }

    public function register() {
        register_setting('mdp_group', MDP_OPTION, array($this, 'sanitize'));
    }

    /**
     * Очистка и валидация настроек перед сохранением.
     */
    public function sanitize($input) {
        $out = mdp_default_settings();
        $existing = get_option(MDP_OPTION, array());
        if (!is_array($existing)) {
            $existing = array();
        }

        $out['pixel_id']          = preg_replace('/[^0-9]/', '', $input['pixel_id'] ?? '');

        // Токен в форме не показываем (поле всегда пустое). Пустой ввод = «оставить как есть»,
        // непустой = заменить. Так секрет не утекает в исходник страницы настроек.
        $token_in = sanitize_text_field($input['access_token'] ?? '');
        $out['access_token'] = ($token_in === '' && !empty($existing['access_token']))
            ? $existing['access_token']
            : $token_in;

        $out['test_event_code']  = sanitize_text_field($input['test_event_code'] ?? '');
        $out['attribution_days']  = max(1, min(365, intval($input['attribution_days'] ?? 90)));
        $out['retention_days']    = max(1, min(365, intval($input['retention_days'] ?? 90)));
        $out['thankyou_page_ids'] = sanitize_text_field($input['thankyou_page_ids'] ?? '');
        $out['thankyou_value']    = sanitize_text_field($input['thankyou_value'] ?? '');
        $out['thankyou_currency'] = sanitize_text_field($input['thankyou_currency'] ?? 'RUB');

        $checkboxes = array(
            'enable_capi', 'enable_advanced_matching', 'track_pageview',
            'track_viewcontent', 'track_addtocart', 'track_initiatecheckout',
            'track_purchase', 'capi_pageview', 'enable_logging', 'exclude_admins',
        );
        foreach ($checkboxes as $cb) {
            $out[$cb] = empty($input[$cb]) ? 0 : 1;
        }

        return $out;
    }

    private function cb($s, $key, $label, $hint = '') {
        printf(
            '<label style="display:block;margin:4px 0"><input type="checkbox" name="%s[%s]" value="1" %s> %s</label>',
            esc_attr(MDP_OPTION), esc_attr($key), checked(1, $s[$key], false), esc_html($label)
        );
        if ($hint) {
            echo '<p class="description" style="margin:0 0 8px 24px">' . esc_html($hint) . '</p>';
        }
    }

    public function render() {
        if (!current_user_can('manage_options')) return;
        $s = mdp_get_settings();
        $name = esc_attr(MDP_OPTION);
        ?>
        <div class="wrap">
            <h1>Meta Dynamic Pixel</h1>
            <p>Динамический пиксель Meta с UTM-атрибуцией, реферером, origin лида и серверным Conversions API.</p>
            <form method="post" action="options.php">
                <?php settings_fields('mdp_group'); ?>

                <h2 class="title">Основное</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label>Pixel ID</label></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo $name; ?>[pixel_id]"
                                   value="<?php echo esc_attr($s['pixel_id']); ?>" placeholder="напр. 123456789012345">
                            <p class="description">ID пикселя из Meta Events Manager.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Access Token (CAPI)</label></th>
                        <td>
                            <input type="password" class="regular-text" name="<?php echo $name; ?>[access_token]"
                                   value="" autocomplete="new-password"
                                   placeholder="<?php echo $s['access_token'] ? esc_attr('•••••••• сохранён — оставьте пустым, чтобы не менять') : ''; ?>">
                            <p class="description">Токен Conversions API. Нужен только при серверной отправке событий.<?php echo $s['access_token'] ? ' <strong>Токен уже сохранён.</strong> Введите новый, чтобы заменить.' : ''; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Test Event Code</label></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo $name; ?>[test_event_code]"
                                   value="<?php echo esc_attr($s['test_event_code']); ?>" placeholder="TEST12345">
                            <p class="description">Код для проверки серверных событий в Test Events. Уберите после теста.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Хранение атрибуции</label></th>
                        <td>
                            <input type="number" min="1" max="365" name="<?php echo $name; ?>[attribution_days]"
                                   value="<?php echo esc_attr($s['attribution_days']); ?>"> дней
                            <p class="description">Сколько хранить UTM/реферер/origin в cookie до момента покупки.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Conversions API (серверные события)</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Серверная отправка</th>
                        <td>
                            <?php $this->cb($s, 'enable_capi', 'Включить Conversions API (CAPI)', 'События дублируются с сервера. Дедупликация по event_id выполняется автоматически.'); ?>
                            <?php $this->cb($s, 'enable_advanced_matching', 'Расширенное сопоставление (хэшировать email/телефон/имя)'); ?>
                            <?php $this->cb($s, 'capi_pageview', 'Дублировать PageView через сервер', 'Обычно не требуется. Включайте только при необходимости.'); ?>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Какие события отслеживать</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">События</th>
                        <td>
                            <?php $this->cb($s, 'track_pageview', 'PageView (просмотр страницы)'); ?>
                            <?php $this->cb($s, 'track_viewcontent', 'ViewContent (просмотр товара)'); ?>
                            <?php $this->cb($s, 'track_addtocart', 'AddToCart (добавление в корзину)'); ?>
                            <?php $this->cb($s, 'track_initiatecheckout', 'InitiateCheckout (начало оформления)'); ?>
                            <?php $this->cb($s, 'track_purchase', 'Purchase (покупка — на странице "Спасибо")'); ?>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Страница "Спасибо" без WooCommerce</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label>ID страниц</label></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo $name; ?>[thankyou_page_ids]"
                                   value="<?php echo esc_attr($s['thankyou_page_ids']); ?>" placeholder="напр. 42, 108">
                            <p class="description">ID страниц благодарности (через запятую). На них сработает Purchase. Для WooCommerce настраивать не нужно — событие ставится автоматически.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Значение / валюта</label></th>
                        <td>
                            <input type="text" name="<?php echo $name; ?>[thankyou_value]"
                                   value="<?php echo esc_attr($s['thankyou_value']); ?>" placeholder="0" style="width:120px">
                            <input type="text" name="<?php echo $name; ?>[thankyou_currency]"
                                   value="<?php echo esc_attr($s['thankyou_currency']); ?>" style="width:90px">
                            <p class="description">Можно переопределить через URL: <code>?value=1990&currency=RUB</code>.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Встроенная аналитика</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Сбор данных</th>
                        <td>
                            <?php $this->cb($s, 'enable_logging', 'Вести аналитику внутри плагина', 'События пишутся в отдельную таблицу БД и видны в разделе «Аналитика». В Meta ничего дополнительно не отправляется.'); ?>
                            <?php $this->cb($s, 'exclude_admins', 'Не учитывать команду (редакторы и админы)', 'Чтобы заходы сотрудников не засоряли статистику и не слались в пиксель.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Хранение данных</label></th>
                        <td>
                            <input type="number" min="1" max="365" name="<?php echo $name; ?>[retention_days]"
                                   value="<?php echo esc_attr($s['retention_days']); ?>"> дней
                            <p class="description">Записи старше этого срока удаляются автоматически раз в сутки.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
