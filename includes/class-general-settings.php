<?php
// includes/class-general-settings.php

class RiseCreatives_Optimization_General {
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
        // 後台 Logo 修改
        add_action('login_head', [$this, 'customize_login_logo']);
        
        // 註冊短代碼
        add_shortcode('risecreatives_copyright', [$this, 'copyright_shortcode']);
    }

    /**
     * 自訂登入 Logo
     */
    public function customize_login_logo() {
        $options = get_option('risecreatives_opt_general');
        
        if (!empty($options['enable_admin_logo'])) {
            echo '<style type="text/css">
                #login h1 a, .login h1 a {
                    background-image: url(https://raise-up.com.tw/wp-content/uploads/2021/02/raiseup-admin-logo.png);
                    background-size: contain;
                    width: 100%;
                    height: 90px;
                }
            </style>';
        }
    }

    /**
     * Copyright 短代碼
     */
    public function copyright_shortcode($atts) {
        $options = get_option('risecreatives_opt_general');
        
        // 解析屬性
        $atts = shortcode_atts([
            'color' => $options['copyright_color']
        ], $atts, 'risecreatives_copyright');
        
        $current_year = date('Y');
        $site_title = get_bloginfo('name');
        
        return sprintf(
            '<span class="risecreatives-copyright" style="color: %s">Copyright &copy; %s %s | 網頁設計 - <a href="https://www.risecreatives.co" target="_blank" style="color: %s">RiseCreatives 展躍網路</a></span>',
            esc_attr($atts['color']),
            esc_html($current_year),
            esc_html($site_title),
            esc_attr($atts['color'])
        );
    }
}

// 初始化一般設定
function risecreatives_optimization_general() {
    return RiseCreatives_Optimization_General::get_instance();
}

// 啟動一般設定
risecreatives_optimization_general();
?>
