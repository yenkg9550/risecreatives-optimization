<?php
// includes/class-upload-restrictions.php

class RiseCreatives_Upload_Restrictions {
    private static $instance = null;
    private $option_name = 'risecreatives_upload_restrictions';
    
    // 設定頁面密碼
    const SETTINGS_PASSWORD = '42647261';
    
    // 預設允許的檔案類型
    private $default_allowed_types = [
        'png'  => true,    // 預設允許
        'jpg'  => true,    // 預設允許
        'jpeg' => true,    // 預設允許
        'webp' => true,    // 預設允許
        'pdf'  => true,    // 預設允許
        'gif'  => true,    // 預設允許
        'doc'  => false,   // 預設不允許
        'docx' => false,   // 預設不允許
        'zip'  => false,   // 預設不允許
        'rar'  => false,   // 預設不允許
        'mp4'  => false,   // 預設不允許
        'mp3'  => false,   // 預設不允許
        'wav'  => false,   // 預設不允許
        'svg'  => true,    // 預設允許
        'avif' => true,    // 預設允許
        'ico'  => true,    // 預設允許
        'txt'  => false,   // 預設不允許
        'csv'  => false,   // 預設不允許
        'xlsx' => false,   // 預設不允許
        'pptx' => false    // 預設不允許
    ];

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
        // 原有的鉤子
        add_filter('upload_size_limit', [$this, 'set_upload_size_limit']);
        add_filter('wp_handle_upload_prefilter', [$this, 'check_file_type']);
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('wp_ajax_save_upload_restrictions', [$this, 'save_settings']);

        // 新增的 WebP 相關鉤子
        add_filter('wp_handle_upload', [$this, 'handle_webp_conversion'], 10, 2);
        add_action('add_attachment', [$this, 'update_attachment_metadata']);

