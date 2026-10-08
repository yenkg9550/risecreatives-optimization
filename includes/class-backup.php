<?php
// includes/class-backup.php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 備份管理
 *
 * - 備份內容（可勾選）：資料庫、上傳檔案、外掛、主題，打包成單一 ZIP。
 * - 存放位置：本機（wp-content 下受保護的隨機名稱資料夾，一律保存）＋ 選用 GitHub（私有儲存庫的草稿 Release 附件）
 *   ＋ 選用 Google Drive（OAuth）。
 * - 排程：關閉／每天／每週／每月（WP-Cron），可設定保留份數。
 * - 手動：立即備份、下載、刪除、還原。
 *
 * 長時間作業（備份、還原）在背景執行，進度寫入備份資料夾內的 status.json，後台以 AJAX 輪詢顯示。
 */
class RiseCreatives_Optimization_Backup {
    private static $instance = null;

    const OPTION_NAME = 'risecreatives_opt_backup';
    const DIR_OPTION  = 'risecreatives_opt_backup_dir';
    const CRON_HOOK   = 'risecreatives_opt_backup_cron';
    const NONCE       = 'risecreatives_backup';
    const STALE_AFTER = 900; // 執行中但超過 15 分鐘沒有進度，視為已中斷

    private $zip_batch = 300;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action(self::CRON_HOOK, array($this, 'run_scheduled'));
        add_action('init', array($this, 'ensure_schedule'));

        add_action('admin_post_risecreatives_backup_save', array($this, 'handle_save_settings'));
        add_action('admin_post_risecreatives_backup_download', array($this, 'handle_download'));
        add_action('admin_post_risecreatives_backup_gdrive_auth', array($this, 'handle_gdrive_auth'));
        add_action('admin_post_risecreatives_backup_gdrive_cb', array($this, 'handle_gdrive_callback'));
        add_action('admin_post_risecreatives_backup_gdrive_disconnect', array($this, 'handle_gdrive_disconnect'));

        add_action('wp_ajax_risecreatives_backup_start', array($this, 'ajax_start_backup'));
        add_action('wp_ajax_risecreatives_backup_restore', array($this, 'ajax_start_restore'));
        add_action('wp_ajax_risecreatives_backup_status', array($this, 'ajax_status'));
        add_action('wp_ajax_risecreatives_backup_delete', array($this, 'ajax_delete'));
        add_action('wp_ajax_risecreatives_backup_test', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_risecreatives_backup_autosave', array($this, 'ajax_autosave'));
    }

    /* ------------------------------------------------------------------
     * 設定
     * ------------------------------------------------------------------ */

    public static function defaults() {
        return array(
            'include_db'           => 1,
            'include_uploads'      => 1,
            'include_plugins'      => 0,
            'include_themes'       => 0,
            'include_core'         => 0,
            'frequency'            => 'off',   // off | daily | weekly | monthly
            'hour'                 => 3,
            'keep_local'           => 7,
            'github_enabled'       => 0,
            'github_repo'          => '',
            'github_token'         => '',
            'keep_github'          => 7,
            'gdrive_enabled'       => 0,
            'gdrive_client_id'     => '',
            'gdrive_client_secret' => '',
            'gdrive_refresh_token' => '',
            'gdrive_folder_id'     => '',
            'keep_gdrive'          => 7,
        );
    }

