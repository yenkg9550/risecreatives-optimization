<?php
// includes/class-performance-settings.php

class RiseCreatives_Optimization_Performance {
    /**
     * 單例實例
     */
    private static $instance = null;

    /**
     * 選項名稱
     */
    private $option_name = 'risecreatives_opt_performance';

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
     * 獲取選項值 - 公開方法供外部使用
     */
    public function get_options() {
        return wp_parse_args(
            get_option($this->option_name, []),
            $this->get_default_options()
        );
    }

    /**
     * 獲取預設選項
     */
    private function get_default_options() {
        return [
            'disable_emoji' => true,           // 預設停用 emoji
            'disable_image_sizes' => true,     // 預設停用多餘圖片尺寸
            'disable_auto_updates' => false,   // 預設保持自動更新啟用
            'optimize_revisions' => true,      // 預設啟用修訂版本最佳化
            'max_revisions' => 5,              // 預設最大修訂版本數
            'disable_xmlrpc' => true,          // 預設停用 XML-RPC
            'remove_wlwmanifest' => true,      // 預設移除 WLW Manifest
            'remove_rsd_link' => true,         // 預設移除 RSD Link

            // 新增的優化選項
            'disable_rest_api' => false,       // 預設不停用 REST API
            'disable_self_pingbacks' => true,  // 預設停用自我 Pingback
            'control_heartbeat' => false,      // 預設不控制 Heartbeat API
            'heartbeat_frequency' => 60,       // Heartbeat 頻率 (秒)
            'disable_heartbeat' => 'default',  // Heartbeat 停用模式：default, admin, post, everywhere
            'remove_generator' => true,        // 預設移除生成器標籤
            'disable_oembed' => false,         // 預設不停用 oEmbed
            'remove_feed_links' => false,      // 預設不移除 Feed 鏈接
            'disable_dashicons' => false,      // 預設不在前台停用 Dashicons
            'disable_jquery_migrate' => false, // 預設不禁用 jQuery Migrate
            'disable_gutenberg_resources' => false, // 預設不禁用前台區塊資源
            'disable_shortlinks' => true,      // 預設停用短連結
            'remove_version_query' => false    // 預設不移除版本查詢參數（避免影響快取更新）
        ];
    }

    /**
     * 建構函數
     */
    private function __construct() {
        try {
            if ($this->verify_initialization()) {
                // 確保在正確的時機執行
                if (did_action('plugins_loaded') || doing_action('plugins_loaded')) {
                    $this->setup_hooks();
                } else {
                    add_action('plugins_loaded', [$this, 'setup_hooks'], 5);
                }
                $this->log_debug('Performance class initialized successfully');
            }
        } catch (Exception $e) {
            $this->log_debug('Error in constructor: ' . $e->getMessage());
        }
    }

