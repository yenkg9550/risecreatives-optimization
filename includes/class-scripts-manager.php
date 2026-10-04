<?php
// class-scripts-manager.php

class RiseCreatives_Optimization_Scripts {
    private static $instance = null;
    private $scripts_version = '1.0.0';
    private $allowed_frameworks = [
        'slick' => [
            'name' => 'Slick Carousel',
            'css' => ['https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.css',
                     'https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick-theme.css'],
            'js' => ['https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.min.js'],
            'deps' => ['jquery']
        ],
        'aos' => [
            'name' => 'AOS (Animate On Scroll)',
            'css' => ['https://unpkg.com/aos@2.3.1/dist/aos.css'],
            'js' => ['https://unpkg.com/aos@2.3.1/dist/aos.js'],
            'deps' => []
        ],
        'gsap' => [
            'name' => 'GSAP',
            'js' => ['https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js'],
            'deps' => []
        ],
        'swiper' => [
            'name' => 'Swiper',
            'css' => ['https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.css'],
            'js' => ['https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.js'],
            'deps' => []
        ]
    ];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts'], 20);
    }

    public function enqueue_scripts() {
        $options = get_option('risecreatives_opt_scripts', []);
        $current_page_id = get_queried_object_id();
    
        foreach ($this->allowed_frameworks as $framework => $config) {
            if (!empty($options["enable_{$framework}"]) && 
                !empty($options["{$framework}_pages"]) && 
                in_array($current_page_id, $options["{$framework}_pages"])) {
    
                // 載入 CSS 檔案
                if (!empty($config['css'])) {
                    foreach ($config['css'] as $index => $url) {
                        wp_enqueue_style(
                            "risecreatives-{$framework}" . ($index > 0 ? "-{$index}" : ""),
                            $url,
                            [],
                            $this->scripts_version
                        );
                    }
                }
    
                // 載入 JS 檔案
                if (!empty($config['js'])) {
                    foreach ($config['js'] as $index => $url) {
                        wp_enqueue_script(
                            "risecreatives-{$framework}" . ($index > 0 ? "-{$index}" : ""),
                            $url,
                            $config['deps'] ?? [],
                            $this->scripts_version,
                            true
                        );
                    }
                }
    
                // 添加初始化腳本
                if ($framework === 'aos') {
                    wp_add_inline_script("risecreatives-aos", "
                        document.addEventListener('DOMContentLoaded', function() {
                            AOS.init({
                                duration: 1000,
                                once: true,
                                offset: 100
                            });
                        });
                    ");
                }
            }
        }
    }
}

// 初始化框架管理
function risecreatives_optimization_scripts() {
    return RiseCreatives_Optimization_Scripts::get_instance();
}

// 啟動框架管理
add_action('plugins_loaded', 'risecreatives_optimization_scripts');