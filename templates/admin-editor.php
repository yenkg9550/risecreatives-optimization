<?php
// templates/admin-editor.php

// 防止直接訪問
if (!defined('ABSPATH')) {
    exit;
}

// 檢查權限
if (!current_user_can('manage_options')) {
    wp_die(__('您沒有足夠的權限訪問此頁面。', 'risecreatives-optimization'));
}

// 獲取設定值
$options = get_option('risecreatives_opt_editor', [
    'enable_classic_editor' => false,
    'enable_classic_widgets' => false,
    'force_classic_editor' => false
]);

// 處理表單提交
if (isset($_POST['risecreatives_save_editor'])) {
    if (check_admin_referer('risecreatives_editor_nonce')) {
        $new_options = [
            'enable_classic_editor' => isset($_POST['enable_classic_editor']),
            'enable_classic_widgets' => isset($_POST['enable_classic_widgets']),
            'force_classic_editor' => isset($_POST['force_classic_editor'])
        ];
        
        update_option('risecreatives_opt_editor', $new_options);
        risecreatives_purge_all_caches();
        $options = $new_options;
        
        echo '<div class="notice notice-success is-dismissible"><p>' . 
             __('設定已更新，請重新整理頁面以套用變更。', 'risecreatives-optimization') . '</p></div>';
    }
}
?>

<div class="wrap risecreatives-wrap">
    <div class="risecreatives-header">
        <h1><?php _e('編輯器設定', 'risecreatives-optimization'); ?></h1>
    </div>

    <div class="risecreatives-content">
        <form method="post" action="">
            <?php wp_nonce_field('risecreatives_editor_nonce'); ?>
            
            <table class="form-table">
                <!-- 傳統編輯器設定 -->
                <tr>
                    <th scope="row">
                        <?php _e('傳統編輯器', 'risecreatives-optimization'); ?>
                    </th>
                    <td>
                        <label class="risecreatives-switch">
                            <input type="checkbox" 
                                   name="enable_classic_editor" 
                                   class="editor-toggle"
                                   data-target="classic-editor-options"
                                   <?php checked($options['enable_classic_editor']); ?>>
                            <span class="risecreatives-slider"></span>
                        </label>
                        <p class="description">
                            <?php _e('啟用後將使用傳統 (TinyMCE) 編輯器替代區塊編輯器', 'risecreatives-optimization'); ?>
                        </p>

                        <div id="classic-editor-options" 
                             class="editor-config"
                             style="display: <?php echo $options['enable_classic_editor'] ? 'block' : 'none'; ?>">
                            <label>
                                <input type="checkbox" 
                                       name="force_classic_editor" 
                                       <?php checked($options['force_classic_editor']); ?>>
                                <?php _e('完全移除區塊編輯器資源', 'risecreatives-optimization'); ?>
                            </label>
                            <p class="description" style="color: #d63638;">
                                <?php _e('注意：這將移除所有 Gutenberg 相關資源，可能會影響使用區塊功能的主題或外掛', 'risecreatives-optimization'); ?>
                            </p>
                        </div>
                    </td>
                </tr>

                <!-- 傳統小工具設定 -->
                <tr>
                    <th scope="row">
                        <?php _e('傳統小工具', 'risecreatives-optimization'); ?>
                    </th>
                    <td>
                        <label class="risecreatives-switch">
                            <input type="checkbox" 
                                   name="enable_classic_widgets" 
                                   <?php checked($options['enable_classic_widgets']); ?>>
                            <span class="risecreatives-slider"></span>
                        </label>
                        <p class="description">
                            <?php _e('啟用後將使用傳統小工具介面替代區塊小工具編輯器', 'risecreatives-optimization'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('儲存設定', 'risecreatives-optimization'), 'primary', 'risecreatives_save_editor'); ?>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // 編輯器選項切換
    $('.editor-toggle').on('change', function() {
        var targetId = '#' + $(this).data('target');
        $(targetId).slideToggle(300);
    });
});
</script>