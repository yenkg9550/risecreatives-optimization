<?php
// templates/admin-performance.php

// 防止直接訪問
if (!defined('ABSPATH')) {
    exit;
}

// 檢查權限
if (!current_user_can('manage_options')) {
    wp_die(__('您沒有足夠的權限訪問此頁面。', 'risecreatives-optimization'));
}

global $pagenow;
$current_page = admin_url($pagenow . '?page=' . $_GET['page']);

// 獲取當前選項
$performance = RiseCreatives_Optimization_Performance::get_instance();
$options = $performance->get_options();

// 處理表單提交
if (isset($_POST['risecreatives_save_performance'])) {
    // 驗證 nonce
    if (wp_verify_nonce($_POST['_wpnonce'], 'performance_settings')) {
        // 準備要更新的選項
        $new_options = [
            'disable_emoji' => isset($_POST['disable_emoji']),
            'disable_image_sizes' => isset($_POST['disable_image_sizes']),
            'disable_auto_updates' => isset($_POST['disable_auto_updates']),
            'optimize_revisions' => isset($_POST['optimize_revisions']),
            'max_revisions' => isset($_POST['max_revisions']) ? 
                max(1, min(100, intval($_POST['max_revisions']))) : 5,
            'disable_xmlrpc' => isset($_POST['disable_xmlrpc']),
            'remove_wlwmanifest' => isset($_POST['remove_wlwmanifest']),
            'remove_rsd_link' => isset($_POST['remove_rsd_link']),
            'disable_rest_api' => isset($_POST['disable_rest_api']),
            'disable_self_pingbacks' => isset($_POST['disable_self_pingbacks']),
            'control_heartbeat' => isset($_POST['control_heartbeat']),
            'heartbeat_frequency' => isset($_POST['heartbeat_frequency']) ? intval($_POST['heartbeat_frequency']) : 60,
            'disable_heartbeat' => isset($_POST['disable_heartbeat']) ? $_POST['disable_heartbeat'] : 'default',
            'remove_generator' => isset($_POST['remove_generator']),
            'disable_oembed' => isset($_POST['disable_oembed']),
            'remove_feed_links' => isset($_POST['remove_feed_links']),
            'disable_dashicons' => isset($_POST['disable_dashicons']),
            'disable_jquery_migrate' => isset($_POST['disable_jquery_migrate']),
            'disable_gutenberg_resources' => isset($_POST['disable_gutenberg_resources']),
            'disable_shortlinks' => isset($_POST['disable_shortlinks']),
            'remove_version_query' => isset($_POST['remove_version_query'])
        ];
        
        // 更新選項
        update_option('risecreatives_opt_performance', $new_options);
        risecreatives_purge_all_caches();

        // 重新載入選項
        $options = $performance->get_options();
        
        // 設定成功訊息
        add_settings_error(
            'performance_settings',
            'settings_updated',
            __('設定已更新。', 'risecreatives-optimization'),
            'updated'
        );
    }
}

// 顯示任何訊息
settings_errors('performance_settings');
?>