        // 新增圖片 SEO 相關鉤子
        add_action('add_attachment', [$this, 'handle_image_upload']);
    }

    public function get_options() {
        $saved_options = get_option($this->option_name);
        
        if (false === $saved_options) {
            return [
                'max_size' => 1.5,
                'allowed_types' => $this->default_allowed_types,
                'enable_webp' => false,
                'webp_quality' => 85,          // 轉換品質設定
                'convert_types' => [           // 要轉換的檔案類型
                    'jpg' => true,
                    'jpeg' => true,
                    'png' => true
                ],
                'enable_image_seo' => false  // 新增的選項
            ];
        }

        // 舊站設定中沒有的新檔案類型，補上預設值（不覆蓋已儲存的選擇）
        if (!isset($saved_options['allowed_types']) || !is_array($saved_options['allowed_types'])) {
            $saved_options['allowed_types'] = [];
        }
        $saved_options['allowed_types'] = $saved_options['allowed_types'] + $this->default_allowed_types;

        // 確保所有新選項存在
        if (!isset($saved_options['enable_webp'])) {
            $saved_options['enable_webp'] = false;
        }
        if (!isset($saved_options['webp_quality'])) {
            $saved_options['webp_quality'] = 85;
        }
        if (!isset($saved_options['keep_original'])) {
            $saved_options['keep_original'] = false;
        }
        if (!isset($saved_options['convert_types'])) {
            $saved_options['convert_types'] = [
                'jpg' => true,
                'jpeg' => true,
                'png' => true
            ];
        }

        return $saved_options;
    }

    private function update_image_metadata($attachment_id) {
        $options = $this->get_options();
        if (empty($options['enable_image_seo'])) {
            return;
        }
    
        // 獲取檔案信息
        $filename = basename(get_attached_file($attachment_id));
        $filename_without_ext = pathinfo($filename, PATHINFO_FILENAME);
        $site_name = get_bloginfo('name');
        
        // 格式化檔案名稱（移除連字符和底線）
        $alt_text = str_replace(['-', '_'], ' ', $filename_without_ext);
        $alt_text = ucwords($alt_text); // 將每個單詞首字母大寫
        
        // 更新 Alt 文字
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        
        // 準備描述文字
        $description = sprintf('%s - %s', $alt_text, $site_name);
        
        // 更新圖片描述
        wp_update_post([
            'ID' => $attachment_id,
            'post_excerpt' => $description
        ]);
    }
    
    public function handle_image_upload($attachment_id) {
        // 檢查是否為圖片
        if (!wp_attachment_is_image($attachment_id)) {
            return;
        }
        
        $this->update_image_metadata($attachment_id);
    }

    public function handle_webp_conversion($file_data, $context) {
        $options = $this->get_options();
        
        // 檢查是否啟用 WebP 轉換
        if (!$options['enable_webp']) {
            return $file_data;
        }

        // 檢查檔案類型是否需要轉換
        $file_type = strtolower(pathinfo($file_data['file'], PATHINFO_EXTENSION));
        if (!isset($options['convert_types'][$file_type]) || !$options['convert_types'][$file_type]) {
            return $file_data;
        }

        try {
            // 載入圖片
            $image = wp_get_image_editor($file_data['file']);
            if (is_wp_error($image)) {
                error_log('WebP 轉換錯誤：無法載入圖片 - ' . $image->get_error_message());
                return $file_data;
            }

            // 設定 WebP 檔案路徑
            $webp_path = pathinfo($file_data['file'], PATHINFO_DIRNAME) . '/' . 
                        pathinfo($file_data['file'], PATHINFO_FILENAME) . '.webp';

            // 執行轉換
            $image->set_quality($options['webp_quality']);
            $result = $image->save($webp_path, 'image/webp');

            if (!is_wp_error($result)) {
                // 檢查轉換是否成功
                if (filesize($webp_path) > 0) {
                    // 如果不保留原圖，則刪除
                    if (!$options['keep_original']) {
                        unlink($file_data['file']);
                        
                        // 更新檔案資訊
                        $file_data['file'] = $webp_path;
                        $file_data['url'] = str_replace(
                            basename($file_data['url']),
                            basename($webp_path),
                            $file_data['url']
                        );
                        $file_data['type'] = 'image/webp';
                    }
                } else {
                    // WebP 檔案無效，刪除它
                    unlink($webp_path);
                }
            } else {
                error_log('WebP 轉換錯誤：轉換失敗 - ' . $result->get_error_message());
            }
        } catch (Exception $e) {
            error_log('WebP 轉換錯誤：' . $e->getMessage());
        }

        return $file_data;
    }

    public function update_attachment_metadata($attachment_id) {
        $file = get_attached_file($attachment_id);
        if (pathinfo($file, PATHINFO_EXTENSION) === 'webp') {
            wp_update_attachment_metadata(
                $attachment_id, 
                wp_generate_attachment_metadata($attachment_id, $file)
            );
        }
    }

    public function set_upload_size_limit($size) {
        // 外掛／主題安裝與匯入頁不套用媒體庫的大小限制
        if ($this->is_non_media_upload_screen()) {
            return $size;
        }

        $options = $this->get_options();
        $max_size = $options['max_size'] * 1024 * 1024; // 轉換為 bytes
        return min($size, $max_size);
    }

    public function check_file_type($file) {
        // 允許其他程式（匯入器、備份還原等）透過過濾器略過檢查
        if (apply_filters('risecreatives_skip_upload_check', false, $file)) {
            return $file;
        }

        // 只限制「媒體庫」上傳，不影響外掛／主題安裝、匯入器與其他外掛的上傳流程
        if (!$this->is_media_library_upload()) {
            return $file;
        }

        $options = $this->get_options();
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!isset($options['allowed_types'][$ext]) || !$options['allowed_types'][$ext]) {
            $file['error'] = sprintf(__('不允許上傳 .%s 格式的檔案。', 'risecreatives-optimization'), $ext);
            return $file;
        }

        // 伺服器端檢查檔案大小（upload_size_limit 只影響上傳介面的顯示）
        $max_bytes = (float) $options['max_size'] * 1024 * 1024;
        if ($max_bytes > 0 && !empty($file['size']) && $file['size'] > $max_bytes) {
            $file['error'] = sprintf(
                __('檔案大小超過上限 %s MB。', 'risecreatives-optimization'),
                $options['max_size']
            );
        }

        return $file;
    }

    /**
     * 是否為媒體庫上傳（媒體庫頁面、媒體視窗、REST 媒體端點）
     */
    private function is_media_library_upload() {
        global $pagenow;

        if (in_array($pagenow, ['media-new.php', 'async-upload.php'], true)) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            $route = '';
            if (!empty($GLOBALS['wp']->query_vars['rest_route'])) {
                $route = $GLOBALS['wp']->query_vars['rest_route'];
            } elseif (isset($_GET['rest_route'])) {
                $route = wp_unslash($_GET['rest_route']);
            }
            return strpos('/' . ltrim((string) $route, '/'), '/wp/v2/media') === 0;
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
            return $action === 'upload-attachment';
        }

        return false;
    }

    /**
     * 是否為外掛／主題安裝、匯入等非媒體庫的上傳畫面
     */
    private function is_non_media_upload_screen() {
        global $pagenow;

        if (in_array($pagenow, ['plugin-install.php', 'theme-install.php', 'update.php', 'import.php'], true)) {
            return true;
        }

        // admin.php?import=xxx（各種匯入器）
        if (isset($_GET['import'])) {
            return true;
        }

        return false;
    }

    public function add_menu_page() {
        add_submenu_page(
            'risecreatives-optimization',
            __('上傳限制', 'risecreatives-optimization'),
            __('上傳限制', 'risecreatives-optimization'),
            'manage_options',
            'risecreatives-upload-restrictions',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        // 驗證密碼
        if (!isset($_POST['settings_password']) || $_POST['settings_password'] !== self::SETTINGS_PASSWORD) {
            require_once RISECREATIVES_OPT_PATH . 'templates/admin-upload-restrictions-login.php';
            return;
        }
        
        require_once RISECREATIVES_OPT_PATH . 'templates/admin-upload-restrictions.php';
    }

    public function save_settings() {
        check_ajax_referer('risecreatives_upload_restrictions_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('權限不足');
        }
        
        if (!isset($_POST['settings_password']) || $_POST['settings_password'] !== self::SETTINGS_PASSWORD) {
            wp_send_json_error('密碼錯誤');
        }
    
        try {
            $new_options = [];
    
            // 基本設定
            $new_options['max_size'] = floatval($_POST['max_size']);
            if ($new_options['max_size'] <= 0) {
                $new_options['max_size'] = 1.5;
            }
    
            // 檔案類型設定
            $new_options['allowed_types'] = [];
            foreach ($this->default_allowed_types as $type => $default) {
                $new_options['allowed_types'][$type] = isset($_POST['file_types'][$type]);
            }
    
            // WebP 設定
            $new_options['enable_webp'] = isset($_POST['enable_webp']);
            $new_options['webp_quality'] = isset($_POST['webp_quality']) 
                ? max(1, min(100, intval($_POST['webp_quality']))) 
                : 85;
            $new_options['keep_original'] = isset($_POST['keep_original']);
    
            // 轉換類型設定
            $new_options['convert_types'] = [];
            $default_convert_types = ['jpg', 'jpeg', 'png'];
            foreach ($default_convert_types as $type) {
                $new_options['convert_types'][$type] = isset($_POST['convert_types'][$type]);
            }
    
            // 圖片 SEO 設定 (新增)
            $new_options['enable_image_seo'] = isset($_POST['enable_image_seo']);
    
            // 驗證設定
            $this->validate_settings($new_options);
    
            // 儲存設定
            // 內容未變更時 update_option() 會回傳 false，不應視為失敗
            $update_result = (update_option($this->option_name, $new_options)
                || get_option($this->option_name) == $new_options);
            
            if ($update_result) {
                // 檢查並創建快取目錄
                $this->setup_cache_directory();
                
                // 返回成功訊息和更新後的設定
                wp_send_json_success([
                    'message' => '設定已更新',
                    'settings' => $this->get_sanitized_settings($new_options)
                ]);
            } else {
                throw new Exception('設定更新失敗');
            }
    
        } catch (Exception $e) {
            error_log('Upload Restrictions - Save Error: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * 驗證設定值
     */
    private function validate_settings($settings) {
        // 驗證上傳大小
        if ($settings['max_size'] <= 0 || $settings['max_size'] > 100) {
            throw new Exception('無效的上傳大小限制');
        }
    
        // 確保至少啟用一個檔案類型
        if (empty(array_filter($settings['allowed_types']))) {
            throw new Exception('必須至少允許一種檔案類型');
        }
    
        // 如果啟用 WebP 轉換，驗證相關設定
        if ($settings['enable_webp']) {
            // 驗證品質設定
            if ($settings['webp_quality'] < 1 || $settings['webp_quality'] > 100) {
                throw new Exception('WebP 品質設定必須在 1-100 之間');
            }
    
            // 確保至少選擇一種要轉換的檔案類型
            if (empty(array_filter($settings['convert_types']))) {
                throw new Exception('必須至少選擇一種要轉換的檔案類型');
            }
    
            // 檢查 GD 庫是否支援 WebP
            if (!function_exists('imagewebp')) {
                throw new Exception('伺服器不支援 WebP 轉換，請確認 PHP GD 庫是否正確安裝');
            }
        }
    }
    
    /**
     * 設置快取目錄
     */
    private function setup_cache_directory() {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/webp-cache';
    
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
            // 添加 index.php 以防止目錄列表
            file_put_contents($cache_dir . '/index.php', '<?php // Silence is golden');
        }
    }
    
    /**
     * 清理快取目錄
     */
    public function clean_cache_directory() {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/webp-cache';
    
        if (file_exists($cache_dir)) {
            $files = glob($cache_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && basename($file) !== 'index.php') {
                    @unlink($file);
                }
            }
        }
    }
    
    /**
     * 獲取清理過的設定
     */
    private function get_sanitized_settings($settings) {
        return [
            'max_size' => floatval($settings['max_size']),
            'allowed_types' => array_filter($settings['allowed_types']),
            'enable_webp' => !empty($settings['enable_webp']),
            'webp_quality' => intval($settings['webp_quality']),
            'keep_original' => !empty($settings['keep_original']),
            'convert_types' => array_filter($settings['convert_types']),
            'enable_image_seo' => !empty($settings['enable_image_seo']) 
        ];
    }
}

// 初始化
function risecreatives_upload_restrictions() {
    return RiseCreatives_Upload_Restrictions::get_instance();
}

// 啟動
add_action('plugins_loaded', 'risecreatives_upload_restrictions');