<?php
// includes/class-security-headers.php

class RiseCreatives_Security_Headers {
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
    
    public function update_htaccess_rules() {
        if (!is_admin()) {
            return;
        }

        require_once(ABSPATH . 'wp-admin/includes/misc.php');

        $htaccess_file = get_home_path() . '.htaccess';
        if (!file_exists($htaccess_file)) {
            return;
        }

        $rules = "
        <IfModule mod_headers.c>
            Header set Strict-Transport-Security 'max-age=31536000; includeSubDomains'
            Header set Content-Security-Policy \"" . $this->get_csp_directives() . "\"
            Header set Referrer-Policy 'no-referrer-when-downgrade'
            Header set Permissions-Policy \"" . $this->get_permissions_directives() . "\"
            Header set X-Content-Type-Options 'nosniff'
            Header set X-Frame-Options 'SAMEORIGIN'
            Header set X-XSS-Protection '1; mode=block'
        </IfModule>
        ";

        return insert_with_markers($htaccess_file, 'RiseCreatives Security Headers', explode("\n", $rules));
    }
    
    private function get_csp_directives() {
        return "default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval'; " .
               "script-src 'self' 'unsafe-inline' 'unsafe-eval' https: *.googleapis.com *.gstatic.com *.google.com maps.google.com; " .
               "style-src 'self' 'unsafe-inline' https: *.googleapis.com *.gstatic.com; " .
               "img-src 'self' data: https: *.googleapis.com *.gstatic.com *.google.com *.maps.googleapis.com maps.gstatic.com; " .
               "font-src 'self' data: https: *.googleapis.com *.gstatic.com; " .
               "connect-src 'self' https: *.googleapis.com *.google.com; " .
               "frame-src 'self' https: *.google.com *.maps.google.com maps.google.com; " .
               "child-src 'self' https: blob: *.google.com *.maps.google.com; " .
               "worker-src 'self' blob:; " .
               "frame-ancestors 'self'; " .
               "form-action 'self' https:; " .
               "base-uri 'self'; " .
               "object-src 'none';";
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