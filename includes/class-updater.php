<?php
// includes/class-updater.php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub Releases 更新檢查
 *
 * - 讀取 GitHub 儲存庫最新 Release，接入 WordPress 內建的外掛更新機制
 *   （外掛列表的更新提示、「立即更新」、WordPress 的外掛自動更新開關）。
 * - Release 請附上外掛 ZIP 檔（壓縮檔最外層資料夾名稱須為 risecreatives-optimization）。
 *   若沒有附 ZIP，會改用 GitHub 自動產生的原始碼 ZIP，並在安裝時自動修正資料夾名稱。
 * - Release 的 tag 名稱即版本號，例如 v1.3.1 或 1.3.1。
 *
 * 也可在 wp-config.php 以常數設定（優先於後台設定）：
 *   define('RISECREATIVES_GITHUB_REPO',  'owner/repo');
 *   define('RISECREATIVES_GITHUB_TOKEN', 'ghp_xxx');  // 私有儲存庫才需要
 */
class RiseCreatives_Optimization_Updater {
    private static $instance = null;

    const OPTION_NAME = 'risecreatives_opt_updater';
    const CACHE_KEY   = 'risecreatives_opt_update_info';
    const CACHE_TTL   = 43200; // 12 小時
    const ERROR_TTL   = 3600;  // 查詢失敗時 1 小時內不重試，避免頻繁請求 GitHub
    const SLUG        = 'risecreatives-optimization';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // WordPress 內建更新機制
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clear_cache_after_upgrade'], 10, 2);

        // 私有儲存庫下載授權
        add_filter('http_request_args', [$this, 'add_download_auth'], 10, 2);
        add_action('requests-requests.before_redirect', [$this, 'strip_auth_on_redirect'], 10, 5);

        // 後台操作
        add_action('admin_post_risecreatives_check_update', [$this, 'handle_manual_check']);
        add_action('admin_post_risecreatives_save_updater', [$this, 'handle_save_settings']);