    /**
     * 設置所有鉤子
     */
    public function setup_hooks() {
        try {
            // 先確保我們能獲取選項
            $options = $this->get_options();
            $this->log_debug('Performance options loaded: ' . print_r($options, true));

            // 還原舊版本誤改成 0 的圖片尺寸設定（僅在功能關閉時、執行一次）
            add_action('admin_init', [$this, 'maybe_restore_image_size_options']);

            // Emoji 優化
            if (!empty($options['disable_emoji'])) {
                add_action('init', [$this, 'disable_emojis'], 1);
                $this->log_debug('Emoji hooks registered');
            }

            // 圖片尺寸控制
            if (!empty($options['disable_image_sizes'])) {
                add_filter('intermediate_image_sizes_advanced', [$this, 'remove_default_image_sizes'], 1);
                add_filter('intermediate_image_sizes', [$this, 'remove_default_image_sizes'], 1);
                add_filter('big_image_size_threshold', '__return_false');
                
                if (did_action('elementor/loaded')) {
                    add_filter('elementor/image_size/get_attachment_image_html', 
                        [$this, 'modify_elementor_image_size'], 10, 4);
                }
                
                $this->log_debug('Image size hooks registered');
            }

            // 自動更新控制
            if (!empty($options['disable_auto_updates'])) {
                if (!did_action('init')) {
                    add_action('init', [$this, 'disable_auto_updates'], 1);
                } else {
                    $this->disable_auto_updates();
                }
                $this->log_debug('Auto-update hooks registered');
            }

            // 修訂版本控制
            if (!empty($options['optimize_revisions'])) {
                add_filter('wp_revisions_to_keep', [$this, 'limit_revisions'], 10, 2);
                $this->log_debug('Revision hooks registered');
            }

            // XML-RPC 控制
            if (!empty($options['disable_xmlrpc'])) {
                add_filter('xmlrpc_enabled', '__return_false');
                add_filter('wp_headers', [$this, 'remove_x_pingback']);
                $this->log_debug('XML-RPC hooks registered');
            }

            // 清理頭部連結
            if (!empty($options['remove_wlwmanifest']) || !empty($options['remove_rsd_link'])) {
                add_action('init', [$this, 'cleanup_head']);
            }

            // REST API 控制
            if (!empty($options['disable_rest_api'])) {
                add_filter('rest_authentication_errors', [$this, 'disable_rest_api']);
                $this->log_debug('REST API hooks registered');
            }

            // 禁用自我 Pingback
            if (!empty($options['disable_self_pingbacks'])) {
                add_action('pre_ping', [$this, 'disable_self_pingbacks']);
                $this->log_debug('Self pingback hooks registered');
            }

            // 控制 Heartbeat API
            if (!empty($options['control_heartbeat'])) {
                add_filter('heartbeat_settings', [$this, 'control_heartbeat_frequency']);
                
                $heartbeat_mode = isset($options['disable_heartbeat']) ? 
                    $options['disable_heartbeat'] : 'default';
                
                if ($heartbeat_mode !== 'default') {
                    add_action('init', [$this, 'disable_heartbeat'], 1);
                }
                
                $this->log_debug('Heartbeat hooks registered');
            }
            
            // 移除生成器標籤
            if (!empty($options['remove_generator'])) {
                add_action('init', [$this, 'remove_generator_tags']);
                $this->log_debug('Generator tags hooks registered');
            }
            
            // 禁用 oEmbed
            if (!empty($options['disable_oembed'])) {
                add_action('init', [$this, 'disable_oembed'], 10);
                $this->log_debug('oEmbed hooks registered');
            }
            
            // 移除 Feed 鏈接
            if (!empty($options['remove_feed_links'])) {
                add_action('init', [$this, 'remove_feed_links']);
                $this->log_debug('Feed links hooks registered');
            }
            
            // 禁用前台 Dashicons
            if (!empty($options['disable_dashicons'])) {
                add_action('wp_enqueue_scripts', [$this, 'disable_dashicons']);
                $this->log_debug('Dashicons hooks registered');
            }
            
            // 禁用 jQuery Migrate
            if (!empty($options['disable_jquery_migrate'])) {
                add_action('wp_default_scripts', [$this, 'disable_jquery_migrate']);
                $this->log_debug('jQuery Migrate hooks registered');
            }
            
            // 禁用前台區塊資源
            if (!empty($options['disable_gutenberg_resources'])) {
                add_action('wp_enqueue_scripts', [$this, 'disable_gutenberg_resources'], 100);
                $this->log_debug('Gutenberg resources hooks registered');
            }
            
            // 禁用短連結
            if (!empty($options['disable_shortlinks'])) {
                add_action('init', [$this, 'disable_shortlinks']);
                $this->log_debug('Shortlinks hooks registered');
            }
            
            // 移除版本查詢參數
            if (!empty($options['remove_version_query'])) {
                add_filter('style_loader_src', [$this, 'remove_version_query'], 10, 2);
                add_filter('script_loader_src', [$this, 'remove_version_query'], 10, 2);
                $this->log_debug('Version query hooks registered');
            }

            $this->log_debug('All performance hooks setup completed');
        } catch (Exception $e) {
            $this->log_debug('Error in setup_hooks: ' . $e->getMessage());
        }
    }