    public function get_settings() {
        $saved = get_option(self::OPTION_NAME, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
    }

    public function save_settings($settings) {
        update_option(self::OPTION_NAME, $settings, false);
    }

    public function is_zip_available() {
        return class_exists('ZipArchive');
    }

    /* ------------------------------------------------------------------
     * 備份資料夾
     * ------------------------------------------------------------------ */

    public function get_dir($create = true) {
        $name = get_option(self::DIR_OPTION);

        if (!is_string($name) || !preg_match('/^risecreatives-backups-[a-z0-9]{10}$/', $name)) {
            $name = '';
            // 設定遺失時（例如資料庫被還原），沿用既有的備份資料夾，避免備份檔變成孤兒
            $found = glob(trailingslashit(WP_CONTENT_DIR) . 'risecreatives-backups-*', GLOB_ONLYDIR);
            if (!empty($found)) {
                $candidate = basename($found[0]);
                if (preg_match('/^risecreatives-backups-[a-z0-9]{10}$/', $candidate)) {
                    $name = $candidate;
                }
            }
            if ($name === '') {
                $name = 'risecreatives-backups-' . strtolower(wp_generate_password(10, false, false));
            }
            update_option(self::DIR_OPTION, $name, false);
        }

        $dir = trailingslashit(WP_CONTENT_DIR) . $name;

        if ($create && !is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if ($create && is_dir($dir)) {
            $this->protect_dir($dir);
        }

        return $dir;
    }

    private function protect_dir($dir) {
        $files = array(
            '.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
            'index.php' => "<?php\n// Silence is golden.\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        );
        foreach ($files as $name => $content) {
            $path = $dir . '/' . $name;
            if (!file_exists($path)) {
                @file_put_contents($path, $content);
            }
        }
    }

    /* ------------------------------------------------------------------
     * 狀態與鎖（存成檔案：還原資料庫時 options 資料表會被覆寫，不能放在資料庫）
     * ------------------------------------------------------------------ */

    public function read_status() {
        $file = $this->get_dir(false) . '/status.json';
        $data = is_readable($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($data)) {
            $data = array('state' => 'idle');
        }

        if (isset($data['state']) && $data['state'] === 'running') {
            $updated = isset($data['updated']) ? (int) $data['updated'] : 0;
            if ($updated < time() - self::STALE_AFTER) {
                $data['state']   = 'failed';
                $data['message'] = '作業已中斷（超過 15 分鐘沒有進度，可能是主機逾時或記憶體不足）。';
            }
        }

        return $data;
    }

    private function write_status($patch) {
        $dir = $this->get_dir();
        $cur = $this->read_status_raw($dir);
        $new = array_merge($cur, $patch, array('updated' => time()));
        @file_put_contents($dir . '/status.json', wp_json_encode($new), LOCK_EX);
        return $new;
    }

    private function read_status_raw($dir) {
        $file = $dir . '/status.json';
        $data = is_readable($file) ? json_decode((string) @file_get_contents($file), true) : null;
        return is_array($data) ? $data : array();
    }

    public function is_running() {
        $st = $this->read_status();
        return isset($st['state']) && $st['state'] === 'running';
    }

    private function progress($step, $percent, $message) {
        $this->write_status(array(
            'state'   => 'running',
            'step'    => $step,
            'percent' => max(0, min(100, (int) $percent)),
            'message' => $message,
        ));
    }

    /* ------------------------------------------------------------------
     * 排程
     * ------------------------------------------------------------------ */

    public function add_cron_schedules($schedules) {
        if (!isset($schedules['risecreatives_monthly'])) {
            $schedules['risecreatives_monthly'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => '每月一次',
            );
        }
        return $schedules;
    }

    private function recurrence_for($frequency) {
        $map = array(
            'daily'   => 'daily',
            'weekly'  => 'weekly',
            'monthly' => 'risecreatives_monthly',
        );
        return isset($map[$frequency]) ? $map[$frequency] : '';
    }

    /**
     * 讓排程與設定一致（設定變更、外掛更新後都會自我修正）
     */
    public function ensure_schedule($force = false) {
        $force = ($force === true);

        $s          = $this->get_settings();
        $recurrence = $this->recurrence_for($s['frequency']);
        $next       = wp_next_scheduled(self::CRON_HOOK);

        if ($recurrence === '') {
            if ($next) {
                wp_clear_scheduled_hook(self::CRON_HOOK);
            }
            return;
        }

        $current = $next ? wp_get_schedule(self::CRON_HOOK) : false;
        if ($next && $current === $recurrence && !$force) {
            return;
        }

        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_event($this->next_run_timestamp((int) $s['hour']), $recurrence, self::CRON_HOOK);
    }

    private function next_run_timestamp($hour) {
        $hour = max(0, min(23, (int) $hour));
        $tz   = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $now  = new DateTime('now', $tz);
        $run  = clone $now;
        $run->setTime($hour, 0, 0);
        if ($run <= $now) {
            $run->modify('+1 day');
        }
        return $run->getTimestamp();
    }

    public function clear_schedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function get_next_run() {
        return wp_next_scheduled(self::CRON_HOOK);
    }

    public function run_scheduled() {
        if ($this->is_running()) {
            return;
        }
        $this->write_status(array(
            'state'    => 'running',
            'kind'     => 'backup',
            'trigger'  => 'auto',
            'started'  => time(),
            'finished' => 0,
            'percent'  => 0,
            'step'     => 'init',
            'message'  => '排程備份開始',
            'backup'   => '',
            'error'    => '',
        ));
        $this->run_backup('auto');
    }

    /* ------------------------------------------------------------------
     * 備份清單（每份備份有一個同名的 .json 描述檔）
     * ------------------------------------------------------------------ */

    private function meta_path($base) {
        return $this->get_dir() . '/' . $base . '.json';
    }

    private function zip_path($base) {
        return $this->get_dir() . '/' . $base . '.zip';
    }

    public static function is_valid_base($base) {
        return is_string($base) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{3,120}$/', $base) && substr($base, -4) !== '.zip';
    }

    private function read_meta($base) {
        $file = $this->meta_path($base);
        $data = is_readable($file) ? json_decode((string) @file_get_contents($file), true) : null;
        return is_array($data) ? $data : null;
    }

    private function write_meta($base, $meta) {
        @file_put_contents($this->meta_path($base), wp_json_encode($meta), LOCK_EX);
    }

    /**
     * 取得備份清單（新到舊）
     */
    public function list_backups() {
        $dir   = $this->get_dir();
        $items = array();

        foreach ((array) glob($dir . '/*.json') as $file) {
            $base = basename($file, '.json');
            if ($base === 'status' || !self::is_valid_base($base)) {
                continue;
            }
            $meta = json_decode((string) @file_get_contents($file), true);
            if (!is_array($meta)) {
                continue;
            }
            $zip = $this->zip_path($base);

            $meta['base']       = $base;
            $meta['zip_exists'] = is_file($zip);
            $meta['size']       = $meta['zip_exists'] ? (int) filesize($zip) : (isset($meta['size']) ? (int) $meta['size'] : 0);
            $meta['created']    = isset($meta['created']) ? (int) $meta['created'] : (int) @filemtime($file);
            $items[] = $meta;
        }

        usort($items, function ($a, $b) {
            return $b['created'] - $a['created'];
        });

        return $items;
    }

    /* ------------------------------------------------------------------
     * 執行環境
     * ------------------------------------------------------------------ */

    private function prepare_environment() {
        @ignore_user_abort(true);
        @set_time_limit(0);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('session_write_close')) {
            @session_write_close();
        }
    }

    /**
     * 先回應瀏覽器，再在背景繼續執行（不依賴 WP-Cron，DISABLE_WP_CRON 的網站也可用）
     */
    private function respond_and_continue($data) {
        $this->prepare_environment();

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        $body = wp_json_encode(array('success' => true, 'data' => $data));
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
        echo $body;

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
    }

    private function register_failure_handler($label) {
        register_shutdown_function(function () use ($label) {
            $st = $this->read_status();
            if (isset($st['state']) && $st['state'] === 'running') {
                $err  = error_get_last();
                $text = $err ? $err['message'] : '未知原因';
                $this->write_status(array(
                    'state'    => 'failed',
                    'finished' => time(),
                    'message'  => $label . '失敗：' . $text,
                    'error'    => $text,
                ));
            }
        });
    }

    /* ------------------------------------------------------------------
     * 備份
     * ------------------------------------------------------------------ */

    /**
     * 執行備份。成功回傳備份代碼（base），失敗回傳 false（狀態檔會記錄原因）。
     *
     * @param string     $trigger  auto | manual | pre_restore
     * @param array|null $override 覆寫設定（例如還原前的安全備份只備份資料庫）
     */
    public function run_backup($trigger = 'manual', $override = null) {
        $this->prepare_environment();
        $this->register_failure_handler('備份');

        $dir  = $this->get_dir();
        $tmp  = array();
        $base = '';

        try {
            if (!$this->is_zip_available()) {
                throw new Exception('此主機沒有安裝 PHP ZipArchive 擴充，無法建立備份。');
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                throw new Exception('備份資料夾無法寫入，請確認 wp-content 資料夾的寫入權限。');
            }
            $free = @disk_free_space($dir);
            if ($free !== false && $free < 50 * MB_IN_BYTES) {
                throw new Exception('主機剩餘空間不足 50 MB，已取消備份。');
            }

            $s = $this->get_settings();
            if (is_array($override)) {
                $s = array_merge($s, $override);
            }
            if (!$s['include_db'] && !$s['include_uploads'] && !$s['include_plugins'] && !$s['include_themes'] && !$s['include_core']) {
                throw new Exception('尚未勾選任何備份內容。');
            }

            $host = sanitize_title((string) wp_parse_url(home_url(), PHP_URL_HOST));
            $host = $host !== '' ? $host : 'site';
            $base = $host . '-' . wp_date('Ymd-His') . '-' . strtolower(wp_generate_password(6, false, false));

            $kind = $trigger === 'pre_restore' ? 'restore' : 'backup';
            $this->write_status(array(
                'state'    => 'running',
                'kind'     => $kind,
                'trigger'  => $trigger,
                'step'     => 'init',
                'percent'  => 1,
                'message'  => '準備備份',
                'backup'   => $base,
                'error'    => '',
                'finished' => 0,
            ));

            $contents = array();
            $sql_file = '';
            $db_tables = 0;

            // 1. 資料庫
            if ($s['include_db']) {
                $this->progress('db', 3, '匯出資料庫');
                $sql_file = $dir . '/.tmp-' . $base . '.sql';
                $tmp[]    = $sql_file;
                $db_tables = $this->dump_database($sql_file);
                $contents[] = 'db';
            }

            // 2. 檔案清單
            $this->progress('scan', 15, '掃描檔案');
            $roots = array();
            if ($s['include_uploads']) {
                $up = wp_upload_dir(null, false);
                $roots['uploads'] = $up['basedir'];
                $contents[] = 'uploads';
            }
            if ($s['include_plugins']) {
                $roots['plugins'] = WP_PLUGIN_DIR;
                $contents[] = 'plugins';
            }
            if ($s['include_themes']) {
                $roots['themes'] = get_theme_root();
                $contents[] = 'themes';
            }

            $list = array();
            foreach ($roots as $label => $root) {
                if ($root && is_dir($root)) {
                    $this->collect_files($root, 'files/' . $label, $list);
                }
            }

            // WordPress 核心：wp-admin、wp-includes 與網站根目錄的檔案（含 wp-config.php）；不含 wp-content
            if ($s['include_core']) {
                $this->collect_core_files($list);
                $contents[] = 'core';
            }

            // 3. 打包
            $part = $dir . '/' . $base . '.zip.part';
            $tmp[] = $part;
            $skipped = $this->build_zip($part, $base, $sql_file, $list, $contents, $db_tables);

            $final = $this->zip_path($base);
            if (!@rename($part, $final)) {
                throw new Exception('無法完成備份檔（重新命名失敗）。');
            }
            $tmp = array_diff($tmp, array($part));

            $meta = array(
                'created'  => time(),
                'type'     => $trigger,
                'contents' => $contents,
                'size'     => (int) filesize($final),
                'files'    => count($list),
                'skipped'  => $skipped,
                'tables'   => $db_tables,
                'siteurl'  => home_url(),
                'prefix'   => $GLOBALS['wpdb']->base_prefix,
                'wp'       => get_bloginfo('version'),
                'plugin'   => defined('RISECREATIVES_OPT_VERSION') ? RISECREATIVES_OPT_VERSION : '',
                'remote'   => array(),
            );
            $this->write_meta($base, $meta);

            // 4. 遠端
            $meta = $this->upload_remote($base, $meta, $s);
            $this->write_meta($base, $meta);

            // 5. 保留份數
            $this->progress('retention', 97, '清理舊備份');
            $this->apply_retention($s);

            $notes = array();
            foreach ($meta['remote'] as $dest => $info) {
                if (isset($info['status']) && $info['status'] === 'failed') {
                    $notes[] = ($dest === 'github' ? 'GitHub' : 'Google Drive') . ' 上傳失敗：' . $info['error'];
                }
            }

            $this->cleanup_tmp($tmp);
            if ($trigger === 'pre_restore') {
                // 還原流程的一部分：維持「進行中」，由還原流程自己寫入最終結果
                $this->write_status(array('state' => 'running', 'kind' => 'restore', 'step' => 'safety_done', 'percent' => 8, 'message' => '安全備份完成，開始還原'));
                return $base;
            }
            $this->write_status(array(
                'state'    => 'done',
                'percent'  => 100,
                'step'     => 'done',
                'finished' => time(),
                'message'  => '備份完成（' . size_format($meta['size']) . '）' . ($notes ? '；' . implode('；', $notes) : ''),
                'backup'   => $base,
                'error'    => $notes ? implode('；', $notes) : '',
            ));

            return $base;
        } catch (Throwable $e) {
            $this->cleanup_tmp($tmp);
            if ($base !== '') {
                @unlink($this->zip_path($base));
                @unlink($this->meta_path($base));
            }
            $this->write_status(array(
                'state'    => 'failed',
                'kind'     => $trigger === 'pre_restore' ? 'restore' : 'backup',
                'finished' => time(),
                'message'  => '備份失敗：' . $e->getMessage(),
                'error'    => $e->getMessage(),
            ));
            return false;
        }
    }

    private function cleanup_tmp($files) {
        foreach ((array) $files as $f) {
            if ($f && file_exists($f)) {
                @unlink($f);
            }
        }
    }

    /**
     * 要排除的資料夾／檔名
     */
    private function excluded_names() {
        $names = array(
            '.git', '.svn', 'node_modules', '.DS_Store',
            // 其他備份外掛的資料夾（避免備份裡包著備份）
            'updraft', 'ai1wm-backups', 'backupbuddy_backups', 'wpvividbackups', 'wp-staging', 'backwpup',
        );
        return (array) apply_filters('risecreatives_backup_excluded_names', $names);
    }

    private function collect_files($root, $prefix, &$list) {
        $root     = rtrim(str_replace('\\', '/', $root), '/');
        $backup   = rtrim(str_replace('\\', '/', $this->get_dir(false)), '/');
        $excluded = $this->excluded_names();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                function ($current) use ($backup, $excluded) {
                    $path = str_replace('\\', '/', $current->getPathname());
                    if ($path === $backup || strpos($path, $backup . '/') === 0) {
                        return false;
                    }
                    $name = $current->getFilename();
                    if (in_array($name, $excluded, true)) {
                        return false;
                    }
                    if (strpos($name, 'risecreatives-backups-') === 0) {
                        return false;
                    }
                    if ($current->isLink()) {
                        return false;
                    }
                    return true;
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $rel  = ltrim(substr($path, strlen($root)), '/');
            $list[] = array($path, $prefix . '/' . $rel);
        }
    }

    /**
     * WordPress 核心檔案：wp-admin、wp-includes、根目錄的檔案與 wp-config.php
     */
    private function collect_core_files(&$list) {
        $root = rtrim(str_replace('\\', '/', ABSPATH), '/');

        foreach (array('wp-admin', 'wp-includes') as $dir) {
            if (is_dir($root . '/' . $dir)) {
                $this->collect_files($root . '/' . $dir, 'files/core/' . $dir, $list);
            }
        }

        $skip_ext = array('zip', 'gz', 'tar', 'sql', 'log', 'bak', 'tmp');
        foreach ((array) scandir($root) as $name) {
            $path = $root . '/' . $name;
            if ($name === '.' || $name === '..' || !is_file($path) || is_link($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, $skip_ext, true) || $name === 'error_log' || $name === '.DS_Store') {
                continue;
            }
            $list[] = array($path, 'files/core/' . $name);
        }

        // wp-config.php 也可能放在網站根目錄的上一層
        if (!is_file($root . '/wp-config.php')) {
            $parent = dirname($root) . '/wp-config.php';
            if (is_file($parent) && !is_file(dirname($root) . '/wp-settings.php')) {
                $list[] = array($parent, 'files/core/wp-config.php');
            }
        }
    }

    private function build_zip($part, $base, $sql_file, $list, $contents, $db_tables) {
        $zip = new ZipArchive();
        if ($zip->open($part, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('無法建立備份檔。');
        }

        $manifest = array(
            'format'   => 1,
            'created'  => gmdate('c'),
            'siteurl'  => home_url(),
            'prefix'   => $GLOBALS['wpdb']->base_prefix,
            'wp'       => get_bloginfo('version'),
            'plugin'   => defined('RISECREATIVES_OPT_VERSION') ? RISECREATIVES_OPT_VERSION : '',
            'contents' => $contents,
            'tables'   => $db_tables,
        );
        $zip->addFromString('manifest.json', wp_json_encode($manifest));

        if ($sql_file !== '') {
            $zip->addFile($sql_file, 'database.sql');
        }

        $total   = max(1, count($list));
        $skipped = 0;
        $added   = 0;
        $store   = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'zip', 'gz', 'mp4', 'mov', 'mp3', 'woff2', 'rar', '7z');

        foreach ($list as $i => $item) {
            list($path, $entry) = $item;

            if (!is_readable($path)) {
                $skipped++;
                continue;
            }
            $zip->addFile($path, $entry);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, $store, true) && method_exists($zip, 'setCompressionName')) {
                $zip->setCompressionName($entry, ZipArchive::CM_STORE);
            }
            $added++;

            // 定期關閉再開啟：寫出資料、避免同時開啟的檔案過多
            if ($added % $this->zip_batch === 0) {
                if (!$zip->close()) {
                    throw new Exception('寫入備份檔失敗（可能是主機空間不足）。');
                }
                if ($zip->open($part) !== true) {
                    throw new Exception('無法繼續寫入備份檔。');
                }
                $this->progress('zip', 20 + (int) (($i / $total) * 70), '壓縮檔案 ' . number_format($added) . ' / ' . number_format(count($list)));
            }
        }

        $this->progress('zip', 92, '完成壓縮');
        if (!$zip->close()) {
            throw new Exception('寫入備份檔失敗（可能是主機空間不足）。');
        }

        return $skipped;
    }

