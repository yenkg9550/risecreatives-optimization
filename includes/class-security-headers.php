<?php
// includes/class-security-headers.php

if (!defined('ABSPATH')) {
    exit;
}

class RiseCreatives_Security_Headers {
    const HTACCESS_MARKER = 'RiseCreatives Security Headers';
    const NOTICE_OPTION   = 'risecreatives_htaccess_sync_failed';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // 在 WordPress 輸出安全標頭
        add_action('send_headers', [$this, 'add_security_headers']);

        // 在 .htaccess 中添加安全標頭（如果使用 Apache）
        add_action('admin_init', [$this, 'update_htaccess_rules']);

        // .htaccess 無法更新時提醒管理員
        add_action('admin_notices', [$this, 'htaccess_sync_notice']);
    }

    public function add_security_headers() {
        // HSTS
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

        // CSP
        $csp = $this->get_csp_directives();
        header("Content-Security-Policy: " . $csp);

        // Referrer Policy
        header('Referrer-Policy: no-referrer-when-downgrade');

        // Permissions Policy
        $permissions = $this->get_permissions_directives();
        header("Permissions-Policy: " . $permissions);

        // 其他安全標頭
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
    }

    /**
     * 取得 .htaccess 路徑（不存在時回傳 false）
     */
    private static function get_htaccess_file() {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';

        $htaccess_file = get_home_path() . '.htaccess';
        return file_exists($htaccess_file) ? $htaccess_file : false;
    }

    /**
     * 要寫入 .htaccess 的規則（每行一個元素）
     */
    private function get_htaccess_rules() {
        return [
            '<IfModule mod_headers.c>',
            "    Header set Strict-Transport-Security 'max-age=31536000; includeSubDomains'",
            '    Header set Content-Security-Policy "' . $this->get_csp_directives() . '"',
            "    Header set Referrer-Policy 'no-referrer-when-downgrade'",
            '    Header set Permissions-Policy "' . $this->get_permissions_directives() . '"',
            "    Header set X-Content-Type-Options 'nosniff'",
            "    Header set X-Frame-Options 'SAMEORIGIN'",
            "    Header set X-XSS-Protection '1; mode=block'",
            '</IfModule>',
        ];
    }

    /**
     * 只比對實際指令（忽略空白行與 WordPress 自動加入的註解）
     */
    private static function normalize_rules($lines) {
        $out = [];
        foreach ((array) $lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $out[] = preg_replace('/\s+/', ' ', $line);
        }
        return $out;
    }

    public function update_htaccess_rules() {
        if (!is_admin() || wp_doing_ajax()) {
            return;
        }

        $htaccess_file = self::get_htaccess_file();
        if (!$htaccess_file) {
            return;
        }

        $rules   = $this->get_htaccess_rules();
        $current = extract_from_markers($htaccess_file, self::HTACCESS_MARKER);

        // 內容已是最新：不需寫入
        if (self::normalize_rules($current) === self::normalize_rules($rules)) {
            delete_option(self::NOTICE_OPTION);
            return;
        }

        if (insert_with_markers($htaccess_file, self::HTACCESS_MARKER, $rules)) {
            delete_option(self::NOTICE_OPTION);
        } else {
            // 檔案沒有寫入權限：舊規則仍然有效，提醒管理員手動更新
            update_option(self::NOTICE_OPTION, 1, false);
        }
    }

    public function htaccess_sync_notice() {
        if (!get_option(self::NOTICE_OPTION) || !current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>RiseCreatives Optimization：</strong>'
            . '無法更新網站根目錄的 <code>.htaccess</code>（檔案沒有寫入權限），網站目前仍在使用舊版的安全標頭，'
            . '可能造成區塊編輯器內文無法顯示（「這項內容已遭到封鎖」）。</p>'
            . '<p>請開放 <code>.htaccess</code> 的寫入權限後重新整理此頁，或手動把「BEGIN '
            . esc_html(self::HTACCESS_MARKER) . '」區塊中的 Content-Security-Policy 改為：</p>'
            . '<p><code style="word-break:break-all;">' . esc_html($this->get_csp_directives()) . '</code></p></div>';
    }

    /**
     * 停用或解除安裝時移除 .htaccess 中的規則，避免外掛停用後舊標頭仍然生效
     */
    public static function remove_htaccess_rules() {
        delete_option(self::NOTICE_OPTION);

        $htaccess_file = self::get_htaccess_file();
        if (!$htaccess_file || !is_writable($htaccess_file)) {
            return false;
        }
        return insert_with_markers($htaccess_file, self::HTACCESS_MARKER, []);
    }

    private function get_csp_directives() {
        // frame-src 需包含 blob:：WordPress 區塊編輯器的內文區是以 blob: 網址載入的 iframe
        $csp = "default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval'; " .
               "script-src 'self' 'unsafe-inline' 'unsafe-eval' https: *.googleapis.com *.gstatic.com *.google.com maps.google.com; " .
               "style-src 'self' 'unsafe-inline' https: *.googleapis.com *.gstatic.com; " .
               "img-src 'self' data: https: *.googleapis.com *.gstatic.com *.google.com *.maps.googleapis.com maps.gstatic.com; " .
               "font-src 'self' data: https: *.googleapis.com *.gstatic.com; " .
               "connect-src 'self' https: *.googleapis.com *.google.com; " .
               "frame-src 'self' https: blob: *.google.com *.maps.google.com maps.google.com; " .
               "child-src 'self' https: blob: *.google.com *.maps.google.com; " .
               "worker-src 'self' blob:; " .
               "frame-ancestors 'self'; " .
               "form-action 'self' https:; " .
               "base-uri 'self'; " .
               "object-src 'none';";

        /**
         * 可依個別站台調整 CSP 內容
         *
         * @param string $csp
         */
        return (string) apply_filters('risecreatives_csp_directives', $csp);
    }

    private function get_permissions_directives() {
        return "accelerometer=*, " .
               "camera=*, " .
               "geolocation=*, " .
               "gyroscope=*, " .
               "magnetometer=*, " .
               "microphone=*, " .
               "payment=*, " .
               "usb=*";
    }
}

// 初始化
function risecreatives_security_headers() {
    return RiseCreatives_Security_Headers::get_instance();
}

// 啟動
add_action('plugins_loaded', 'risecreatives_security_headers');