    /**
     * 檢查並驗證初始化狀態
     */
    private function verify_initialization() {
        try {
            if (!function_exists('wp_get_theme') || !function_exists('get_option')) {
                throw new Exception('WordPress core functions not available');
            }

            // 檢查選項是否存在
            $options = get_option($this->option_name);
            if ($options === false) {
                $default_options = $this->get_default_options();
                update_option($this->option_name, $default_options);
                $this->log_debug('Created default performance options');
            }

            return true;
        } catch (Exception $e) {
            $this->log_debug('Initialization verification failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 停用 Emoji
     */
    public function disable_emojis() {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

        add_filter('tiny_mce_plugins', function($plugins) {
            if (is_array($plugins)) {
                return array_diff($plugins, ['wpemoji']);
            }
            return [];
        });

        add_filter('emoji_svg_url', '__return_false');
    }

    /**
     * 舊版本會把 thumbnail/medium/medium_large/large 的尺寸設定寫成 0，
     * 功能關閉時一次性還原為 WordPress 預設值。
     */
    public function maybe_restore_image_size_options() {
        if (get_option('risecreatives_image_sizes_restored')) {
            return;
        }

        $options = $this->get_options();
        if (!empty($options['disable_image_sizes'])) {
            return;
        }

        $defaults = [
            'thumbnail'    => [150, 150],
            'medium'       => [300, 300],
            'medium_large' => [768, 0],
            'large'        => [1024, 1024],
        ];

        foreach ($defaults as $size => $dims) {
            if ((int) get_option("{$size}_size_w") === 0 && (int) get_option("{$size}_size_h") === 0) {
                update_option("{$size}_size_w", $dims[0]);
                update_option("{$size}_size_h", $dims[1]);
            }
        }

        update_option('risecreatives_image_sizes_restored', 1, false);
    }

    /**
     * 只移除 WordPress 內建的四種尺寸，保留主題／外掛（Elementor、WooCommerce 等）註冊的尺寸
     */
    public function remove_default_image_sizes($sizes) {
        if (!is_array($sizes)) {
            return $sizes;
        }

        $core_sizes = ['thumbnail', 'medium', 'medium_large', 'large'];

        foreach ($sizes as $key => $value) {
            // intermediate_image_sizes_advanced 以尺寸名稱為 key；intermediate_image_sizes 以名稱為 value
            $name = is_string($key) ? $key : $value;
            if (is_string($name) && in_array($name, $core_sizes, true)) {
                unset($sizes[$key]);
            }
        }

        return $sizes;
    }

    public function modify_elementor_image_size($html, $settings, $image_size_key, $image_key) {
        try {
            if (isset($settings[$image_key]['url'])) {
                $url = esc_url($settings[$image_key]['url']);
                $alt = isset($settings[$image_key]['alt']) ? esc_attr($settings[$image_key]['alt']) : '';
                return sprintf('<img src="%s" alt="%s">', $url, $alt);
            }
        } catch (Exception $e) {
            $this->log_debug('Error in modify_elementor_image_size: ' . $e->getMessage());
        }
        return $html;
    }

    public function disable_auto_updates() {
        if (!defined('AUTOMATIC_UPDATER_DISABLED')) {
            define('AUTOMATIC_UPDATER_DISABLED', true);
        }
        if (!defined('WP_AUTO_UPDATE_CORE')) {
            define('WP_AUTO_UPDATE_CORE', false);
        }

        remove_action('init', 'wp_schedule_update_checks');
        wp_clear_scheduled_hook('wp_version_check');
        wp_clear_scheduled_hook('wp_update_plugins');
        wp_clear_scheduled_hook('wp_update_themes');
        wp_clear_scheduled_hook('wp_maybe_auto_update');

        add_filter('auto_update_core', '__return_false');
        add_filter('auto_update_plugin', '__return_false');
        add_filter('auto_update_theme', '__return_false');
        add_filter('allow_minor_auto_core_updates', '__return_false');
        add_filter('allow_major_auto_core_updates', '__return_false');
        add_filter('auto_update_translation', '__return_false');
        add_filter('automatic_updater_disabled', '__return_true');

        add_action('admin_menu', function() {
            remove_submenu_page('index.php', 'update-core.php');
        });
        
        add_action('admin_init', function() {
            remove_action('admin_notices', 'update_nag', 3);
        });

        add_action('admin_bar_menu', function($wp_admin_bar) {
            $wp_admin_bar->remove_node('updates');
        }, 999);
    }

    public function limit_revisions($num, $post) {
        $options = $this->get_options();
        return isset($options['max_revisions']) ? (int) $options['max_revisions'] : 5;
    }

    public function remove_x_pingback($headers) {
        unset($headers['X-Pingback']);
        return $headers;
    }

    public function cleanup_head() {
        $options = $this->get_options();
        
        if (!empty($options['remove_wlwmanifest'])) {
            remove_action('wp_head', 'wlwmanifest_link');
        }
        
        if (!empty($options['remove_rsd_link'])) {
            remove_action('wp_head', 'rsd_link');
        }
    }

    /**
     * 禁用 REST API
     */
    public function disable_rest_api($access) {
        // 如果用戶已登入，則允許訪問（可對登入用戶開放 REST API）
        if (is_user_logged_in()) {
            return $access;
        }

        // 放行常見的前台表單／購物車端點，避免表單送出或結帳失敗
        $route = $this->get_current_rest_route();
        $allowed_namespaces = apply_filters('risecreatives_rest_allowed_namespaces', [
            'contact-form-7',
            'wc/store',
            'wpforms',
            'fluentform',
            'forminator',
            'jetpack',
            'oembed',
        ]);

        foreach ((array) $allowed_namespaces as $namespace) {
            $namespace = trim((string) $namespace, '/');
            if ($namespace !== '' && ($route === $namespace || strpos($route, $namespace . '/') === 0)) {
                return $access;
            }
        }

        // 返回錯誤以阻止匿名訪問 REST API
        return new WP_Error('rest_api_disabled', __('REST API 已被禁用。', 'risecreatives-optimization'), ['status' => 403]);
    }

    /**
     * 取得目前請求的 REST 路由（不含開頭斜線）
     */
    private function get_current_rest_route() {
        $route = '';

        if (!empty($GLOBALS['wp']->query_vars['rest_route'])) {
            $route = $GLOBALS['wp']->query_vars['rest_route'];
        } elseif (isset($_GET['rest_route'])) {
            $route = wp_unslash($_GET['rest_route']);
        }

        return ltrim((string) $route, '/');
    }

    /**
     * 禁用自我 Pingback
     */
    public function disable_self_pingbacks(&$links) {
        $home_url = get_option('home');
        foreach ($links as $key => $link) {
            if (strpos($link, $home_url) !== false) {
                unset($links[$key]);
            }
        }
    }

    /**
     * 控制 Heartbeat 頻率
     */
    public function control_heartbeat_frequency($settings) {
        $options = $this->get_options();
        $frequency = isset($options['heartbeat_frequency']) ? 
            intval($options['heartbeat_frequency']) : 60;
            
        $settings['interval'] = max(15, min(120, $frequency)); // 限制在 15-120 秒之間
        
        return $settings;
    }

    /**
     * 根據位置禁用 Heartbeat
     */
    public function disable_heartbeat() {
        $options = $this->get_options();
        $heartbeat_mode = isset($options['disable_heartbeat']) ? 
            $options['disable_heartbeat'] : 'default';
            
        // 禁用特定頁面的 Heartbeat
        global $pagenow;
        
        switch ($heartbeat_mode) {
            case 'everywhere':
                // 完全禁用
                wp_deregister_script('heartbeat');
                break;
                
            case 'admin':
                // 在管理界面禁用
                if (is_admin()) {
                    wp_deregister_script('heartbeat');
                }
                break;
                
            case 'post':
                // 在文章編輯頁面保留，其他地方禁用
                if (is_admin() && ($pagenow !== 'post.php' && $pagenow !== 'post-new.php')) {
                    wp_deregister_script('heartbeat');
                }
                break;
        }
    }

    /**
     * 禁用生成器標籤
     */
    public function remove_generator_tags() {
        // 移除所有生成器標籤
        remove_action('wp_head', 'wp_generator');
        remove_action('rss2_head', 'the_generator');
        remove_action('rss_head', 'the_generator');
        remove_action('rdf_header', 'the_generator');
        remove_action('atom_head', 'the_generator');
        remove_action('opml_head', 'the_generator');
        remove_action('app_head', 'the_generator');
        remove_action('comments_atom_head', 'the_generator');
        remove_action('comments_rss2_head', 'the_generator');
    }

    /**
     * 禁用 oEmbed
     */
    public function disable_oembed() {
        // 移除 oEmbed 相關操作
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');
        
        // 移除 oEmbed REST API 端點
        add_filter('rewrite_rules_array', [$this, 'disable_oembed_rewrites']);
        
        // 移除 oEmbed 相關過濾器
        remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);
        
        // 停用 oEmbed 自動發現功能
        add_filter('embed_oembed_discover', '__return_false');
        
        // 移除所有 oEmbed 提供者
        add_action('init', function() {
            remove_action('rest_api_init', 'wp_oembed_register_route');
            remove_filter('rest_pre_serve_request', '_oembed_rest_pre_serve_request', 10);
            
            // 解除註冊 oEmbed 相關小工具
            unregister_widget('WP_Widget_Media_Audio');
            unregister_widget('WP_Widget_Media_Video');
        }, 100);
    }

    /**
     * 禁用 oEmbed 重寫規則
     */
    public function disable_oembed_rewrites($rules) {
        foreach ($rules as $rule => $rewrite) {
            if (strpos($rewrite, 'embed=true') !== false) {
                unset($rules[$rule]);
            }
        }
        return $rules;
    }

    /**
     * 移除 Feed 鏈接
     */
    public function remove_feed_links() {
        remove_action('wp_head', 'feed_links', 2);
        remove_action('wp_head', 'feed_links_extra', 3);
        add_action('wp_head', function() {
            // 阻止 WordPress 輸出信息
            add_filter('feed_links_show_posts_feed', '__return_false', 100);
            add_filter('feed_links_show_comments_feed', '__return_false', 100);
        }, 1);
    }

    /**
     * 禁用前台 Dashicons
     */
    public function disable_dashicons() {
        if (!is_user_logged_in()) {
            wp_deregister_style('dashicons');
        }
    }

    /**
     * 禁用 jQuery Migrate
     */
    public function disable_jquery_migrate($scripts) {
        if (!is_admin() && isset($scripts->registered['jquery'])) {
            $script = $scripts->registered['jquery'];
            
            if ($script->deps) {
                $script->deps = array_diff($script->deps, ['jquery-migrate']);
            }
        }
    }

    /**
     * 禁用前台區塊資源
     * 
     * 註：此功能僅移除前台頁面載入的區塊編輯器資源，不影響後台編輯功能
     * 如需禁用後台區塊編輯器，請使用「編輯器設定」中的選項
     */
    public function disable_gutenberg_resources() {
        if (!is_admin()) {
            // 移除 Gutenberg 區塊樣式
            wp_dequeue_style('wp-block-library');
            wp_dequeue_style('wp-block-library-theme');
            wp_dequeue_style('wc-blocks-style'); // WooCommerce 區塊
            wp_dequeue_style('global-styles'); // 全局樣式
            
            // 移除 Gutenberg 相關腳本
            wp_dequeue_script('wp-block-library');
            wp_dequeue_script('wp-block-editor');
        }
    }

    /**
     * 禁用短連結
     */
    public function disable_shortlinks() {
        // 移除頭部 shortlink
        remove_action('wp_head', 'wp_shortlink_wp_head', 10);
        
        // 移除 HTTP 頭部 shortlink
        remove_action('template_redirect', 'wp_shortlink_header', 11);
    }

    /**
     * 移除腳本和樣式的版本查詢參數
     */
    public function remove_version_query($src, $handle) {
        if (strpos($src, 'ver=') === false) {
            return $src;
        }

        // 預設只移除 WordPress 核心檔案的版本參數，
        // 主題／外掛／Elementor 的版本參數需保留，才能在更新後讓瀏覽器與 CDN 抓取新檔案。
        // 如需全部移除，可使用 add_filter('risecreatives_remove_all_version_query', '__return_true');
        if (apply_filters('risecreatives_remove_all_version_query', false)) {
            return remove_query_arg('ver', $src);
        }

        if (strpos($src, includes_url()) === 0 || strpos($src, admin_url()) === 0) {
            return remove_query_arg('ver', $src);
        }

        return $src;
    }

    private function log_debug($message) {
        // 只在發生錯誤或設定為詳細模式時記錄
        if (defined('WP_DEBUG') && WP_DEBUG === true && 
            (
                defined('RISECREATIVES_VERBOSE_DEBUG') && RISECREATIVES_VERBOSE_DEBUG === true ||  // 詳細模式
                strpos($message, 'Error') !== false ||                                            // 錯誤訊息
                $this->is_settings_changed() ||                                                   // 設定已變更
                strpos($message, 'Created default') !== false                                     // 創建預設設定
            )
        ) {
            error_log(sprintf('[RiseCreatives Optimization] %s', $message));
        }
    }

    /**
     * 檢查設定是否已變更
     */
    private function is_settings_changed() {
        static $is_changed = null;
        
        // 如果已計算過結果，直接返回
        if ($is_changed !== null) {
            return $is_changed;
        }
        
        // 獲取當前選項
        $current_options = get_option($this->option_name);
        
        // 檢查選項是否為空（首次執行）
        if (empty($current_options)) {
            $is_changed = true;
            return true;
        }
        
        // 獲取上次儲存的選項雜湊值
        $last_hash = get_option('risecreatives_opt_performance_hash');
        $current_hash = md5(maybe_serialize($current_options));
        
        // 檢查雜湊值是否變更
        $is_changed = ($last_hash !== $current_hash);
        
        // 如果設定已變更，更新雜湊值
        if ($is_changed) {
            update_option('risecreatives_opt_performance_hash', $current_hash);
        }
        
        return $is_changed;
    }
}

// 初始化外掛
if (!function_exists('risecreatives_optimization_performance')) {
    function risecreatives_optimization_performance() {
        return RiseCreatives_Optimization_Performance::get_instance();
    }
}

add_action('plugins_loaded', 'risecreatives_optimization_performance', 20);