    /* ------------------------------------------------------------------
     * 資料庫匯出
     * ------------------------------------------------------------------ */

    private function sql_escape($value) {
        global $wpdb;
        if (isset($wpdb->dbh) && $wpdb->dbh instanceof mysqli) {
            return mysqli_real_escape_string($wpdb->dbh, $value);
        }
        return str_replace(
            array('\\', "\0", "\n", "\r", "'", '"', "\x1a"),
            array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'),
            $value
        );
    }

    /**
     * 匯出資料庫為 SQL（每個陳述式獨立一行，方便還原時逐行執行）。回傳資料表數量。
     */
    public function dump_database($file) {
        global $wpdb;

        $fh = fopen($file, 'wb');
        if (!$fh) {
            throw new Exception('無法建立資料庫匯出檔。');
        }

        $charset = !empty($wpdb->charset) ? $wpdb->charset : 'utf8mb4';
        fwrite($fh, "-- RiseCreatives Optimization database backup\n");
        fwrite($fh, '-- Created: ' . gmdate('c') . "\n");
        fwrite($fh, 'SET NAMES ' . preg_replace('/[^a-z0-9_]/i', '', $charset) . ";\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
        fwrite($fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");

        $prefix = $wpdb->base_prefix;
        $rows   = $wpdb->get_results('SHOW FULL TABLES', ARRAY_N);
        $tables = array();
        foreach ((array) $rows as $row) {
            if (isset($row[1]) && strtoupper($row[1]) === 'BASE TABLE' && strpos($row[0], $prefix) === 0) {
                $tables[] = $row[0];
            }
        }
        if (!$tables) {
            fclose($fh);
            throw new Exception('找不到任何資料表可匯出。');
        }

        $count = count($tables);
        foreach ($tables as $n => $table) {
            $this->progress('db', 3 + (int) (($n / $count) * 11), '匯出資料表 ' . $table);
            $this->dump_table($fh, $table);
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        return $count;
    }

    private function dump_table($fh, $table) {
        global $wpdb;

        $qt = '`' . str_replace('`', '``', $table) . '`';

        $create = $wpdb->get_row('SHOW CREATE TABLE ' . $qt, ARRAY_N);
        if (!$create || empty($create[1])) {
            throw new Exception('無法讀取資料表結構：' . $table);
        }
        fwrite($fh, 'DROP TABLE IF EXISTS ' . $qt . ";\n");
        fwrite($fh, preg_replace('/\s*\n\s*/', ' ', $create[1]) . ";\n");

        // 欄位型態（二進位欄位以十六進位輸出）
        $columns = $wpdb->get_results('SHOW COLUMNS FROM ' . $qt, ARRAY_A);
        $names   = array();
        $binary  = array();
        foreach ((array) $columns as $col) {
            $names[] = $col['Field'];
            if (preg_match('/blob|binary|bit/i', $col['Type'])) {
                $binary[$col['Field']] = true;
            }
        }
        $col_list = '`' . implode('`,`', array_map(function ($c) {
            return str_replace('`', '``', $c);
        }, $names)) . '`';

        // 排除項目：本外掛自己的設定（含 Token）與暫存快取
        $where = '1=1';
        if ($table === $wpdb->options) {
            $where = "option_name NOT IN ('" . self::OPTION_NAME . "','" . self::DIR_OPTION . "')"
                . " AND option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
        }

        // 主鍵（單一欄位時以主鍵分頁，速度較快）
        $keys = $wpdb->get_results("SHOW KEYS FROM " . $qt . " WHERE Key_name = 'PRIMARY'", ARRAY_A);
        $pk   = (is_array($keys) && count($keys) === 1) ? $keys[0]['Column_name'] : '';
        $qpk  = $pk !== '' ? '`' . str_replace('`', '``', $pk) . '`' : '';

        $batch  = 200;
        $last   = null;
        $offset = 0;

        while (true) {
            if ($qpk !== '') {
                $cond = $last === null ? $where : $where . " AND $qpk > '" . $this->sql_escape((string) $last) . "'";
                $sql  = "SELECT * FROM $qt WHERE $cond ORDER BY $qpk ASC LIMIT $batch";
            } else {
                $sql = "SELECT * FROM $qt WHERE $where LIMIT $offset, $batch";
            }
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (!$rows) {
                if ($wpdb->last_error) {
                    throw new Exception('讀取資料表失敗：' . $table . '（' . $wpdb->last_error . '）');
                }
                break;
            }

            $values = array();
            $bytes  = 0;
            foreach ($rows as $row) {
                $parts = array();
                foreach ($names as $name) {
                    $v = $row[$name];
                    if ($v === null) {
                        $parts[] = 'NULL';
                    } elseif (isset($binary[$name])) {
                        $parts[] = $v === '' ? "''" : '0x' . bin2hex($v);
                    } else {
                        $parts[] = "'" . $this->sql_escape($v) . "'";
                    }
                }
                $line = '(' . implode(',', $parts) . ')';
                $bytes += strlen($line);
                $values[] = $line;

                if ($bytes > 500000) {
                    fwrite($fh, "INSERT INTO $qt ($col_list) VALUES " . implode(',', $values) . ";\n");
                    $values = array();
                    $bytes  = 0;
                }
            }
            if ($values) {
                fwrite($fh, "INSERT INTO $qt ($col_list) VALUES " . implode(',', $values) . ";\n");
            }

            if ($qpk !== '') {
                $end  = end($rows);
                $last = $end[$pk];
            } else {
                $offset += $batch;
            }
            if (count($rows) < $batch) {
                break;
            }
        }
    }

    /* ------------------------------------------------------------------
     * 遠端上傳與保留份數
     * ------------------------------------------------------------------ */

    private function upload_remote($base, $meta, $s) {
        $zip = $this->zip_path($base);
        $name = $base . '.zip';

        $dests = array();
        if ($s['github_enabled']) {
            $dests['github'] = 'RiseCreatives_Backup_GitHub';
        }
        if ($s['gdrive_enabled']) {
            $dests['gdrive'] = 'RiseCreatives_Backup_GDrive';
        }

        $i = 0;
        foreach ($dests as $key => $class) {
            $i++;
            $this->progress('remote', 93 + $i, '上傳到 ' . ($key === 'github' ? 'GitHub' : 'Google Drive'));
            try {
                $result = call_user_func(array($class, 'upload'), $zip, $name, $s);
                $meta['remote'][$key] = array_merge(array('status' => 'ok', 'at' => time()), $result);
                if ($key === 'gdrive' && !empty($result['folder_id']) && $result['folder_id'] !== $s['gdrive_folder_id']) {
                    $s['gdrive_folder_id'] = $result['folder_id'];
                    $this->save_settings($s);
                }
            } catch (Throwable $e) {
                $meta['remote'][$key] = array('status' => 'failed', 'at' => time(), 'error' => $e->getMessage());
            }
        }

        return $meta;
    }

    private function remote_delete($dest, $info, $s) {
        $class = $dest === 'github' ? 'RiseCreatives_Backup_GitHub' : 'RiseCreatives_Backup_GDrive';
        call_user_func(array($class, 'delete'), $info, $s);
    }

    private function apply_retention($s) {
        $all     = $this->list_backups();
        $regular = array();
        $pre     = array();
        foreach ($all as $item) {
            if (isset($item['type']) && $item['type'] === 'pre_restore') {
                $pre[] = $item;
            } else {
                $regular[] = $item;
            }
        }

        // 本機
        $keep_local = max(1, (int) $s['keep_local']);
        $n = 0;
        foreach ($regular as $item) {
            if (!$item['zip_exists']) {
                continue;
            }
            $n++;
            if ($n > $keep_local) {
                $this->delete_local_zip($item['base']);
            }
        }

        // 還原前的安全備份：只保留最近 3 份
        $n = 0;
        foreach ($pre as $item) {
            if (!$item['zip_exists']) {
                continue;
            }
            $n++;
            if ($n > 3) {
                $this->delete_local_zip($item['base']);
            }
        }

        // 遠端
        foreach (array('github' => 'keep_github', 'gdrive' => 'keep_gdrive') as $dest => $opt) {
            if (empty($s[$dest . '_enabled'])) {
                continue;
            }
            $keep = max(1, (int) $s[$opt]);
            $n = 0;
            foreach ($this->list_backups() as $item) {
                if (!isset($item['remote'][$dest]['status']) || $item['remote'][$dest]['status'] !== 'ok') {
                    continue;
                }
                $n++;
                if ($n > $keep) {
                    try {
                        $this->remote_delete($dest, $item['remote'][$dest], $s);
                        $meta = $this->read_meta($item['base']);
                        $meta['remote'][$dest]['status'] = 'deleted';
                        $this->write_meta($item['base'], $meta);
                    } catch (Throwable $e) {
                        // 刪除失敗保留紀錄，下次再試
                    }
                }
            }
        }

        $this->prune_empty_meta();
    }

    private function delete_local_zip($base) {
        @unlink($this->zip_path($base));
        $meta = $this->read_meta($base);
        if ($meta) {
            $meta['local_deleted'] = true;
            $this->write_meta($base, $meta);
        }
    }

    /**
     * 本機檔案與遠端副本都已不存在的備份，移除描述檔
     */
    private function prune_empty_meta() {
        foreach ($this->list_backups() as $item) {
            if ($item['zip_exists']) {
                continue;
            }
            $has_remote = false;
            if (!empty($item['remote'])) {
                foreach ($item['remote'] as $info) {
                    if (isset($info['status']) && $info['status'] === 'ok') {
                        $has_remote = true;
                    }
                }
            }
            if (!$has_remote) {
                @unlink($this->meta_path($item['base']));
            }
        }
    }

    /**
     * 刪除備份（本機＋遠端副本）。回傳 array(ok => bool, message => string)
     */
    public function delete_backup($base) {
        if (!self::is_valid_base($base)) {
            return array('ok' => false, 'message' => '備份代碼不正確。');
        }
        $meta = $this->read_meta($base);
        if (!$meta) {
            return array('ok' => false, 'message' => '找不到這份備份。');
        }

        $s      = $this->get_settings();
        $errors = array();

        if (!empty($meta['remote'])) {
            foreach ($meta['remote'] as $dest => $info) {
                if (isset($info['status']) && $info['status'] === 'ok') {
                    try {
                        $this->remote_delete($dest, $info, $s);
                        $meta['remote'][$dest]['status'] = 'deleted';
                    } catch (Throwable $e) {
                        $errors[] = ($dest === 'github' ? 'GitHub' : 'Google Drive') . '：' . $e->getMessage();
                    }
                }
            }
        }

        @unlink($this->zip_path($base));
        $meta['local_deleted'] = true;
        $this->write_meta($base, $meta);
        $this->prune_empty_meta();

        if ($errors) {
            return array('ok' => true, 'message' => '本機備份已刪除，但遠端副本刪除失敗：' . implode('；', $errors) . '（記錄已保留，可稍後再刪除）');
        }
        return array('ok' => true, 'message' => '已刪除。');
    }

    /* ------------------------------------------------------------------
     * 還原
     * ------------------------------------------------------------------ */

    public function run_restore($base) {
        $this->prepare_environment();
        $this->register_failure_handler('還原');

        try {
            if (!$this->is_zip_available()) {
                throw new Exception('此主機沒有安裝 PHP ZipArchive 擴充，無法還原。');
            }
            if (!self::is_valid_base($base) || !is_file($this->zip_path($base))) {
                throw new Exception('找不到本機的備份檔（只能還原仍保存在本機的備份）。');
            }

            $zip_file = $this->zip_path($base);
            $zip      = new ZipArchive();
            if ($zip->open($zip_file) !== true) {
                throw new Exception('備份檔無法開啟，可能已損毀。');
            }

            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (!is_array($manifest) || empty($manifest['contents'])) {
                $zip->close();
                throw new Exception('備份檔缺少 manifest.json，無法確認內容。');
            }

            $has_db = in_array('db', $manifest['contents'], true);
            if ($has_db) {
                if (!isset($manifest['prefix']) || $manifest['prefix'] !== $GLOBALS['wpdb']->base_prefix) {
                    $zip->close();
                    throw new Exception('資料表前綴不同（備份：' . (isset($manifest['prefix']) ? $manifest['prefix'] : '?') . '，目前：' . $GLOBALS['wpdb']->base_prefix . '），已取消還原。');
                }
                $norm = function ($u) {
                    return untrailingslashit(strtolower((string) preg_replace('#^https?://#i', '', (string) $u)));
                };
                if (!isset($manifest['siteurl']) || $norm($manifest['siteurl']) !== $norm(home_url())) {
                    $zip->close();
                    throw new Exception('備份來自不同的網站網址（' . (isset($manifest['siteurl']) ? $manifest['siteurl'] : '?') . '），本功能不支援跨網站搬家，已取消還原。');
                }
            }

            $this->write_status(array(
                'state'    => 'running',
                'kind'     => 'restore',
                'trigger'  => 'manual',
                'started'  => time(),
                'finished' => 0,
                'step'     => 'safety',
                'percent'  => 2,
                'message'  => '建立還原前的安全備份',
                'backup'   => $base,
                'error'    => '',
            ));

            // 先備份目前的資料庫，還原失敗或後悔時可以回復
            $safety = $this->run_backup('pre_restore', array(
                'include_db' => 1, 'include_uploads' => 0, 'include_plugins' => 0, 'include_themes' => 0, 'include_core' => 0,
                'github_enabled' => 0, 'gdrive_enabled' => 0,
            ));
            if ($safety === false) {
                $st = $this->read_status();
                $zip->close();
                throw new Exception('還原前的安全備份失敗，已取消還原：' . (isset($st['error']) ? $st['error'] : ''));
            }
            $this->write_status(array('kind' => 'restore', 'state' => 'running', 'backup' => $base, 'step' => 'restore', 'percent' => 10, 'message' => '開始還原', 'safety' => $safety));

            // 暫存本外掛的設定，還原資料庫後再寫回（避免 Token 與備份資料夾設定被舊資料覆蓋）
            $keep_settings = get_option(self::OPTION_NAME);
            $keep_dir      = get_option(self::DIR_OPTION);

            if ($has_db) {
                $this->restore_database($zip);
            }

            if ($keep_settings !== false) {
                update_option(self::OPTION_NAME, $keep_settings, false);
            }
            if ($keep_dir !== false) {
                update_option(self::DIR_OPTION, $keep_dir, false);
            }

            $files = $this->restore_files($zip);
            $zip->close();

            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            delete_option('rewrite_rules');
            if (function_exists('risecreatives_purge_all_caches')) {
                risecreatives_purge_all_caches();
            }

            $this->write_status(array(
                'state'    => 'done',
                'kind'     => 'restore',
                'percent'  => 100,
                'step'     => 'done',
                'finished' => time(),
                'message'  => '還原完成（' . ($has_db ? '資料庫' : '') . ($has_db && $files ? '、' : '') . ($files ? number_format($files) . ' 個檔案' : '') . '）。還原前的資料庫已另存為安全備份 ' . $safety . '。請重新登入並清除快取。',
                'backup'   => $base,
                'error'    => '',
            ));
            return true;
        } catch (Throwable $e) {
            $this->write_status(array(
                'state'    => 'failed',
                'kind'     => 'restore',
                'finished' => time(),
                'message'  => '還原失敗：' . $e->getMessage(),
                'error'    => $e->getMessage(),
            ));
            return false;
        }
    }

    private function restore_database($zip) {
        global $wpdb;

        $stream = $zip->getStream('database.sql');
        if (!$stream) {
            throw new Exception('備份檔內找不到 database.sql。');
        }

        $wpdb->suppress_errors(true);
        $wpdb->show_errors(false);

        $count = 0;
        $table = '';
        while (($line = fgets($stream)) !== false) {
            // 一行就是一個完整的 SQL 陳述式（含 INSERT 的大型字串，換行已被跳脫）
            $sql = rtrim($line, "\r\n");
            if ($sql === '' || strpos($sql, '--') === 0) {
                continue;
            }
            if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`/', $sql, $m)) {
                $table = $m[1];
                $this->progress('restore', 12 + min(60, (int) ($count / 20)), '還原資料表 ' . $table);
            }

            $wpdb->check_current_query = false;
            $wpdb->last_error = '';
            $result = $wpdb->query($sql);
            if ($result === false || $wpdb->last_error) {
                fclose($stream);
                throw new Exception('資料庫還原失敗（' . ($table ?: '?') . '）：' . $wpdb->last_error . '。目前資料庫可能只還原了一部分，請使用「還原前的安全備份」回復。');
            }
            $count++;
        }
        fclose($stream);
    }

    private function restore_files($zip) {
        $roots = array();
        $up = wp_upload_dir(null, false);
        $roots['uploads'] = $up['basedir'];
        $roots['plugins'] = WP_PLUGIN_DIR;
        $roots['themes']  = get_theme_root();
        $roots['core']    = rtrim(ABSPATH, '/\\');

        $own_plugin = 'files/plugins/' . basename(RISECREATIVES_OPT_PATH) . '/';
        $total = $zip->numFiles;
        $done  = 0;

        for ($i = 0; $i < $total; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || strpos($name, 'files/') !== 0 || substr($name, -1) === '/') {
                continue;
            }
            if (strpos($name, '..') !== false || strpos($name, "\0") !== false) {
                continue;
            }
            // 不覆蓋正在執行的本外掛，也不覆蓋 wp-config.php（資料庫連線設定以目前網站為準）
            if (strpos($name, $own_plugin) === 0 || $name === 'files/core/wp-config.php') {
                continue;
            }

            $parts = explode('/', $name, 3);
            if (count($parts) < 3 || !isset($roots[$parts[1]]) || !$roots[$parts[1]]) {
                continue;
            }
            $target = rtrim($roots[$parts[1]], '/\\') . '/' . $parts[2];

            $target_dir = dirname($target);
            if (!is_dir($target_dir) && !wp_mkdir_p($target_dir)) {
                continue;
            }

            $in = $zip->getStream($name);
            if (!$in) {
                continue;
            }
            $out = @fopen($target, 'wb');
            if ($out) {
                stream_copy_to_stream($in, $out);
                fclose($out);
                $done++;
            }
            fclose($in);

            if ($done % 200 === 0) {
                $this->progress('restore', 75 + (int) (($i / max(1, $total)) * 22), '還原檔案 ' . number_format($done));
            }
        }

        return $done;
    }

    /* ------------------------------------------------------------------
     * 後台操作（儲存設定）
     * ------------------------------------------------------------------ */

    private function require_admin() {
        if (!current_user_can('manage_options')) {
            wp_die(__('您沒有足夠的權限執行此操作。', 'risecreatives-optimization'), '', array('response' => 403));
        }
    }

    private function redirect_back($notice, $msg = '') {
        $args = array('page' => 'risecreatives-optimization-backup', 'risecreatives_notice' => $notice);
        if ($msg !== '') {
            $args['risecreatives_msg'] = rawurlencode($msg);
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * 套用表單送來的設定並儲存。
     *
     * @return array saved(bool)、level(ok|warn|error)、code、message
     */
    private function apply_settings($in) {
        $old = $this->get_settings();
        $new = $old;

        foreach (array('include_db', 'include_uploads', 'include_plugins', 'include_themes', 'include_core', 'github_enabled', 'gdrive_enabled') as $k) {
            $new[$k] = !empty($in[$k]) ? 1 : 0;
        }

        $freq = isset($in['frequency']) ? sanitize_key($in['frequency']) : 'off';
        $new['frequency'] = in_array($freq, array('off', 'daily', 'weekly', 'monthly'), true) ? $freq : 'off';
        $new['hour']      = isset($in['hour']) ? max(0, min(23, (int) $in['hour'])) : 3;

        foreach (array('keep_local', 'keep_github', 'keep_gdrive') as $k) {
            $new[$k] = isset($in[$k]) ? max(1, min(365, (int) $in[$k])) : 7;
        }

        if (!$new['include_db'] && !$new['include_uploads'] && !$new['include_plugins'] && !$new['include_themes'] && !$new['include_core']) {
            return array('saved' => false, 'level' => 'error', 'code' => 'no_content', 'message' => '請至少保留一項備份內容，這項變更未儲存。');
        }

        $result = array('saved' => true, 'level' => 'ok', 'code' => 'saved', 'message' => '已自動儲存');

        // GitHub
        $new['github_repo'] = isset($in['github_repo']) ? RiseCreatives_Backup_GitHub::normalize_repo($in['github_repo']) : '';
        if (!empty($in['clear_github_token'])) {
            $new['github_token'] = '';
        } elseif (isset($in['github_token']) && trim($in['github_token']) !== '') {
            $new['github_token'] = trim($in['github_token']);
        }
        if ($new['github_enabled'] && ($new['github_repo'] === '' || $new['github_token'] === '')) {
            $new['github_enabled'] = 0;
            $result = array('saved' => true, 'level' => 'warn', 'code' => 'github_incomplete', 'message' => '已儲存，但 GitHub 尚未啟用：請先填寫儲存庫與 Token，再勾選。');
        }

        // Google Drive
        $new['gdrive_client_id'] = isset($in['gdrive_client_id']) ? trim($in['gdrive_client_id']) : '';
        if (isset($in['gdrive_client_secret']) && trim($in['gdrive_client_secret']) !== '') {
            $new['gdrive_client_secret'] = trim($in['gdrive_client_secret']);
        }
        if ($new['gdrive_client_id'] !== $old['gdrive_client_id'] && $old['gdrive_refresh_token'] !== '') {
            // 換了 Client ID 就必須重新授權
            $new['gdrive_refresh_token'] = '';
            $new['gdrive_folder_id']     = '';
            $result = array('saved' => true, 'level' => 'warn', 'code' => 'gdrive_reauth', 'message' => '已儲存。Client ID 已變更，需要重新授權 Google Drive。');
        }
        if ($new['gdrive_enabled'] && $new['gdrive_refresh_token'] === '') {
            $new['gdrive_enabled'] = 0;
            if ($result['code'] === 'saved') {
                $result = array('saved' => true, 'level' => 'warn', 'code' => 'gdrive_not_authorized', 'message' => '已儲存，但 Google Drive 尚未啟用：請先填寫 Client ID／Secret 並完成「授權 Google Drive」，再勾選。');
            }
        }

        $this->save_settings($new);

        // 頻率或時間有變才重排排程，其他設定只確認排程存在
        $this->ensure_schedule($new['frequency'] !== $old['frequency'] || (int) $new['hour'] !== (int) $old['hour']);

        return $result;
    }

    /**
     * 給後台畫面同步用的目前狀態
     */
    private function ui_state() {
        $s = $this->get_settings();
        return array(
            'checks' => array(
                'include_db'      => (int) $s['include_db'],
                'include_uploads' => (int) $s['include_uploads'],
                'include_plugins' => (int) $s['include_plugins'],
                'include_themes'  => (int) $s['include_themes'],
                'include_core'    => (int) $s['include_core'],
                'github_enabled'  => (int) $s['github_enabled'],
                'gdrive_enabled'  => (int) $s['gdrive_enabled'],
            ),
            'github_repo'      => $s['github_repo'],
            'has_github_token' => $s['github_token'] !== '',
            'has_gd_secret'    => $s['gdrive_client_secret'] !== '',
            'has_gd_client'    => $s['gdrive_client_id'] !== '' && $s['gdrive_client_secret'] !== '',
            'authorized'       => $s['gdrive_refresh_token'] !== '',
            'next_run'         => $this->describe_next_run(),
        );
    }

    public function describe_next_run() {
        $s    = $this->get_settings();
        $next = $this->get_next_run();
        if (!$next || $s['frequency'] === 'off') {
            return '未啟用';
        }
        $labels = array('daily' => '每天', 'weekly' => '每週', 'monthly' => '每月');
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next)
            . '（' . (isset($labels[$s['frequency']]) ? $labels[$s['frequency']] : '') . '，網站時區 ' . wp_timezone_string() . '）';
    }

    public function handle_save_settings() {
        $this->require_admin();
        check_admin_referer('risecreatives_backup_save');

        $in = isset($_POST['backup']) && is_array($_POST['backup']) ? wp_unslash($_POST['backup']) : array();
        $r  = $this->apply_settings($in);

        $this->redirect_back($r['code']);
    }

    public function ajax_autosave() {
        $this->ajax_guard();

        $in = isset($_POST['backup']) && is_array($_POST['backup']) ? wp_unslash($_POST['backup']) : array();
        $r  = $this->apply_settings($in);
        $r['state'] = $this->ui_state();

        wp_send_json_success($r);
    }

    /* ------------------------------------------------------------------
     * Google Drive 授權
     * ------------------------------------------------------------------ */

    public function get_gdrive_redirect_uri() {
        return admin_url('admin-post.php?action=risecreatives_backup_gdrive_cb');
    }

    public function handle_gdrive_auth() {
        $this->require_admin();
        check_admin_referer('risecreatives_backup_gdrive_auth');

        $s = $this->get_settings();
        if ($s['gdrive_client_id'] === '' || $s['gdrive_client_secret'] === '') {
            $this->redirect_back('gdrive_no_client');
        }

        $url = RiseCreatives_Backup_GDrive::auth_url($s['gdrive_client_id'], $this->get_gdrive_redirect_uri(), wp_create_nonce('risecreatives_gdrive_state'));
        wp_redirect($url); // 外部網址（Google）
        exit;
    }

    public function handle_gdrive_callback() {
        $this->require_admin();

        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if (!wp_verify_nonce($state, 'risecreatives_gdrive_state')) {
            wp_die('授權驗證失敗，請回到備份頁面重新操作。');
        }
        if (!empty($_GET['error'])) {
            $this->redirect_back('gdrive_denied', sanitize_text_field(wp_unslash($_GET['error'])));
        }
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if ($code === '') {
            $this->redirect_back('gdrive_denied');
        }

        $s = $this->get_settings();
        try {
            $token = RiseCreatives_Backup_GDrive::exchange_code($code, $s['gdrive_client_id'], $s['gdrive_client_secret'], $this->get_gdrive_redirect_uri());
        } catch (Throwable $e) {
            $this->redirect_back('gdrive_error', $e->getMessage());
        }

        if (empty($token['refresh_token'])) {
            $this->redirect_back('gdrive_error', 'Google 沒有回傳 refresh token。請到 Google 帳戶「第三方存取權」移除此應用程式後再授權一次。');
        }

        $s['gdrive_refresh_token'] = $token['refresh_token'];
        $s['gdrive_folder_id']     = '';
        $this->save_settings($s);
        $this->redirect_back('gdrive_ok');
    }

    public function handle_gdrive_disconnect() {
        $this->require_admin();
        check_admin_referer('risecreatives_backup_gdrive_disconnect');

        $s = $this->get_settings();
        $s['gdrive_refresh_token'] = '';
        $s['gdrive_folder_id']     = '';
        $s['gdrive_enabled']       = 0;
        $this->save_settings($s);
        $this->redirect_back('gdrive_disconnected');
    }

    /* ------------------------------------------------------------------
     * 下載
     * ------------------------------------------------------------------ */

    public function handle_download() {
        $this->require_admin();
        check_admin_referer('risecreatives_backup_download');

        $base = isset($_GET['backup']) ? sanitize_text_field(wp_unslash($_GET['backup'])) : '';
        if (!self::is_valid_base($base) || !is_file($this->zip_path($base))) {
            wp_die('找不到這份備份檔。');
        }

        $file = $this->zip_path($base);
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $base . '.zip"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');

        $fh = fopen($file, 'rb');
        if ($fh) {
            while (!feof($fh)) {
                echo fread($fh, 1048576);
                flush();
            }
            fclose($fh);
        }
        exit;
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    private function ajax_guard() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '權限不足。'), 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');
    }

    public function ajax_start_backup() {
        $this->ajax_guard();

        if ($this->is_running()) {
            wp_send_json_error(array('message' => '目前已有備份或還原正在進行中。'));
        }
        if (!$this->is_zip_available()) {
            wp_send_json_error(array('message' => '此主機沒有安裝 PHP ZipArchive 擴充，無法建立備份。'));
        }

        $this->write_status(array(
            'state'    => 'running',
            'kind'     => 'backup',
            'trigger'  => 'manual',
            'started'  => time(),
            'finished' => 0,
            'percent'  => 0,
            'step'     => 'init',
            'message'  => '開始備份',
            'backup'   => '',
            'error'    => '',
        ));

        $this->respond_and_continue(array('started' => true));
        $this->run_backup('manual');
        exit;
    }

    public function ajax_start_restore() {
        $this->ajax_guard();

        $base = isset($_POST['backup']) ? sanitize_text_field(wp_unslash($_POST['backup'])) : '';
        if (!self::is_valid_base($base) || !is_file($this->zip_path($base))) {
            wp_send_json_error(array('message' => '找不到本機的備份檔。'));
        }
        if ($this->is_running()) {
            wp_send_json_error(array('message' => '目前已有備份或還原正在進行中。'));
        }

        $this->write_status(array(
            'state'    => 'running',
            'kind'     => 'restore',
            'trigger'  => 'manual',
            'started'  => time(),
            'finished' => 0,
            'percent'  => 0,
            'step'     => 'init',
            'message'  => '準備還原',
            'backup'   => $base,
            'error'    => '',
        ));

        $this->respond_and_continue(array('started' => true));
        $this->run_restore($base);
        exit;
    }

    public function ajax_status() {
        $this->ajax_guard();
        wp_send_json_success($this->read_status());
    }

    public function ajax_delete() {
        $this->ajax_guard();
        $base   = isset($_POST['backup']) ? sanitize_text_field(wp_unslash($_POST['backup'])) : '';
        $result = $this->delete_backup($base);
        if ($result['ok']) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result);
    }

    public function ajax_test_connection() {
        $this->ajax_guard();

        $dest = isset($_POST['dest']) ? sanitize_key(wp_unslash($_POST['dest'])) : '';
        $s    = $this->get_settings();

        // 以表單目前輸入的值測試（Token 留空則使用已儲存的）
        if ($dest === 'github') {
            if (isset($_POST['github_repo'])) {
                $s['github_repo'] = RiseCreatives_Backup_GitHub::normalize_repo(wp_unslash($_POST['github_repo']));
            }
            if (!empty($_POST['github_token'])) {
                $s['github_token'] = trim(wp_unslash($_POST['github_token']));
            }
            try {
                wp_send_json_success(array('message' => RiseCreatives_Backup_GitHub::test($s)));
            } catch (Throwable $e) {
                wp_send_json_error(array('message' => $e->getMessage()));
            }
        } elseif ($dest === 'gdrive') {
            try {
                wp_send_json_success(array('message' => RiseCreatives_Backup_GDrive::test($s)));
            } catch (Throwable $e) {
                wp_send_json_error(array('message' => $e->getMessage()));
            }
        }

        wp_send_json_error(array('message' => '未知的測試項目。'));
    }
}

require_once dirname(__FILE__) . '/class-backup-remote.php';

RiseCreatives_Optimization_Backup::get_instance();
