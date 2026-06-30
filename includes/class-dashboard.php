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

    private function money($v, $cur = '') {
        return number_format_i18n((float) $v, 2) . ($cur ? ' ' . $cur : '');
    }

    public function render() {
        if (!current_user_can('manage_options')) return;

        $days  = isset($_GET['range']) ? max(1, intval($_GET['range'])) : 7;
        $allowed_ranges = array(1, 7, 30, 90);
        if (!in_array($days, $allowed_ranges, true)) $days = 7;

        $totals = MDP_Logger::totals($days);
        $funnel = MDP_Logger::funnel($days);
        $byday  = MDP_Logger::by_day($days);
        $origins = MDP_Logger::top('origin', $days);
        $sources = MDP_Logger::top('utm_source', $days);
        $campaigns = MDP_Logger::top('utm_campaign', $days);
        $recent = MDP_Logger::recent(25);

        $logging_on = mdp_get('enable_logging');
        $base = admin_url('admin.php?page=meta-dynamic-pixel');
        ?>
        <div class="wrap mdp-dash">
            <h1 style="display:flex;align-items:center;gap:12px">
                Meta Pixel — Аналитика
                <span style="font-size:12px;font-weight:400;color:#646970">данные внутри сайта, без обращения к Meta</span>
            </h1>

            <?php if (!$logging_on) : ?>
                <div class="notice notice-warning"><p>Логирование выключено. Включите его в «Настройках», чтобы собирать аналитику.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['cleared'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Данные аналитики очищены.</p></div>
            <?php endif; ?>

            <p class="mdp-ranges">
                <?php
                $labels = array(1 => 'Сегодня', 7 => '7 дней', 30 => '30 дней', 90 => '90 дней');
                foreach ($labels as $r => $label) {
                    $cls = ($r === $days) ? 'button button-primary' : 'button';
                    printf('<a class="%s" href="%s">%s</a> ', esc_attr($cls), esc_url(add_query_arg('range', $r, $base)), esc_html($label));
                }
                ?>
            </p>

            <!-- KPI -->
            <div class="mdp-cards">
                <?php
                $this->card('Событий', number_format_i18n($totals['events']));
                $this->card('Покупок', number_format_i18n($totals['purchases']));
                $this->card('Выручка', $this->money($totals['revenue'], mdp_get('thankyou_currency')));
                $this->card('Средний чек', $this->money($totals['aov'], mdp_get('thankyou_currency')));
                $this->card('Браузер (Pixel)', number_format_i18n($totals['browser']));
                $this->card('Сервер (CAPI)', number_format_i18n($totals['server']));
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

            <div class="mdp-grid3">
                <div class="mdp-box"><h2>Откуда пришли (origin)</h2><?php $this->top_table($origins); ?></div>
                <div class="mdp-box"><h2>UTM Source</h2><?php $this->top_table($sources); ?></div>
                <div class="mdp-box"><h2>UTM Campaign</h2><?php $this->top_table($campaigns); ?></div>
            </div>

            <!-- Последние события -->
            <div class="mdp-box">
                <h2>Последние события</h2>
                <table class="widefat striped">
                    <thead><tr>
                        <th>Время</th><th>Событие</th><th>Канал</th><th>Сумма</th>
                        <th>Origin</th><th>UTM Source</th><th>Статус</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($recent)) : ?>
                        <tr><td colspan="7">Пока нет данных. Откройте сайт как обычный посетитель — события появятся здесь.</td></tr>
                    <?php else : foreach ($recent as $r) : ?>
                        <tr>
                            <td><?php echo esc_html(get_date_from_gmt($r->created_at, 'd.m H:i')); ?></td>
                            <td><strong><?php echo esc_html($r->event_name); ?></strong></td>
                            <td><?php echo $r->channel === 'server' ? '🖥 CAPI' : '🌐 Pixel'; ?></td>
                            <td><?php echo $r->value > 0 ? esc_html($this->money($r->value, $r->currency)) : '—'; ?></td>
                            <td><?php echo esc_html($r->origin ?: '—'); ?></td>
                            <td><?php echo esc_html($r->utm_source ?: '—'); ?></td>
                            <td><?php echo esc_html($r->status); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('Удалить все собранные данные аналитики?');" style="margin-top:16px">
                <input type="hidden" name="action" value="mdp_clear_data">
                <?php wp_nonce_field('mdp_clear_data'); ?>
                <button type="submit" class="button button-secondary">Очистить данные аналитики</button>
            </form>
        </div>

        <style>
            .mdp-cards{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin:16px 0}
            @media(max-width:1200px){.mdp-cards{grid-template-columns:repeat(3,1fr)}}
            @media(max-width:782px){.mdp-cards{grid-template-columns:repeat(2,1fr)}}
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
