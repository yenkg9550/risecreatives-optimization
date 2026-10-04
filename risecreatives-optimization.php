<?php
/**
 * Plugin Name: RiseCreatives Optimization
 * Plugin URI: https://www.risecreatives.co
 * Description: 展躍網路客製化優化外掛，提供多種WordPress優化功能
 * Version: 1.3.0
 * Author: RiseCreatives 展躍網路
 * Author URI: https://www.risecreatives.co
 * License: GPL v2 or later
 * Text Domain: risecreatives-optimization
 */

// 防止直接訪問此文件
if (!defined('ABSPATH')) {
    exit;
}

// 定義外掛常數
define('RISECREATIVES_OPT_VERSION', '1.3.0');
define('RISECREATIVES_OPT_PATH', plugin_dir_path(__FILE__));
define('RISECREATIVES_OPT_URL', plugin_dir_url(__FILE__));
define('RISECREATIVES_OPT_BASENAME', plugin_basename(__FILE__));

// 自動載入類別
spl_autoload_register(function ($class) {
    // 檢查是否為本外掛的類別
    if (strpos($class, 'RiseCreatives_Optimization') !== false) {
        $class_path = RISECREATIVES_OPT_PATH . 'includes/class-' . 
            strtolower(
                str_replace(
                    ['RiseCreatives_Optimization_', '_'],
                    ['', '-'],
                    $class
                )
            ) . '.php';
        
        if (file_exists($class_path)) {
            require_once $class_path;
        }
    }
});

/**
 * 主要外掛類別
 */
class RiseCreatives_Optimization {
    /**
     * 單例實例
     */
    private static $instance = null;

