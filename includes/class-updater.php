<?php
if (!defined('ABSPATH')) exit;

/**
 * Обновление плагина напрямую из релизов GitHub.
 *
 * Как только в репозитории публикуется новый Release с тегом-версией (например v1.1.2),
 * WordPress показывает обновление в разделе «Плагины» и ставит его в один клик — как для
 * плагинов из официального каталога. Версия берётся из тега релиза и сравнивается с локальной.
 *
 * Репозиторий должен быть публичным (или нужно расширить класс заголовком авторизации).
 */
class MDP_GitHub_Updater {

    private $file;       // абсолютный путь к основному файлу плагина (__FILE__)
    private $basename;   // meta-dynamic-pixel/meta-dynamic-pixel.php
    private $slug;       // meta-dynamic-pixel
    private $repo;       // owner/repo на GitHub
    private $version;    // текущая установленная версия
    private $cache_key = 'mdp_gh_release';
    private $cache_ttl  = 21600; // кэш ответа GitHub на 6 часов (бережём rate limit)

    public function __construct($file, $repo, $version) {
        $this->file     = $file;
        $this->repo     = $repo;
        $this->version  = $version;
        $this->basename = plugin_basename($file);
        $this->slug     = dirname($this->basename);
        if ($this->slug === '.' || $this->slug === '') {
            $this->slug = basename($file, '.php');
        }

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_source_selection', array($this, 'fix_source_dir'), 10, 4);
        add_action('upgrader_process_complete', array($this, 'clear_cache'), 10, 2);
    }

    /** Получить последний релиз с GitHub (с кэшем). */
    private function get_release() {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached === 'none' ? null : $cached;
        }

        $url = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $res = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
            ),
        ));

        if (is_wp_error($res) || 200 !== (int) wp_remote_retrieve_response_code($res)) {
            // короткий «отрицательный» кэш, чтобы не долбить API при сбоях
            set_transient($this->cache_key, 'none', 10 * MINUTE_IN_SECONDS);
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($res));
        if (!is_object($data) || empty($data->tag_name)) {
            set_transient($this->cache_key, 'none', 10 * MINUTE_IN_SECONDS);
            return null;
        }

        set_transient($this->cache_key, $data, $this->cache_ttl);
        return $data;
    }

    /** Версия из тега релиза (v1.1.2 -> 1.1.2). */
    private function remote_version($release) {
        return ltrim((string) $release->tag_name, 'vV');
    }

    /** Откуда качать: приложенный zip-ассет, иначе авто-zip исходников. */
    private function package_url($release) {
        if (!empty($release->assets) && is_array($release->assets)) {
            foreach ($release->assets as $asset) {
                if (!empty($asset->browser_download_url) && '.zip' === substr($asset->name, -4)) {
                    return $asset->browser_download_url;
                }
            }
        }
        return isset($release->zipball_url) ? $release->zipball_url : '';
    }

    /** Подмешать обновление в транзиент WP, если на GitHub версия новее. */
    public function check_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }
        $release = $this->get_release();
        if (!$release) {
            return $transient;
        }

        $remote = $this->remote_version($release);
        $item = array(
            'slug'         => $this->slug,
            'plugin'       => $this->basename,
            'new_version'  => $remote,
            'url'          => 'https://github.com/' . $this->repo,
            'package'      => $this->package_url($release),
            'icons'        => array(),
            'banners'      => array(),
            'tested'       => get_bloginfo('version'),
            'requires_php' => '7.2',
        );

        if (version_compare($remote, $this->version, '>')) {
            $transient->response[$this->basename] = (object) $item;
        } else {
            // фиксируем «обновлений нет», чтобы плагин корректно отображался
            $transient->no_update[$this->basename] = (object) $item;
        }
        return $transient;
    }

    /** Карточка «Просмотр сведений» в админке. */
    public function plugin_info($result, $action, $args) {
        if ('plugin_information' !== $action) {
            return $result;
        }
        if (empty($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }
        $release = $this->get_release();
        if (!$release) {
            return $result;
        }

        $info = array(
            'name'          => 'Meta Dynamic Pixel',
            'slug'          => $this->slug,
            'version'       => $this->remote_version($release),
            'author'        => '<a href="https://github.com/' . $this->repo . '">Degree Team</a>',
            'homepage'      => 'https://github.com/' . $this->repo,
            'requires'      => '5.5',
            'requires_php'  => '7.2',
            'download_link' => $this->package_url($release),
            'trunk'         => $this->package_url($release),
            'sections'      => array(
                'description' => 'Динамический пиксель Meta (Facebook/Instagram) с Conversions API, UTM-атрибуцией и встроенной аналитикой.',
                'changelog'   => !empty($release->body) ? wpautop(esc_html($release->body)) : 'См. GitHub Releases.',
            ),
        );
        if (!empty($release->published_at)) {
            $info['last_updated'] = $release->published_at;
        }
        return (object) $info;
    }

    /**
     * Авто-zip GitHub распаковывается в папку owner-repo-<hash>. Переименовываем её
     * в slug плагина, иначе WordPress посчитает это новым плагином.
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = array()) {
        global $wp_filesystem;
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) {
            return $source;
        }
        if (!$wp_filesystem) {
            return $source;
        }
        $desired = trailingslashit($remote_source) . $this->slug . '/';
        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source;
        }
        if ($wp_filesystem->move($source, $desired, true)) {
            return $desired;
        }
        return $source;
    }

    /** Сбросить кэш релиза после обновления плагина. */
    public function clear_cache($upgrader, $options) {
        if (
            isset($options['action'], $options['type'])
            && 'update' === $options['action']
            && 'plugin' === $options['type']
        ) {
            delete_transient($this->cache_key);
        }
    }
}
