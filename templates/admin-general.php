<?php
// templates/admin-general.php

// 防止直接訪問
if (!defined('ABSPATH')) {
    exit;
}

// 檢查權限
if (!current_user_can('manage_options')) {
    wp_die(__('您沒有足夠的權限訪問此頁面。', 'risecreatives-optimization'));
}

// 獲取設定值
$options = get_option('risecreatives_opt_general', [
    'enable_admin_logo' => false,
    'copyright_color' => '#000000'
]);

// 處理表單提交
if (isset($_POST['risecreatives_save_general'])) {
    if (check_admin_referer('risecreatives_general_nonce')) {
        $new_options = [
            'enable_admin_logo' => isset($_POST['enable_admin_logo']),
            'copyright_color' => sanitize_hex_color($_POST['copyright_color'])
        ];
        
        update_option('risecreatives_opt_general', $new_options);
        risecreatives_purge_all_caches();
        $options = $new_options;
        echo '<div class="notice notice-success"><p>' . __('設定已更新', 'risecreatives-optimization') . '</p></div>';
    }
}

// 獲取短代碼文本
$shortcode_text = '[risecreatives_copyright color="' . esc_attr($options['copyright_color']) . '"]';
?>

<div class="wrap risecreatives-wrap">
    <div class="risecreatives-header">
        <h1><?php _e('一般設定', 'risecreatives-optimization'); ?></h1>
    </div>

    <div class="risecreatives-content">
        <form method="post" action="">
            <?php wp_nonce_field('risecreatives_general_nonce'); ?>
            
            <table class="form-table risecreatives-form-table">
                <!-- 後台 Logo 設定 -->
                <tr>
                    <th scope="row">
                        <label for="enable_admin_logo"><?php _e('後台 Logo', 'risecreatives-optimization'); ?></label>
                    </th>
                    <td>
                        <label class="risecreatives-switch">
                            <input type="checkbox" 
                                   id="enable_admin_logo" 
                                   name="enable_admin_logo" 
                                   <?php checked($options['enable_admin_logo']); ?>>
                            <span class="risecreatives-slider"></span>
                        </label>
                        <p class="description">
                            <?php _e('啟用後，將使用展躍網路 Logo 取代 WordPress 預設登入 Logo', 'risecreatives-optimization'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Copyright 設定 -->
                <tr>
                    <th scope="row">
                        <label for="copyright_color"><?php _e('Copyright 顏色', 'risecreatives-optimization'); ?></label>
                    </th>
                    <td>
                        <input type="color" 
                               id="copyright_color" 
                               name="copyright_color" 
                               value="<?php echo esc_attr($options['copyright_color']); ?>">
                        <p class="description">
                            <?php _e('選擇 Copyright 文字顏色', 'risecreatives-optimization'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Copyright 短代碼 -->
                <tr>
                    <th scope="row"><?php _e('Copyright 短代碼', 'risecreatives-optimization'); ?></th>
                    <td>
                        <div class="risecreatives-shortcode-wrap">
                            <input type="text" 
                                   class="regular-text" 
                                   value="<?php echo esc_attr($shortcode_text); ?>" 
                                   readonly>
                            <button type="button" 
                                    class="button button-secondary risecreatives-copy-shortcode" 
                                    data-shortcode="<?php echo esc_attr($shortcode_text); ?>">
                                <?php _e('複製短代碼', 'risecreatives-optimization'); ?>
                            </button>
                        </div>
                        <p class="description">
                            <?php _e('複製此短代碼並貼上到您想要顯示 Copyright 的地方', 'risecreatives-optimization'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" 
                       name="risecreatives_save_general" 
                       class="button button-primary" 
                       value="<?php _e('儲存設定', 'risecreatives-optimization'); ?>">
            </p>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // 複製短代碼功能
    $('.risecreatives-copy-shortcode').click(function() {
        var shortcode = $(this).data('shortcode');
        var tempInput = $('<input>');
        $('body').append(tempInput);
        tempInput.val(shortcode).select();
        document.execCommand('copy');
        tempInput.remove();
        
        // 顯示複製成功訊息
        var $button = $(this);
        var originalText = $button.text();
        $button.text('<?php _e('已複製！', 'risecreatives-optimization'); ?>');
        setTimeout(function() {
            $button.text(originalText);
        }, 2000);
    });
});
</script>