    /**
     * 取得實例
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 建構函數
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * 初始化 Hooks
     */
    private function init_hooks() {
        // 載入安全標頭類別
        add_action('plugins_loaded', function() {
            require_once RISECREATIVES_OPT_PATH . 'includes/class-security-headers.php';
        }, 5);

        // 載入上傳限制
        add_action('plugins_loaded', function() {
            require_once RISECREATIVES_OPT_PATH . 'includes/class-upload-restrictions.php';
        }, 5);

        // 載入一般設定
        add_action('plugins_loaded', function() {
            require_once RISECREATIVES_OPT_PATH . 'includes/class-general-settings.php';
        }, 5);

        // 初始化性能設定
        add_action('plugins_loaded', function() {
            require_once RISECREATIVES_OPT_PATH . 'includes/class-performance-settings.php';
        }, 5);

        // 添加這段代碼來載入腳本管理類別
        add_action('plugins_loaded', function() {
            require_once RISECREATIVES_OPT_PATH . 'includes/class-scripts-manager.php';
        }, 5);

        // 載入傳統編輯器
        add_action('plugins_loaded', function() {
            require_once RISECREATIVES_OPT_PATH . 'includes/class-editor-settings.php';
        }, 5);

        // 啟用與停用鉤子
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // 初始化後台選單
        add_action('admin_menu', [$this, 'init_admin_menu']);
        
        // 載入語系檔
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        
        // 載入後台資源
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * 外掛啟用時執行
     */
    public function activate() {
        try {
            // 初始化預設選項
            $this->init_options();
            
            // 清除重寫規則快取
            flush_rewrite_rules();
            
        } catch (Exception $e) {
            // 記錄錯誤
            error_log('RiseCreatives Optimization 啟用錯誤: ' . $e->getMessage());
        }
        
        // 確保移除維護模式檔案
        $maintenance_file = ABSPATH . '.maintenance';
        if (file_exists($maintenance_file)) {
            @unlink($maintenance_file);
        }
    }

    /**
     * 外掛停用時執行
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * 初始化預設選項
     */
    private function init_options() {

        // 上傳限制選項初始化
        if (!get_option('risecreatives_upload_restrictions')) {
            $default_upload_options = [
                'max_size' => 1.5,
                'allowed_types' => [
                    'png'  => true,
                    'jpg'  => true,
                    'jpeg' => true,
                    'webp' => true,
                    'pdf'  => true,
                    'gif'  => true,
                    'doc'  => false,
                    'docx' => false,
                    'zip'  => false,
                    'rar'  => false,
                    'mp4'  => false,
                    'mp3'  => false,
                    'wav'  => false,
                    'svg'  => true
                ]
            ];
            update_option('risecreatives_upload_restrictions', $default_upload_options);
        }
        
        // 一般設定
        if (!get_option('risecreatives_opt_general')) {
            update_option('risecreatives_opt_general', [
                'enable_admin_logo' => false,
                'admin_logo_url' => '',
                'admin_logo_width' => 320,
                'admin_logo_height' => 80,
                'admin_logo_link' => home_url('/'),
                'admin_logo_title' => get_bloginfo('name'),
                'copyright_color' => '#000000',
                'copyright_company' => 'RiseCreatives 展躍網路',
                'copyright_url' => 'https://www.risecreatives.co'
            ]);
        }

        // 框架設定
        if (!get_option('risecreatives_opt_scripts')) {
            update_option('risecreatives_opt_scripts', [
                'enable_slick' => false,
                'slick_pages' => [],
                'enable_aos' => false,
                'aos_pages' => []
            ]);
        }

        // 編輯器設定
        if (!get_option('risecreatives_opt_editor')) {
            update_option('risecreatives_opt_editor', [
                'enable_classic_editor' => false,
                'enable_classic_widgets' => false
            ]);
        }

        // 效能設定
        if (!get_option('risecreatives_opt_performance')) {
            $performance_defaults = [
                'disable_emoji' => true,           // 預設停用 emoji
                'disable_image_sizes' => true,     // 預設停用多餘圖片尺寸
                'disable_auto_updates' => false,   // 預設保持自動更新啟用
                'optimize_revisions' => true,      // 新增：最佳化修訂版本
                'max_revisions' => 5,              // 新增：最大修訂版本數
                'disable_xmlrpc' => true,          // 新增：停用 XML-RPC
                'remove_wlwmanifest' => true,      // 新增：移除 WLW Manifest
                'remove_rsd_link' => true          // 新增：移除 RSD Link
            ];
            update_option('risecreatives_opt_performance', $performance_defaults);
        }
    }

    /**
     * 初始化後台選單
     */
    public function init_admin_menu() {
        // 主選單
        add_menu_page(
            __('展躍系統', 'risecreatives-optimization'),
            __('展躍系統', 'risecreatives-optimization'),
            'manage_options',
            'risecreatives-optimization',
            [$this, 'render_general_page'],
            'dashicons-admin-generic',
            30
        );

        // 子選單
        add_submenu_page(
            'risecreatives-optimization',
            __('一般設定', 'risecreatives-optimization'),
            __('一般設定', 'risecreatives-optimization'),
            'manage_options',
            'risecreatives-optimization',
            [$this, 'render_general_page']
        );

        add_submenu_page(
            'risecreatives-optimization',
            __('框架管理', 'risecreatives-optimization'),
            __('框架管理', 'risecreatives-optimization'),
            'manage_options',
            'risecreatives-optimization-scripts',
            [$this, 'render_scripts_page']
        );

        add_submenu_page(
            'risecreatives-optimization',
            __('編輯器設定', 'risecreatives-optimization'),
            __('編輯器設定', 'risecreatives-optimization'),
            'manage_options',
            'risecreatives-optimization-editor',
            [$this, 'render_editor_page']
        );

        add_submenu_page(
            'risecreatives-optimization',
            __('效能監控', 'risecreatives-optimization'),
            __('效能監控', 'risecreatives-optimization'),
            'manage_options',
            'risecreatives-optimization-performance',
            [$this, 'render_performance_page']
        );

    }

    /**
     * 載入語系檔
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'risecreatives-optimization',
            false,
            dirname(RISECREATIVES_OPT_BASENAME) . '/languages'
        );
    }

    /**
     * 載入後台資源
     */
    public function enqueue_admin_assets($hook) {
        // 只在本外掛的頁面載入資源
        if (strpos($hook, 'risecreatives-optimization') !== false) {
            wp_enqueue_style(
                'risecreatives-optimization-admin',
                RISECREATIVES_OPT_URL . 'assets/css/admin.css',
                [],
                RISECREATIVES_OPT_VERSION
            );

            wp_enqueue_script(
                'risecreatives-optimization-admin',
                RISECREATIVES_OPT_URL . 'assets/js/admin.js',
                ['jquery'],
                RISECREATIVES_OPT_VERSION,
                true
            );

            // 添加本地化腳本
            wp_localize_script(
                'risecreatives-optimization-admin',
                'risecreativesOptL10n',
                [
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('risecreatives_optimization_nonce')
                ]
            );
        }
    }

    /**
     * 渲染各個設定頁面
     */
    public function render_general_page() {
        require_once RISECREATIVES_OPT_PATH . 'templates/admin-general.php';
    }

    public function render_scripts_page() {
        require_once RISECREATIVES_OPT_PATH . 'templates/admin-scripts.php';
    }

    public function render_editor_page() {
        require_once RISECREATIVES_OPT_PATH . 'templates/admin-editor.php';
    }

    public function render_performance_page() {
        require_once RISECREATIVES_OPT_PATH . 'templates/admin-performance.php';
    }
}

register_activation_hook(__FILE__, function() {
    // 外掛啟動時的處理邏輯
});

/**
 * 清除常見快取外掛的頁面快取（未安裝的外掛會自動略過）
 * 設定變更後呼叫，避免已快取的頁面仍顯示舊設定的結果。
 */
if (!function_exists('risecreatives_purge_all_caches')) {
    function risecreatives_purge_all_caches() {
        try {
            if (function_exists('rocket_clean_domain')) {
                rocket_clean_domain();                      // WP Rocket
            }
            if (function_exists('w3tc_flush_all')) {
                w3tc_flush_all();                           // W3 Total Cache
            }
            if (function_exists('wp_cache_clear_cache')) {
                wp_cache_clear_cache();                     // WP Super Cache
            }
            if (function_exists('sg_cachepress_purge_cache')) {
                sg_cachepress_purge_cache();                // SiteGround Optimizer
            }
            if (function_exists('wpfc_clear_all_cache')) {
                wpfc_clear_all_cache(true);                 // WP Fastest Cache
            }
            if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
                autoptimizeCache::clearall();               // Autoptimize
            }
            if (has_action('litespeed_purge_all')) {
                do_action('litespeed_purge_all');           // LiteSpeed Cache
            }
            if (has_action('cache_enabler_clear_complete_cache')) {
                do_action('cache_enabler_clear_complete_cache'); // Cache Enabler
            }
        } catch (\Throwable $e) {
            error_log('RiseCreatives Optimization 清除快取錯誤: ' . $e->getMessage());
        }

        do_action('risecreatives_purge_caches');
    }
}

// 初始化外掛
function risecreatives_optimization() {
    return RiseCreatives_Optimization::get_instance();
}

// 啟動外掛
risecreatives_optimization();
?>
