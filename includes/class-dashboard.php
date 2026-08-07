<?php
if (!defined('ABSPATH')) exit;

/**
 * Встроенная аналитика: верхнеуровневое меню + страница с KPI, воронкой,
 * графиком по дням, топом источников/origin и последними событиями.
 */
class MDP_Dashboard {

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 9);
        add_action('admin_post_mdp_clear_data', array($this, 'clear_data'));
        add_action('admin_post_mdp_fix_legacy', array($this, 'fix_legacy'));
    }

    public function menu() {
        add_menu_page(
            'Meta Pixel',
            'Meta Pixel',
            'manage_options',
            'meta-dynamic-pixel',
            array($this, 'render'),
            'dashicons-chart-area',
            58
        );
        add_submenu_page(
            'meta-dynamic-pixel',
            'Аналитика',
            'Аналитика',
            'manage_options',
            'meta-dynamic-pixel',
            array($this, 'render')
        );
    }

    public function clear_data() {
        if (!current_user_can('manage_options') || !check_admin_referer('mdp_clear_data')) {
            wp_die('forbidden');
        }
        MDP_Logger::truncate();
        wp_safe_redirect(add_query_arg(array('page' => 'meta-dynamic-pixel', 'cleared' => '1'), admin_url('admin.php')));
        exit;
    }

    /** Символ валюты перед суммой (£/$/€/₽), иначе код после суммы. */
    /** Переклассификация старых «покупок», которые на деле были заявками. */
    public function fix_legacy() {
        if (!current_user_can('manage_options') || !check_admin_referer('mdp_fix_legacy')) {
            wp_die('forbidden');
        }
        $n = MDP_Logger::fix_legacy_leads();
        wp_safe_redirect(add_query_arg(
            array('page' => 'meta-dynamic-pixel', 'fixed' => $n),
            admin_url('admin.php')
        ));
        exit;
    }

    private function money($v, $cur = '') {
        $symbols = array('GBP' => '£', 'USD' => '$', 'EUR' => '€', 'RUB' => '₽', 'UAH' => '₴');
        $num = number_format_i18n((float) $v, 2);
        if ($cur === '' || $cur === '—') {
            return $num;
        }
        return isset($symbols[$cur]) ? $symbols[$cur] . $num : $num . ' ' . $cur;
    }

    /** Деньги по всем валютам через разделитель: «£17.97 · $50.00». */
    private function money_multi($by_currency, $key) {
        if (empty($by_currency)) {
            return $this->money(0);
        }
        $parts = array();
        foreach ($by_currency as $c) {
            $parts[] = $this->money($c[$key], $c['currency']);
        }
        return implode('  ·  ', $parts);
    }

    public function render() {
        if (!current_user_can('manage_options')) return;

        $days  = isset($_GET['range']) ? max(1, intval($_GET['range'])) : 7;
        $allowed_ranges = array(1, 7, 30, 90);
        if (!in_array($days, $allowed_ranges, true)) $days = 7;

        $totals = MDP_Logger::totals($days);
        $funnel = MDP_Logger::funnel($days);
        $byday  = MDP_Logger::by_day($days);
        $src_col = isset($_GET['src']) ? sanitize_key($_GET['src']) : 'origin';
        if (!in_array($src_col, array('origin', 'utm_source', 'utm_campaign'), true)) {
            $src_col = 'origin';
        }
        $by_source = MDP_Logger::by_source($src_col, $days);
        // Фильтр ленты событий: найти покупку среди сотен PageView иначе нереально.
        $ev_filter = isset($_GET['ev']) ? sanitize_text_field(wp_unslash($_GET['ev'])) : '';
        $ev_types  = array('PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Lead', 'Purchase');
        if (!in_array($ev_filter, $ev_types, true)) {
            $ev_filter = '';
        }
        $day  = isset($_GET['day']) ? sanitize_text_field(wp_unslash($_GET['day'])) : '';
        if ($day !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $day = '';
        }
        $page_n  = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
        $src_val = isset($_GET['sv']) ? sanitize_text_field(wp_unslash($_GET['sv'])) : '';
        $log = MDP_Logger::events(array(
            'event_name' => $ev_filter,
            'day'        => $day,
            'src_col'    => $src_col,
            'src_val'    => $src_val,
            'per_page'   => 50,
            'page'       => $page_n,
        ));
        $recent = $log['rows'];

        // Реальные заказы магазина — источник истины при сверке с трафик-менеджером.
        $orders = class_exists('MDP_WooCommerce')
            ? MDP_WooCommerce::orders_report(300, $src_col, $src_val)
            : array();

        $logging_on = mdp_get('enable_logging');
        $base = admin_url('admin.php?page=meta-dynamic-pixel');
        ?>
        <div class="wrap mdp-dash">
            <h1 style="display:flex;align-items:center;gap:12px">
                Meta Pixel — Аналитика
                <span style="font-size:12px;font-weight:400;color:#646970">данные внутри сайта, без обращения к Meta</span>
            </h1>

            <?php
            // Явно предупреждаем, если серверная отправка не работает: без неё Meta
            // теряет события пользователей с блокировщиками, и цифры расходятся.
            $capi_reason = MDP_CAPI::inactive_reason();
            if ($capi_reason) :
            ?>
                <div class="notice notice-warning">
                    <p><strong>Conversions API не работает</strong> — <?php echo esc_html($capi_reason); ?>.
                    Без серверной отправки часть событий не доходит до Meta (блокировщики рекламы, iOS).
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mdp-settings')); ?>">Открыть настройки</a></p>
                </div>
            <?php endif; ?>

            <?php if (!$logging_on) : ?>
                <div class="notice notice-warning"><p>Логирование выключено. Включите его в «Настройках», чтобы собирать аналитику.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['cleared'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Данные аналитики очищены.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['fixed'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Исправлено записей: <?php echo intval($_GET['fixed']); ?>. Теперь «Покупки» — только реальные оплаченные заказы WooCommerce.</p></div>
            <?php endif; ?>

            <?php
            // До версии 1.2.0 заход на страницу «Спасибо» записывался как Purchase.
            // Из-за этого «Покупок» показывало заявки вместе с реальными заказами.
            $legacy = MDP_Logger::legacy_lead_count();
            if ($legacy) :
            ?>
                <div class="notice notice-error">
                    <p><strong>Найдено <?php echo intval($legacy); ?> записей, где заявка со страницы «Спасибо» посчитана покупкой.</strong><br>
                    Так работали версии до 1.2.0 — из-за этого «Покупок» и «Выручка» завышены.
                    Реальные заказы WooCommerce не пострадают: они отличаются по event_id.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          onsubmit="return confirm('Переклассифицировать <?php echo intval($legacy); ?> записей из Purchase в Lead?');" style="margin:8px 0 12px">
                        <input type="hidden" name="action" value="mdp_fix_legacy">
                        <?php wp_nonce_field('mdp_fix_legacy'); ?>
                        <button type="submit" class="button button-primary">Исправить: пометить их как Лиды</button>
                    </form>
                </div>
            <?php endif; ?>

            <p class="mdp-ranges">
                <?php
                $labels = array(1 => 'Сегодня', 7 => '7 дней', 30 => '30 дней', 90 => '90 дней');
                foreach ($labels as $r => $label) {
                    $cls = ($r === $days) ? 'button button-primary' : 'button';
                    printf('<a class="%s" href="%s">%s</a> ', esc_attr($cls), esc_url(add_query_arg(array('range' => $r, 'src' => $src_col), $base)), esc_html($label));
                }
                ?>
            </p>

            <!-- KPI -->
            <div class="mdp-cards">
                <?php
                $this->card('Событий (уник.)', number_format_i18n($totals['events']));
                $this->card('Лидов', number_format_i18n($totals['leads']));
                $this->card('Покупок', number_format_i18n($totals['purchases']));
                $this->card('Выручка', $this->money_multi($totals['by_currency'], 'revenue'));
                $this->card('Средний чек', $this->money_multi($totals['by_currency'], 'aov'));
                $this->card('Записей: браузер', number_format_i18n($totals['browser']));
                $this->card('Записей: сервер', number_format_i18n($totals['server']));
                ?>
            </div>

            <div class="mdp-grid2">
                <!-- График по дням -->
                <div class="mdp-box">
                    <h2>События по дням</h2>
                    <?php $this->bar_chart($byday); ?>
                </div>

                <!-- Воронка -->
                <div class="mdp-box">
                    <h2>Воронка</h2>
                    <?php $this->funnel_chart($funnel); ?>
                </div>
            </div>

            <!-- Главный отчёт: какой трафик приносит лиды и деньги -->
            <div class="mdp-box">
                <h2 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    Источники → лиды → покупки
                    <span style="font-weight:400;color:#646970;font-size:12px">разрез:</span>
                    <?php
                    $cols = array('origin' => 'Источник', 'utm_source' => 'UTM Source', 'utm_campaign' => 'UTM Campaign');
                    foreach ($cols as $c => $lbl) {
                        $cls = ($c === $src_col) ? 'button button-small button-primary' : 'button button-small';
                        printf('<a class="%s" href="%s">%s</a> ',
                            esc_attr($cls),
                            esc_url(add_query_arg(array('range' => $days, 'src' => $c), $base)),
                            esc_html($lbl));
                    }
                    ?>
                </h2>
                <?php
                $src_link = function ($label) use ($base, $days, $src_col) {
                    return esc_url(add_query_arg(array(
                        'range' => $days, 'src' => $src_col, 'sv' => $label, 'p' => 1,
                    ), $base) . '#log');
                };
                $this->source_table($by_source, $src_link, $src_val);
                ?>
                <p class="description" style="margin:8px 0 0">Нажмите на источник, чтобы посмотреть его события и заказы.</p>
            </div>

            <?php if ($src_val !== '') : ?>
                <div class="notice notice-info" style="margin:0 0 16px">
                    <p>Фильтр по источнику: <strong><?php echo esc_html($src_val); ?></strong>
                    (<?php echo esc_html($cols[$src_col] ?? $src_col); ?>) —
                    журнал и заказы ниже показаны только для него.
                    <a href="<?php echo esc_url(add_query_arg(array('range' => $days, 'src' => $src_col), $base)); ?>">Сбросить</a></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($orders) || class_exists('MDP_WooCommerce')) : ?>
            <!-- Сверка с реальными заказами магазина -->
            <div class="mdp-box">
                <h2>Заказы WooCommerce <span style="font-weight:400;color:#646970;font-size:12px">— реальные заказы магазина, включая неоплаченные</span></h2>
                <?php $this->orders_table($orders); ?>
            </div>
            <?php endif; ?>

            <!-- Последние события -->
            <div class="mdp-box">
                <?php
                // Ссылка журнала с сохранением всех фильтров (кроме переопределённых).
                $log_url = function ($over = array()) use ($base, $days, $src_col, $ev_filter, $day, $src_val) {
                    return esc_url(add_query_arg(array_merge(array(
                        'range' => $days, 'src' => $src_col, 'ev' => $ev_filter,
                        'day' => $day, 'sv' => $src_val, 'p' => 1,
                    ), $over), $base) . '#log');
                };
                $all_days = MDP_Logger::available_days();
                ?>
                <h2 id="log" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    Журнал событий
                    <span style="font-weight:400;color:#646970;font-size:12px">
                        всего: <?php echo esc_html(number_format_i18n($log['total'])); ?>
                    </span>
                </h2>

                <p style="margin:0 0 10px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                    <span style="color:#646970;font-size:12px">Событие:</span>
                    <?php
                    $ev_labels = array('' => 'Все') + array_combine($ev_types, $ev_types);
                    foreach ($ev_labels as $ev => $lbl) {
                        $cls = ($ev === $ev_filter) ? 'button button-small button-primary' : 'button button-small';
                        printf('<a class="%s" href="%s">%s</a>', esc_attr($cls), $log_url(array('ev' => $ev)), esc_html($lbl));
                    }
                    ?>
                </p>

                <p style="margin:0 0 12px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                    <span style="color:#646970;font-size:12px">День:</span>
                    <a class="<?php echo $day === '' ? 'button button-small button-primary' : 'button button-small'; ?>"
                       href="<?php echo $log_url(array('day' => '')); ?>">Все дни</a>
                    <?php foreach (array_slice((array) $all_days, 0, 14) as $d) :
                        $cls = ($d === $day) ? 'button button-small button-primary' : 'button button-small'; ?>
                        <a class="<?php echo esc_attr($cls); ?>" href="<?php echo $log_url(array('day' => $d)); ?>">
                            <?php echo esc_html(date_i18n('d.m', strtotime($d))); ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if (count((array) $all_days) > 14) : ?>
                        <select onchange="if(this.value)location.href=this.value" style="height:26px;font-size:12px">
                            <option value="">— другой день —</option>
                            <?php foreach ((array) $all_days as $d) : ?>
                                <option value="<?php echo $log_url(array('day' => $d)); ?>" <?php selected($d, $day); ?>>
                                    <?php echo esc_html(date_i18n('d.m.Y', strtotime($d))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </p>
                <table class="widefat striped">
                    <thead><tr>
                        <th>Время</th><th>Событие</th><th>Канал</th><th>Сумма</th>
                        <th>Origin</th><th>UTM Source</th><th>ID события</th><th>Статус</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($recent)) : ?>
                        <tr><td colspan="8">Пока нет данных. Откройте сайт как обычный посетитель — события появятся здесь.</td></tr>
                    <?php else : foreach ($recent as $r) : ?>
                        <tr>
                            <td style="white-space:nowrap"><?php echo esc_html(get_date_from_gmt($r->created_at, 'd.m.Y H:i:s')); ?></td>
                            <td><strong><?php echo esc_html($r->event_name); ?></strong></td>
                            <td><?php echo $r->channel === 'server' ? '🖥 CAPI' : '🌐 Pixel'; ?></td>
                            <td><?php echo $r->value > 0 ? esc_html($this->money($r->value, $r->currency)) : '—'; ?></td>
                            <td><?php echo esc_html($r->origin ?: '—'); ?></td>
                            <td><?php echo esc_html($r->utm_source ?: '—'); ?></td>
                            <td><code style="font-size:11px"><?php echo esc_html($r->event_id ?: '—'); ?></code></td>
                            <td><?php echo esc_html($r->status); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <?php if ($log['pages'] > 1) :
                    $cur = $log['page'];
                    $pg  = function ($n) use ($log_url) { return $log_url(array('p' => $n)); };
                ?>
                <div style="margin-top:12px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                    <?php if ($cur > 1) : ?>
                        <a class="button button-small" href="<?php echo $pg(1); ?>">« В начало</a>
                        <a class="button button-small" href="<?php echo $pg($cur - 1); ?>">‹ Назад</a>
                    <?php endif; ?>

                    <span style="font-size:13px;color:#646970">
                        Страница <strong><?php echo intval($cur); ?></strong> из <?php echo intval($log['pages']); ?>
                    </span>

                    <?php if ($cur < $log['pages']) : ?>
                        <a class="button button-small" href="<?php echo $pg($cur + 1); ?>">Вперёд ›</a>
                        <a class="button button-small" href="<?php echo $pg($log['pages']); ?>">В конец »</a>
                    <?php endif; ?>

                    <select onchange="if(this.value)location.href=this.value" style="height:26px;font-size:12px;margin-left:8px">
                        <?php for ($i = 1; $i <= $log['pages']; $i++) : ?>
                            <option value="<?php echo $pg($i); ?>" <?php selected($i, $cur); ?>>
                                стр. <?php echo intval($i); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('Удалить все собранные данные аналитики?');" style="margin-top:16px">
                <input type="hidden" name="action" value="mdp_clear_data">
                <?php wp_nonce_field('mdp_clear_data'); ?>
                <button type="submit" class="button button-secondary">Очистить данные аналитики</button>
            </form>
        </div>

        <style>
            .mdp-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin:16px 0}
            .mdp-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px}
            .mdp-card .l{color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
            .mdp-card .v{font-size:24px;font-weight:700;margin-top:6px;color:#1d2327}
            .mdp-box{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 18px;margin-bottom:16px}
            .mdp-box h2{margin:0 0 12px;font-size:14px}
            .mdp-grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
            .mdp-grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
            @media(max-width:960px){.mdp-grid2,.mdp-grid3{grid-template-columns:1fr}}
            .mdp-bars{display:flex;align-items:flex-end;gap:4px;height:160px}
            .mdp-bars .col{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:4px}
            .mdp-bars .bar{width:100%;background:linear-gradient(180deg,#4a7cff,#2851d6);border-radius:4px 4px 0 0;min-height:2px}
            .mdp-bars .cap{font-size:10px;color:#646970;white-space:nowrap}
            .mdp-funnel .step{margin:8px 0}
            .mdp-funnel .step .row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:3px}
            .mdp-funnel .track{background:#eef1f6;border-radius:6px;overflow:hidden;height:22px}
            .mdp-funnel .fill{height:100%;background:linear-gradient(90deg,#4a7cff,#2851d6)}
            .mdp-ranges .button{margin-right:4px}
            .mdp-tt{width:100%;border-collapse:collapse}
            .mdp-tt td{padding:5px 0;font-size:13px;border-bottom:1px solid #f0f0f1}
            .mdp-tt .n{text-align:right;color:#646970}
        </style>
        <?php
    }

    private function card($label, $value) {
        printf('<div class="mdp-card"><div class="l">%s</div><div class="v">%s</div></div>',
            esc_html($label), esc_html($value));
    }

    /** Простой столбчатый график без внешних библиотек. */
    private function bar_chart($series) {
        $max = max(1, max($series ?: array(0)));
        echo '<div class="mdp-bars">';
        foreach ($series as $day => $count) {
            $h = round(($count / $max) * 100);
            $cap = date_i18n('d.m', strtotime($day));
            printf(
                '<div class="col" title="%s: %s"><div class="bar" style="height:%d%%"></div><span class="cap">%s</span></div>',
                esc_attr($cap), esc_attr($count), $h, esc_html($cap)
            );
        }
        echo '</div>';
    }

    /** Воронка с процентами конверсии относительно первого шага. */
    private function funnel_chart($funnel) {
        $first = max(1, reset($funnel));
        echo '<div class="mdp-funnel">';
        foreach ($funnel as $name => $count) {
            $pct = round(($count / $first) * 100);
            printf(
                '<div class="step"><div class="row"><span>%s</span><span>%s &middot; %d%%</span></div>
                 <div class="track"><div class="fill" style="width:%d%%"></div></div></div>',
                esc_html($name), esc_html(number_format_i18n($count)), $pct, $pct
            );
        }
        echo '</div>';
    }

    /**
     * Реальные заказы магазина: оплаченные и нет, с источником. Именно эта таблица
     * отвечает на вопрос «сколько на самом деле было покупок с такой-то метки».
     */
    private function orders_table($orders) {
        if (empty($orders)) {
            echo '<p style="color:#646970;font-size:13px">Заказов не найдено. Если фильтр по источнику активен — у этого источника заказов нет.</p>';
            return;
        }

        // Сводка: сколько оплачено, сколько нет, на какую сумму.
        $paid = $unpaid = 0;
        $revenue = array();
        foreach ($orders as $o) {
            if ($o->paid) {
                $paid++;
                $cur = $o->currency ?: '';
                $revenue[$cur] = ($revenue[$cur] ?? 0) + $o->total;
            } else {
                $unpaid++;
            }
        }
        $rev_str = array();
        foreach ($revenue as $cur => $sum) {
            $rev_str[] = $this->money($sum, $cur);
        }

        printf(
            '<p style="margin:0 0 12px;font-size:14px">
                Всего заказов: <strong>%d</strong> &nbsp;·&nbsp;
                <span style="color:#1a7f37">оплачено: <strong>%d</strong></span> &nbsp;·&nbsp;
                <span style="color:#b32d2e">не оплачено: <strong>%d</strong></span> &nbsp;·&nbsp;
                выручка: <strong>%s</strong>
            </p>',
            count($orders), $paid, $unpaid, esc_html($rev_str ? implode(' · ', $rev_str) : '—')
        );

        $labels = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();

        echo '<div style="overflow-x:auto;max-height:520px"><table class="widefat striped"><thead><tr>'
            . '<th>Заказ</th><th>Дата</th><th>Статус</th><th>Сумма</th>'
            . '<th>Источник</th><th>UTM Source</th><th>UTM Campaign</th><th>CAPI</th>'
            . '</tr></thead><tbody>';

        foreach ($orders as $o) {
            $status_label = $labels['wc-' . $o->status] ?? $o->status;
            printf(
                '<tr><td><a href="%s">#%d</a></td><td style="white-space:nowrap">%s</td>
                 <td><span style="color:%s;font-weight:600">%s</span></td><td>%s</td>
                 <td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_url($o->edit_url ?: admin_url('admin.php?page=wc-orders&action=edit&id=' . $o->id)),
                $o->id,
                esc_html($o->date),
                $o->paid ? '#1a7f37' : '#b32d2e',
                esc_html($status_label),
                esc_html($this->money($o->total, $o->currency)),
                esc_html($o->origin ?: '—'),
                esc_html($o->utm_source ?: '—'),
                esc_html($o->utm_campaign ?: '—'),
                $o->capi_sent ? '✅' : '—'
            );
        }
        echo '</tbody></table></div>';
    }

    /** Таблица «источник → визиты → лиды → покупки → выручка». */
    private function source_table($rows, $link = null, $active = '') {
        if (empty($rows)) {
            echo '<p style="color:#646970;font-size:13px">Нет данных за период.</p>';
            return;
        }
        echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>'
            . '<th>Источник</th><th>Визиты</th><th>Лиды</th><th>Покупки</th>'
            . '<th>Выручка</th><th>Визит→лид</th><th>Лид→покупка</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $visits    = (int) $r->visits;
            $leads     = (int) $r->leads;
            $purchases = (int) $r->purchases;

            $rev_parts = array();
            foreach ((array) $r->revenue as $rev) {
                $rev_parts[] = $this->money($rev['sum'], $rev['currency']);
            }
            $cr_lead = $visits ? round($leads / $visits * 100, 1) . '%' : '—';
            $cr_buy  = $leads ? round($purchases / $leads * 100, 1) . '%' : '—';

            // Клик по источнику фильтрует журнал и список заказов по нему.
            $label = $link
                ? sprintf('<a href="%s">%s</a>', $link($r->label), esc_html($r->label))
                : esc_html($r->label);
            printf(
                '<tr%s><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                ($active !== '' && $r->label === $active) ? ' style="background:#fff6e5"' : '',
                $label,
                esc_html(number_format_i18n($visits)),
                $leads ? esc_html(number_format_i18n($leads)) : '—',
                $purchases ? '<strong>' . esc_html(number_format_i18n($purchases)) . '</strong>' : '—',
                $rev_parts ? esc_html(implode(' · ', $rev_parts)) : '—',
                esc_html($cr_lead),
                esc_html($cr_buy)
            );
        }
        echo '</tbody></table></div>';
    }

    private function top_table($rows) {
        if (empty($rows)) {
            echo '<p style="color:#646970;font-size:13px">Нет данных.</p>';
            return;
        }
        echo '<table class="mdp-tt">';
        foreach ($rows as $r) {
            printf('<tr><td>%s</td><td class="n">%s</td></tr>',
                esc_html($r->label), esc_html(number_format_i18n($r->c)));
        }
        echo '</table>';
    }
}