        // 外掛列表快速連結
        add_filter('plugin_action_links_' . RISECREATIVES_OPT_BASENAME, [$this, 'add_action_links']);
    }

    /* ------------------------------------------------------------------
     * 設定
     * ------------------------------------------------------------------ */

    private function get_saved_settings() {
        $saved = get_option(self::OPTION_NAME, []);
        return is_array($saved) ? $saved : [];
    }

    /**
     * 取得 GitHub 儲存庫（owner/repo），格式不正確時回傳空字串
     */
    public function get_repo() {
        if (defined('RISECREATIVES_GITHUB_REPO') && RISECREATIVES_GITHUB_REPO) {
            $repo = RISECREATIVES_GITHUB_REPO;
        } else {
            $saved = $this->get_saved_settings();
            $repo  = isset($saved['repo']) ? $saved['repo'] : '';
        }

        return $this->normalize_repo($repo);
    }

    public function get_token() {
        if (defined('RISECREATIVES_GITHUB_TOKEN') && RISECREATIVES_GITHUB_TOKEN) {
            return (string) RISECREATIVES_GITHUB_TOKEN;
        }

        $saved = $this->get_saved_settings();
        return isset($saved['token']) ? (string) $saved['token'] : '';
    }

    public function is_repo_locked() {
        return defined('RISECREATIVES_GITHUB_REPO') && RISECREATIVES_GITHUB_REPO;
    }

    public function is_token_locked() {
        return defined('RISECREATIVES_GITHUB_TOKEN') && RISECREATIVES_GITHUB_TOKEN;
    }

    /**
     * 接受 owner/repo 或完整 GitHub 網址，回傳 owner/repo；不合法則回傳空字串
     */
    private function normalize_repo($repo) {
        $repo = trim((string) $repo);
        $repo = preg_replace('#^https?://(www\.)?github\.com/#i', '', $repo);
        $repo = trim($repo, '/');
        $repo = preg_replace('#\.git$#i', '', $repo);

        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            return '';
        }

        return $repo;
    }

    /* ------------------------------------------------------------------
     * 取得最新 Release
     * ------------------------------------------------------------------ */

    private function api_get($path) {
        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'RiseCreatives-Optimization/' . RISECREATIVES_OPT_VERSION,
        ];

        $token = $this->get_token();
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return wp_remote_get('https://api.github.com' . $path, [
            'timeout' => 10,
            'headers' => $headers,
        ]);
    }

    /**
     * 取得最新 Release 資訊（有快取）。
     *
     * @param bool $force 為 true 時忽略快取，立即向 GitHub 查詢
     * @return array 一定包含 'error'（空字串表示成功），成功時另含 version、package、body 等欄位
     */
    public function fetch_latest($force = false) {
        $repo = $this->get_repo();

        if ($repo === '') {
            return ['error' => '尚未設定 GitHub 儲存庫（格式：owner/repo）。', 'repo' => ''];
        }

        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['repo']) && $cached['repo'] === $repo) {
                return $cached;
            }
        }

        $result = [
            'repo'       => $repo,
            'error'      => '',
            'checked_at' => time(),
        ];

        $response = $this->api_get('/repos/' . $repo . '/releases/latest');

        if (is_wp_error($response)) {
            $result['error'] = '無法連線到 GitHub：' . $response->get_error_message();
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $data = json_decode(wp_remote_retrieve_body($response), true);

            if ($code === 200 && is_array($data) && !empty($data['tag_name'])) {
                $result = array_merge($result, $this->parse_release($data));
            } elseif ($code === 404) {
                $result['error'] = '找不到 Release。請確認儲存庫名稱正確、已發布至少一個 Release（非草稿或預發布版），私有儲存庫需要設定 Token。';
            } elseif ($code === 401) {
                $result['error'] = 'GitHub 驗證失敗，請檢查 Token 是否正確或已過期。';
            } elseif ($code === 403 || $code === 429) {
                $result['error'] = 'GitHub 請求次數已達上限，請稍後再試（或設定 Token 以提高上限）。';
            } else {
                $result['error'] = 'GitHub 回應異常（HTTP ' . $code . '）。';
            }
        }

        set_transient(self::CACHE_KEY, $result, $result['error'] === '' ? self::CACHE_TTL : self::ERROR_TTL);

        return $result;
    }

    private function parse_release($data) {
        $tag     = (string) $data['tag_name'];
        $package = '';

        // 優先使用 Release 附加的 ZIP 檔
        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (empty($asset['name']) || !preg_match('/\.zip$/i', $asset['name'])) {
                    continue;
                }

                // 有 Token（可能是私有儲存庫）時使用 API 網址下載，否則使用公開下載網址
                if ($this->get_token() !== '' && !empty($asset['url'])) {
                    $package = $asset['url'];
                } elseif (!empty($asset['browser_download_url'])) {
                    $package = $asset['browser_download_url'];
                }
                break;
            }
        }

        // 沒有附 ZIP 時，改用 GitHub 自動產生的原始碼 ZIP（安裝時會自動修正資料夾名稱）
        if ($package === '' && !empty($data['zipball_url'])) {
            $package = $data['zipball_url'];
        }

        return [
            'tag'          => $tag,
            'version'      => ltrim($tag, 'vV'),
            'name'         => isset($data['name']) ? (string) $data['name'] : $tag,
            'body'         => isset($data['body']) ? (string) $data['body'] : '',
            'html_url'     => isset($data['html_url']) ? (string) $data['html_url'] : '',
            'published_at' => isset($data['published_at']) ? (string) $data['published_at'] : '',
            'package'      => $this->is_allowed_package_url($package) ? $package : '',
        ];
    }

    /**
     * 只接受 GitHub 的 https 下載網址
     */
    private function is_allowed_package_url($url) {
        if ($url === '') {
            return false;
        }

        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https' || empty($parts['host'])) {
            return false;
        }

        $allowed_hosts = ['github.com', 'api.github.com', 'codeload.github.com', 'objects.githubusercontent.com'];
        return in_array(strtolower($parts['host']), $allowed_hosts, true);
    }

    /**
     * 供設定頁使用：目前版本、最新版本與狀態
     */
    public function get_status($force = false) {
        $info = $this->fetch_latest($force);

        $has_update = $info['error'] === ''
            && !empty($info['version'])
            && version_compare($info['version'], RISECREATIVES_OPT_VERSION, '>');

        return [
            'current'    => RISECREATIVES_OPT_VERSION,
            'info'       => $info,
            'has_update' => $has_update,
            'repo'       => $this->get_repo(),
            'has_token'  => $this->get_token() !== '',
        ];
    }

    public function get_update_url() {
        return wp_nonce_url(
            self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode(RISECREATIVES_OPT_BASENAME)),
            'upgrade-plugin_' . RISECREATIVES_OPT_BASENAME
        );
    }

    /* ------------------------------------------------------------------
     * 接入 WordPress 更新機制
     * ------------------------------------------------------------------ */

    public function inject_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }

        $info = $this->fetch_latest(false);
        if ($info['error'] !== '' || empty($info['version'])) {
            return $transient;
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = [];
        }

        $item = (object) [
            'id'          => 'github.com/' . $info['repo'],
            'slug'        => self::SLUG,
            'plugin'      => RISECREATIVES_OPT_BASENAME,
            'new_version' => $info['version'],
            'url'         => $info['html_url'] ? $info['html_url'] : 'https://www.risecreatives.co',
            'package'     => $info['package'],
            'icons'       => [],
            'banners'     => [],
        ];

        if (version_compare($info['version'], RISECREATIVES_OPT_VERSION, '>') && $info['package'] !== '') {
            $transient->response[RISECREATIVES_OPT_BASENAME] = $item;
            unset($transient->no_update[RISECREATIVES_OPT_BASENAME]);
        } else {
            $item->new_version = RISECREATIVES_OPT_VERSION;
            $item->package     = '';
            $transient->no_update[RISECREATIVES_OPT_BASENAME] = $item;
            unset($transient->response[RISECREATIVES_OPT_BASENAME]);
        }

        return $transient;
    }

    /**
     * 外掛列表「查看詳細資料」視窗
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }

        $info = $this->fetch_latest(false);
        $ok   = $info['error'] === '' && !empty($info['version']);

        $changelog = ($ok && $info['body'] !== '')
            ? '<pre style="white-space:pre-wrap;">' . esc_html($info['body']) . '</pre>'
            : '<p>尚無更新說明。</p>';

        return (object) [
            'name'          => 'RiseCreatives Optimization',
            'slug'          => self::SLUG,
            'version'       => $ok ? $info['version'] : RISECREATIVES_OPT_VERSION,
            'author'        => '<a href="https://www.risecreatives.co">RiseCreatives 展躍網路</a>',
            'homepage'      => 'https://www.risecreatives.co',
            'download_link' => $ok ? $info['package'] : '',
            'last_updated'  => $ok ? $info['published_at'] : '',
            'sections'      => [
                'description' => '展躍網路客製化優化外掛，提供多種 WordPress 優化功能。',
                'changelog'   => $changelog,
            ],
        ];
    }

    /**
     * 安裝更新時，確保解壓縮後的資料夾名稱為 risecreatives-optimization。
     * （GitHub 自動產生的原始碼 ZIP 資料夾名稱為「owner-repo-hash」，直接安裝會變成另一個外掛。）
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = []) {
        global $wp_filesystem;

        $is_ours = false;
        if (!empty($hook_extra['plugin']) && $hook_extra['plugin'] === RISECREATIVES_OPT_BASENAME) {
            $is_ours = true;
        } elseif (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])
            && in_array(RISECREATIVES_OPT_BASENAME, $hook_extra['plugins'], true)) {
            $is_ours = true;
        }

        if (!$is_ours || !is_string($source) || !$wp_filesystem) {
            return $source;
        }

        $desired = trailingslashit($remote_source) . self::SLUG . '/';

        if (untrailingslashit($source) !== untrailingslashit($desired)) {
            if (!$wp_filesystem->move($source, $desired, true)) {
                return new WP_Error('risecreatives_rename_failed', '無法修正更新檔的資料夾名稱，更新已中止。');
            }
            $source = $desired;
        }

        // 確認壓縮檔內確實是本外掛
        if (!$wp_filesystem->exists(trailingslashit($source) . 'risecreatives-optimization.php')) {
            return new WP_Error('risecreatives_invalid_package', '更新檔內容不正確（找不到 risecreatives-optimization.php），更新已中止。');
        }

        return $source;
    }

    public function clear_cache_after_upgrade($upgrader, $hook_extra) {
        if (empty($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
            return;
        }

        $plugins = [];
        if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
            $plugins = $hook_extra['plugins'];
        } elseif (!empty($hook_extra['plugin'])) {
            $plugins = [$hook_extra['plugin']];
        }

        if (in_array(RISECREATIVES_OPT_BASENAME, $plugins, true)) {
            delete_transient(self::CACHE_KEY);
        }
    }

    /* ------------------------------------------------------------------
     * 私有儲存庫下載授權（僅對本外掛的 GitHub API 網址附加 Token）
     * ------------------------------------------------------------------ */

    public function add_download_auth($args, $url) {
        $token = $this->get_token();
        $repo  = $this->get_repo();

        if ($token === '' || $repo === '' || !is_string($url)) {
            return $args;
        }

        $parts = wp_parse_url($url);
        if (empty($parts['host']) || strtolower($parts['host']) !== 'api.github.com') {
            return $args;
        }

        if (empty($parts['path']) || strpos($parts['path'], '/repos/' . $repo . '/') !== 0) {
            return $args;
        }

        if (!isset($args['headers']) || !is_array($args['headers'])) {
            $args['headers'] = [];
        }

        $args['headers']['Authorization'] = 'Bearer ' . $token;

        if (strpos($parts['path'], '/releases/assets/') !== false) {
            $args['headers']['Accept'] = 'application/octet-stream';
        }

        return $args;
    }

    /**
     * 轉址到 GitHub 以外的主機（如 S3）時，不可帶著 Token
     */
    public function strip_auth_on_redirect(&$location, &$headers, &$data, &$options, $original = null) {
        $host = wp_parse_url((string) $location, PHP_URL_HOST);

        if ($host && strtolower($host) !== 'api.github.com') {
            unset($headers['Authorization'], $headers['authorization']);
        }
    }

    /* ------------------------------------------------------------------
     * 後台操作
     * ------------------------------------------------------------------ */

    public function add_action_links($links) {
        $url = admin_url('admin.php?page=risecreatives-optimization-version');
        array_unshift($links, '<a href="' . esc_url($url) . '">版本資訊</a>');
        return $links;
    }

    private function redirect_to_page($notice, $message = '') {
        $args = ['page' => 'risecreatives-optimization-version', 'risecreatives_notice' => $notice];
        if ($message !== '') {
            $args['risecreatives_msg'] = $message;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function handle_manual_check() {
        if (!current_user_can('manage_options')) {
            wp_die(__('您沒有足夠的權限執行此操作。', 'risecreatives-optimization'));
        }
        check_admin_referer('risecreatives_check_update');

        delete_transient(self::CACHE_KEY);
        $info = $this->fetch_latest(true);

        // 讓 WordPress 的外掛更新資料同步（不必等排程）
        $update_plugins = get_site_transient('update_plugins');
        if (!is_object($update_plugins)) {
            $update_plugins = new stdClass();
        }
        set_site_transient('update_plugins', $this->inject_update($update_plugins));

        if ($info['error'] !== '') {
            $this->redirect_to_page('check_error', $info['error']);
        }

        $this->redirect_to_page('checked');
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(__('您沒有足夠的權限執行此操作。', 'risecreatives-optimization'));
        }
        check_admin_referer('risecreatives_save_updater');

        $saved = $this->get_saved_settings();

        if (!$this->is_repo_locked()) {
            $raw_repo = isset($_POST['github_repo']) ? sanitize_text_field(wp_unslash($_POST['github_repo'])) : '';
            $repo     = $this->normalize_repo($raw_repo);

            if ($raw_repo !== '' && $repo === '') {
                $this->redirect_to_page('invalid_repo');
            }

            $saved['repo'] = $repo;
        }

        if (!$this->is_token_locked()) {
            if (!empty($_POST['clear_github_token'])) {
                $saved['token'] = '';
            } elseif (isset($_POST['github_token']) && trim((string) $_POST['github_token']) !== '') {
                $saved['token'] = sanitize_text_field(wp_unslash($_POST['github_token']));
            }
        }

        update_option(self::OPTION_NAME, $saved, false);
        delete_transient(self::CACHE_KEY);

        $this->redirect_to_page('saved');
    }
}

// 初始化更新檢查
function risecreatives_optimization_updater() {
    return RiseCreatives_Optimization_Updater::get_instance();
}

add_action('plugins_loaded', 'risecreatives_optimization_updater');
