<?php
// admin-scripts.php

// 防止直接訪問
if (!defined('ABSPATH')) {
    exit;
}

// 檢查權限
if (!current_user_can('manage_options')) {
    wp_die(__('您沒有足夠的權限訪問此頁面。', 'risecreatives-optimization'));
}

// 獲取框架管理實例
$scripts_manager = RiseCreatives_Optimization_Scripts::get_instance();
$allowed_frameworks = [
    'slick' => 'Slick Carousel',
    'aos' => 'AOS (Animate On Scroll)',
    'gsap' => 'GSAP',
    'swiper' => 'Swiper'
];

// 獲取設定值
$options = get_option('risecreatives_opt_scripts', []);

// 獲取所有已發布的頁面
$pages = get_pages([
    'post_status' => 'publish',
    'sort_column' => 'menu_order,post_title'
]);

// 處理表單提交
if (isset($_POST['risecreatives_save_scripts'])) {
    if (check_admin_referer('risecreatives_scripts_nonce')) {
        $new_options = [];
        
        foreach ($allowed_frameworks as $framework => $name) {
            $new_options["enable_{$framework}"] = isset($_POST["enable_{$framework}"]);
            $new_options["{$framework}_pages"] = isset($_POST["{$framework}_pages"]) ? 
                array_map('intval', $_POST["{$framework}_pages"]) : [];
        }
        
        update_option('risecreatives_opt_scripts', $new_options);
        risecreatives_purge_all_caches();
        $options = $new_options;
        
        echo '<div class="notice notice-success is-dismissible"><p>' . 
             __('設定已更新', 'risecreatives-optimization') . '</p></div>';
    }
}
?>

<div class="wrap">
    <h1><?php _e('框架管理', 'risecreatives-optimization'); ?></h1>

    <form method="post" action="">
        <?php wp_nonce_field('risecreatives_scripts_nonce'); ?>
        
        <table class="form-table">
            <?php foreach ($allowed_frameworks as $framework => $name): ?>
            <tr>
                <th scope="row">
                    <?php echo esc_html($name); ?>
                </th>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="enable_<?php echo esc_attr($framework); ?>" 
                               value="1"
                               <?php checked(!empty($options["enable_{$framework}"])); ?>>
                        <?php _e('啟用', 'risecreatives-optimization'); ?>
                    </label>
                    
                    <div class="framework-pages" style="margin-top: 10px;">
                        <p><?php _e('選擇要載入的頁面：', 'risecreatives-optimization'); ?></p>
                        <div style="max-height: 200px; overflow-y: auto; padding: 10px; border: 1px solid #ddd;">
                            <?php foreach ($pages as $page): ?>
                                <label style="display: block; margin-bottom: 5px;">
                                    <input type="checkbox" 
                                           name="<?php echo esc_attr($framework); ?>_pages[]" 
                                           value="<?php echo $page->ID; ?>"
                                           <?php checked(in_array($page->ID, $options["{$framework}_pages"] ?? [])); ?>>
                                    <?php echo esc_html($page->post_title); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

        <?php submit_button(__('儲存設定', 'risecreatives-optimization'), 'primary', 'risecreatives_save_scripts'); ?>
    </form>
</div>