<div class="wrap">
    <h1><?php _e('效能設定', 'risecreatives-optimization'); ?></h1>

    <form method="post" action="<?php echo esc_url($current_page); ?>" onsubmit="console.log('Form submitted');">
        <?php wp_nonce_field('performance_settings'); ?>
        <table class="form-table" role="presentation">
            <!-- Emoji 設定 -->
            <tr>
                <th scope="row">
                    <label for="disable_emoji"><?php _e('停用 Emoji', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="disable_emoji" 
                            name="disable_emoji" 
                            value="1"
                            <?php checked($options['disable_emoji']); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('停用 WordPress Emoji 功能以提升載入速度', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>

            <!-- 修訂版本設定 -->
            <tr>
                <th scope="row">
                    <label for="optimize_revisions"><?php _e('最佳化修訂版本', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="optimize_revisions" 
                            name="optimize_revisions" 
                            value="1"
                            <?php checked($options['optimize_revisions']); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <div class="revision-options" style="margin-top: 10px;">
                        <label for="max_revisions">
                            <?php _e('最大修訂版本數量：', 'risecreatives-optimization'); ?>
                        </label>
                        <input type="number" 
                        id="max_revisions" 
                        name="max_revisions" 
                        value="<?php echo esc_attr(max(1, min(100, $options['max_revisions']))); ?>" 
                        min="1" 
                        max="100" 
                        class="small-text">
                    </div>
                    <p class="description">
                        <?php _e('限制每篇文章的修訂版本數量以優化資料庫', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>

            <!-- XML-RPC 設定 -->
            <tr>
                <th scope="row">
                    <label for="disable_xmlrpc"><?php _e('停用 XML-RPC', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="disable_xmlrpc" 
                            name="disable_xmlrpc" 
                            value="1"
                            <?php checked($options['disable_xmlrpc']); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('停用 XML-RPC 功能以增強安全性', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>

            <!-- 移除 WLW Manifest 設定 -->
            <tr>
                <th scope="row">
                    <label for="remove_wlwmanifest"><?php _e('移除 WLW Manifest', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="remove_wlwmanifest" 
                            name="remove_wlwmanifest" 
                            value="1"
                            <?php checked($options['remove_wlwmanifest']); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('移除 Windows Live Writer manifest 連結', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>

            <!-- 移除 RSD Link 設定 -->
            <tr>
                <th scope="row">
                    <label for="remove_rsd_link"><?php _e('移除 RSD Link', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="remove_rsd_link" 
                            name="remove_rsd_link" 
                            value="1"
                            <?php checked($options['remove_rsd_link']); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('移除 Really Simple Discovery 連結', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>

            <!-- 圖片尺寸設定 -->
            <tr>
                <th scope="row">
                    <label for="disable_image_sizes"><?php _e('停用預設圖片尺寸', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="disable_image_sizes" 
                            name="disable_image_sizes" 
                            value="1"
                            <?php checked($options['disable_image_sizes']); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('停用 WordPress 預設的縮圖、中型、中大型和大型圖片尺寸', 'risecreatives-optimization'); ?>
                    </p>
                    <p class="description" style="color: #d63638;">
                        <?php _e('注意：啟用此選項後，只會影響新上傳的圖片，已存在的圖片尺寸不會被刪除', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>

            <!-- 自動更新設定 -->
            <tr>
                <th scope="row">
                    <label for="disable_auto_updates"><?php _e('停用自動更新', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="disable_auto_updates" 
                            name="disable_auto_updates" 
                            value="1"
                            <?php checked($options['disable_auto_updates']); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('停用所有自動更新，包括 WordPress 核心、外掛和主題', 'risecreatives-optimization'); ?>
                    </p>
                    <p class="description" style="color: #d63638;">
                        <?php _e('注意：停用自動更新後，請記得定期手動檢查和更新，以確保網站安全', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>

            <!-- 系統資訊 -->
            <tr>
                <th scope="row"><?php _e('系統資訊', 'risecreatives-optimization'); ?></th>
                <td>
                    <div class="risecreatives-system-info">
                        <p>
                            <strong><?php _e('PHP 版本：', 'risecreatives-optimization'); ?></strong>
                            <?php echo PHP_VERSION; ?>
                        </p>
                        <p>
                            <strong><?php _e('WordPress 版本：', 'risecreatives-optimization'); ?></strong>
                            <?php echo get_bloginfo('version'); ?>
                        </p>
                        <p>
                            <strong><?php _e('記憶體限制：', 'risecreatives-optimization'); ?></strong>
                            <?php 
                            // 獲取 WordPress 的實際記憶體限制
                            $wp_memory_limit = wp_convert_hr_to_bytes( WP_MEMORY_LIMIT );
                            $wp_max_memory_limit = wp_convert_hr_to_bytes( WP_MAX_MEMORY_LIMIT );
                            
                            // 獲取 PHP 的記憶體限制
                            $php_memory_limit = wp_convert_hr_to_bytes( ini_get('memory_limit') );
                            
                            // 使用最大值
                            $actual_memory_limit = max($wp_memory_limit, $wp_max_memory_limit, $php_memory_limit);
                            echo size_format($actual_memory_limit);
                            ?>
                        </p>
                        <p>
                            <strong><?php _e('最大執行時間：', 'risecreatives-optimization'); ?></strong>
                            <?php echo ini_get('max_execution_time'); ?> <?php _e('秒', 'risecreatives-optimization'); ?>
                        </p>
                        <p>
                            <strong><?php _e('上傳檔案大小限制：', 'risecreatives-optimization'); ?></strong>
                            <?php echo ini_get('upload_max_filesize'); ?>
                        </p>
                    </div>
                </td>
            </tr>
        </table>

        <h2 class="title"><?php _e('進階優化選項', 'risecreatives-optimization'); ?></h2>
        <p class="description">
            <?php _e('以下選項可以進一步優化WordPress性能，但可能會影響某些功能，請謹慎啟用', 'risecreatives-optimization'); ?>
        </p>
        
        <table class="form-table" role="presentation">
            <!-- REST API 設定 -->
            <tr>
                <th scope="row">
                    <label for="disable_rest_api"><?php _e('限制 REST API', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="disable_rest_api" 
                            name="disable_rest_api" 
                            value="1"
                            <?php checked(!empty($options['disable_rest_api'])); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('限制 REST API 僅對已登入用戶開放，可提高安全性。預設放行 Contact Form 7、WooCommerce Store API、WPForms、Fluent Forms 等前台表單端點；其他依賴匿名 REST 的外掛可能受影響', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>
            
            <!-- 禁用自我 Pingback -->
            <tr>
                <th scope="row">
                    <label for="disable_self_pingbacks"><?php _e('禁用自我 Pingback', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="disable_self_pingbacks" 
                            name="disable_self_pingbacks" 
                            value="1"
                            <?php checked(!empty($options['disable_self_pingbacks'])); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('禁止 WordPress 向自己的文章發送 pingback 通知，減少不必要的請求', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>
            
            <!-- Heartbeat API 控制 -->
            <tr>
                <th scope="row">
                    <label for="control_heartbeat"><?php _e('控制 Heartbeat API', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="control_heartbeat" 
                            name="control_heartbeat" 
                            value="1"
                            <?php checked(!empty($options['control_heartbeat'])); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    
                    <div class="heartbeat-options" style="margin-top: 10px;">
                        <label for="heartbeat_frequency">
                            <?php _e('Heartbeat 頻率 (秒)：', 'risecreatives-optimization'); ?>
                        </label>
                        <input type="number" 
                            id="heartbeat_frequency" 
                            name="heartbeat_frequency" 
                            value="<?php echo isset($options['heartbeat_frequency']) ? esc_attr($options['heartbeat_frequency']) : 60; ?>" 
                            min="15" 
                            max="120" 
                            class="small-text">
                            
                        <div style="margin-top: 10px;">
                            <label for="disable_heartbeat">
                                <?php _e('停用模式：', 'risecreatives-optimization'); ?>
                            </label>
                            <select id="disable_heartbeat" name="disable_heartbeat">
                                <option value="default" <?php selected(isset($options['disable_heartbeat']) ? $options['disable_heartbeat'] : 'default', 'default'); ?>>
                                    <?php _e('不停用 (只控制頻率)', 'risecreatives-optimization'); ?>
                                </option>
                                <option value="admin" <?php selected(isset($options['disable_heartbeat']) ? $options['disable_heartbeat'] : '', 'admin'); ?>>
                                    <?php _e('後台停用', 'risecreatives-optimization'); ?>
                                </option>
                                <option value="post" <?php selected(isset($options['disable_heartbeat']) ? $options['disable_heartbeat'] : '', 'post'); ?>>
                                    <?php _e('後台停用，文章編輯頁面保留', 'risecreatives-optimization'); ?>
                                </option>
                                <option value="everywhere" <?php selected(isset($options['disable_heartbeat']) ? $options['disable_heartbeat'] : '', 'everywhere'); ?>>
                                    <?php _e('完全停用', 'risecreatives-optimization'); ?>
                                </option>
                            </select>
                        </div>
                    </div>
                    
                    <p class="description">
                        <?php _e('控制或禁用 WordPress Heartbeat API，減少 CPU 使用率和減輕服務器負載', 'risecreatives-optimization'); ?>
                    </p>
                    <p class="description" style="color: #d63638;">
                        <?php _e('注意：完全停用可能會影響文章自動保存和一些後台功能', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>
            
            <!-- 移除生成器標籤 -->
            <tr>
                <th scope="row">
                    <label for="remove_generator"><?php _e('移除生成器標籤', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="remove_generator" 
                            name="remove_generator" 
                            value="1"
                            <?php checked(!empty($options['remove_generator'])); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('移除顯示 WordPress 版本的 meta 標籤，提高安全性', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>
            
            <!-- 禁用 oEmbed -->
            <tr>
                <th scope="row">
                    <label for="disable_oembed"><?php _e('禁用 oEmbed', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="disable_oembed" 
                            name="disable_oembed" 
                            value="1"
                            <?php checked(!empty($options['disable_oembed'])); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('禁用 WordPress oEmbed 功能，減少外部請求和提高頁面載入速度', 'risecreatives-optimization'); ?>
                    </p>
                    <p class="description" style="color: #d63638;">
                        <?php _e('注意：這將禁用在文章中自動嵌入其他網站內容的功能', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>
            
            <!-- 移除 Feed 鏈接 -->
            <tr>
                <th scope="row">
                    <label for="remove_feed_links"><?php _e('移除 Feed 鏈接', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="remove_feed_links" 
                            name="remove_feed_links" 
                            value="1"
                            <?php checked(!empty($options['remove_feed_links'])); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('移除頭部的 RSS Feed 鏈接，減少不必要的 HTTP 請求', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>
            
            <!-- 禁用前台 Dashicons -->
            <tr>
                <th scope="row">
                    <label for="disable_dashicons"><?php _e('禁用前台 Dashicons', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="disable_dashicons" 
                            name="disable_dashicons" 
                            value="1"
                            <?php checked(!empty($options['disable_dashicons'])); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('對未登入用戶禁用前台的 Dashicons 圖標字體，減少不必要的資源載入', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>
            
            <!-- 禁用 jQuery Migrate -->
            <tr>
                <th scope="row">
                    <label for="disable_jquery_migrate"><?php _e('禁用 jQuery Migrate', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="disable_jquery_migrate" 
                            name="disable_jquery_migrate" 
                            value="1"
                            <?php checked(!empty($options['disable_jquery_migrate'])); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('禁用 jQuery Migrate 腳本，減少前台載入的 JavaScript', 'risecreatives-optimization'); ?>
                    </p>
                    <p class="description" style="color: #d63638;">
                        <?php _e('注意：可能會影響使用舊版 jQuery API 的外掛或主題', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>
            
            <!-- 禁用古騰堡資源 -->
            <tr>
                <th scope="row">
                    <label for="disable_gutenberg_resources"><?php _e('禁用前台區塊資源', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="disable_gutenberg_resources" 
                            name="disable_gutenberg_resources" 
                            value="1"
                            <?php checked(!empty($options['disable_gutenberg_resources'])); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('禁用前台頁面載入的區塊編輯器 CSS 和 JavaScript 資源（僅影響前台顯示，不影響後台編輯功能）', 'risecreatives-optimization'); ?>
                    </p>
                    <p class="description" style="color: #d63638;">
                        <?php _e('注意：如果您需要停用後台區塊編輯器功能，請使用「編輯器設定」中的選項', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>
            
            <!-- 禁用短連結 -->
            <tr>
                <th scope="row">
                    <label for="disable_shortlinks"><?php _e('禁用短連結', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="disable_shortlinks" 
                            name="disable_shortlinks" 
                            value="1"
                            <?php checked(!empty($options['disable_shortlinks'])); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('移除 WordPress 生成的短連結標籤', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>
            
            <!-- 移除版本查詢參數 -->
            <tr>
                <th scope="row">
                    <label for="remove_version_query"><?php _e('移除版本查詢參數', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            id="remove_version_query" 
                            name="remove_version_query" 
                            value="1"
                            <?php checked(!empty($options['remove_version_query'])); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('僅移除 WordPress 核心檔案 URL 中的版本查詢參數 (?ver=x.x.x)。主題、外掛與 Elementor 的版本參數會保留，避免更新後瀏覽器或 CDN 仍使用舊檔', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('儲存設定', 'risecreatives-optimization'), 'primary', 'risecreatives_save_performance'); ?>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // 檢查修訂版本控制的狀態並相應地顯示/隱藏選項
    function toggleRevisionOptions() {
        var $checkbox = $('#optimize_revisions');
        var $options = $('.revision-options');
        
        if ($checkbox.is(':checked')) {
            $options.slideDown();
        } else {
            $options.slideUp();
        }
    }

    // 檢查 Heartbeat 控制的狀態並相應地顯示/隱藏選項
    function toggleHeartbeatOptions() {
        var $checkbox = $('#control_heartbeat');
        var $options = $('.heartbeat-options');
        
        if ($checkbox.is(':checked')) {
            $options.slideDown();
        } else {
            $options.slideUp();
        }
    }

    // 初始檢查
    toggleRevisionOptions();
    toggleHeartbeatOptions();

    // 當修訂版本控制開關改變時
    $('#optimize_revisions').on('change', toggleRevisionOptions);
    
    // 當 Heartbeat 控制開關改變時
    $('#control_heartbeat').on('change', toggleHeartbeatOptions);
});
</script>