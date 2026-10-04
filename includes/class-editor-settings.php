<?php
// includes/class-editor-settings.php

class RiseCreatives_Optimization_Editor {
    /**
     * 單例實例
     */
    private static $instance = null;

    /**
     * 選項名稱
     */
    private $option_name = 'risecreatives_opt_editor';

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
     * 獲取選項值
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
            'enable_classic_editor' => false,
            'enable_classic_widgets' => false,
            'force_classic_editor' => false
        ];
    }

    /**
     * 初始化 Hooks
     */
    private function init_hooks() {
        $options = $this->get_options();

        // 傳統編輯器相關
        if (!empty($options['enable_classic_editor'])) {
            // 停用 Gutenberg
            add_filter('use_block_editor_for_post_type', '__return_false', 100);
            
            if (!empty($options['force_classic_editor'])) {
                // 移除 Gutenberg 相關資源
                remove_action('wp_enqueue_scripts', 'wp_common_block_scripts_and_styles');
                add_action('admin_enqueue_scripts', [$this, 'remove_gutenberg_styles'], 100);
            }
        }

        // 傳統小工具相關
        if (!empty($options['enable_classic_widgets'])) {
            add_filter('gutenberg_use_widgets_block_editor', '__return_false');
            add_filter('use_widgets_block_editor', '__return_false');
        }
    }

    /**
     * 移除 Gutenberg 相關樣式
     */
    public function remove_gutenberg_styles($hook = '') {
        // 僅在文章編輯畫面移除，避免影響其他使用 wp-components 的後台頁面（WooCommerce、Yoast 等）
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        wp_dequeue_style('wp-block-library');
        wp_dequeue_style('wp-block-library-theme');
        wp_dequeue_style('wc-block-style');
        wp_dequeue_style('wp-block-editor');
        wp_dequeue_style('wp-block-library-editor');
    }
}

// 初始化編輯器設定
function risecreatives_optimization_editor() {
    return RiseCreatives_Optimization_Editor::get_instance();
}

// 啟動編輯器設定
add_action('plugins_loaded', 'risecreatives_optimization_editor', 20);