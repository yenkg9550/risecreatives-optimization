<?php
// templates/admin-upload-restrictions.php

// 獲取設定
$options = RiseCreatives_Upload_Restrictions::get_instance()->get_options();
error_log('Upload Restrictions - Page Load Options: ' . print_r($options, true));

?>
<div class="wrap">
    <h1><?php _e('上傳限制設定', 'risecreatives-optimization'); ?></h1>

    <?php
    // 在表格中 WebP 設定區塊添加狀態資訊
    ?>
    
    <form id="upload-restrictions-form" method="post">
        <?php wp_nonce_field('risecreatives_upload_restrictions_nonce'); ?>
        <input type="hidden" name="settings_password" value="<?php echo esc_attr($_POST['settings_password']); ?>">
        
        <table class="form-table">
            <!-- 原有的上傳大小限制設定 -->
            <tr>
                <th scope="row">
                    <?php _e('最大上傳大小', 'risecreatives-optimization'); ?>
                </th>
                <td>
                    <input type="number" 
                        name="max_size" 
                        value="<?php echo esc_attr($options['max_size']); ?>" 
                        step="0.1" 
                        min="0.1" 
                        max="100" 
                        class="small-text">
                    MB
                </td>
            </tr>
            
            <!-- 原有的檔案類型限制設定 -->
            <tr>
                <th scope="row">
                    <?php _e('允許的檔案類型', 'risecreatives-optimization'); ?>
                </th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">
                            <?php _e('允許的檔案類型', 'risecreatives-optimization'); ?>
                        </legend>
                        <?php foreach ($options['allowed_types'] as $type => $enabled): ?>
                            <label style="display: inline-block; margin-right: 20px; margin-bottom: 10px;">
                                <input type="checkbox" 
                                    name="file_types[<?php echo esc_attr($type); ?>]" 
                                    <?php checked($enabled); ?>>
                                .<?php echo esc_html($type); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <?php _e('圖片 SEO 優化', 'risecreatives-optimization'); ?>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            name="enable_image_seo" 
                            id="enable_image_seo"
                            value="1"
                            <?php checked(!empty($options['enable_image_seo'])); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('自動設定圖片的 Alt 文字與描述', 'risecreatives-optimization'); ?>
                        <br>
                        <?php _e('格式：檔案名稱 - 網站名稱', 'risecreatives-optimization'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <?php _e('WebP 圖片轉換', 'risecreatives-optimization'); ?>
                </th>
                <td>
                    <label class="risecreatives-switch">
                        <input type="checkbox" 
                            name="enable_webp" 
                            id="enable_webp"
                            value="1"
                            <?php checked(!empty($options['enable_webp'])); ?>>
                        <span class="risecreatives-slider"></span>
                    </label>
                    <p class="description">
                        <?php _e('自動將上傳的圖片轉換為 WebP 格式以提升載入速度', 'risecreatives-optimization'); ?>
                    </p>

                    <!-- 系統檢查 -->
                    <div id="webp-system-check" style="margin-top: 10px; <?php echo empty($options['enable_webp']) ? 'display: none;' : ''; ?>">
                        <?php
                        $memory_limit = ini_get('memory_limit');
                        $memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit);
                        $webp_supported = function_exists('imagewebp');
                        ?>
                        
                        <div class="notice <?php echo ($webp_supported && $memory_limit_bytes >= 256 * 1024 * 1024) ? 'notice-success' : 'notice-error'; ?> inline">
                            <p>
                                <strong><?php _e('系統檢查：', 'risecreatives-optimization'); ?></strong><br>
                                <?php if ($webp_supported): ?>
                                    ✓ <?php _e('支援 WebP 轉換', 'risecreatives-optimization'); ?><br>
                                <?php else: ?>
                                    ✗ <?php _e('不支援 WebP 轉換，請安裝 PHP GD 擴充功能', 'risecreatives-optimization'); ?><br>
                                <?php endif; ?>
                                
                                <?php if ($memory_limit_bytes >= 256 * 1024 * 1024): ?>
                                    ✓ <?php printf(__('記憶體限制充足（目前：%s）', 'risecreatives-optimization'), $memory_limit); ?><br>
                                <?php else: ?>
                                    ✗ <?php printf(__('記憶體限制不足（目前：%s，建議：256M）', 'risecreatives-optimization'), $memory_limit); ?><br>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <!-- WebP 設定選項 -->
                    <div id="webp-settings" class="webp-settings" style="margin-top: 15px; <?php echo empty($options['enable_webp']) ? 'display: none;' : ''; ?>">
                        <!-- 轉換品質設定 -->
                        <div class="webp-quality-setting" style="margin-bottom: 15px;">
                            <label>
                                <?php _e('轉換品質', 'risecreatives-optimization'); ?>
                                <input type="number" 
                                    name="webp_quality" 
                                    value="<?php echo esc_attr($options['webp_quality'] ?? 85); ?>"
                                    min="1" 
                                    max="100" 
                                    class="small-text">
                            </label>
                            <p class="description">
                                <?php _e('設定 WebP 轉換品質（1-100），預設為 85。品質越高，檔案越大。', 'risecreatives-optimization'); ?>
                            </p>
                        </div>

                        <!-- 要轉換的檔案類型 -->
                        <div class="convert-types-setting">
                            <p style="margin-bottom: 10px;">
                                <?php _e('選擇要轉換的圖片格式：', 'risecreatives-optimization'); ?>
                            </p>
                            <?php
                            $convert_types = $options['convert_types'] ?? ['jpg' => true, 'jpeg' => true, 'png' => true];
                            foreach ($convert_types as $type => $enabled): ?>
                                <label style="display: inline-block; margin-right: 20px;">
                                    <input type="checkbox" 
                                        name="convert_types[<?php echo esc_attr($type); ?>]" 
                                        value="1"
                                        <?php checked($enabled); ?>>
                                    .<?php echo esc_html($type); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // WebP 設定顯示/隱藏控制
    $('#enable_webp').on('change', function() {
        if ($(this).is(':checked')) {
            if (!<?php echo $webp_supported ? 'true' : 'false'; ?>) {
                alert('<?php _e('您的伺服器不支援 WebP 轉換功能，請先安裝必要的 PHP 擴充功能。', 'risecreatives-optimization'); ?>');
                $(this).prop('checked', false);
                return;
            }
            
            if (<?php echo $memory_limit_bytes < 256 * 1024 * 1024 ? 'true' : 'false'; ?>) {
                alert('<?php _e('伺服器記憶體可能不足，建議增加記憶體限制至少 256MB。', 'risecreatives-optimization'); ?>');
            }
            
            $('#webp-system-check, #webp-settings').slideDown();
        } else {
            $('#webp-system-check, #webp-settings').slideUp();
        }
    });
    
    // 初始顯示狀態
    if ($('#enable_webp').is(':checked')) {
        $('#webp-system-check, #webp-settings').show();
    }
});

jQuery(document).ready(function($) {
    // WebP 設定顯示/隱藏控制
    $('#enable_webp').on('change', function() {
        if ($(this).is(':checked')) {
            $('#webp-settings').slideDown();
        } else {
            $('#webp-settings').slideUp();
        }
    });

    // 表單提交處理
    $('#upload-restrictions-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = new FormData(this);
        formData.append('action', 'save_upload_restrictions');
        
        // 停用提交按鈕
        var $submitButton = $(this).find(':submit');
        $submitButton.prop('disabled', true);
        
        // 發送 AJAX 請求
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    alert('設定已更新');
                } else {
                    alert('更新失敗：' + response.data);
                }
            },
            error: function() {
                alert('發生錯誤，請稍後再試');
            },
            complete: function() {
                $submitButton.prop('disabled', false);
            }
        });
    });
});

jQuery(document).ready(function($) {
    // 定期更新轉換狀態
    function updateConversionStatus() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_conversion_status',
                nonce: $('#_wpnonce').val()
            },
            success: function(response) {
                if (response.success && response.data) {
                    var status = response.data;
                    
                    // 更新進度條
                    if (status.total > 0) {
                        var progress = Math.round((status.converted / status.total) * 100);
                        $('.progress-bar').css('width', progress + '%');
                    }
                    
                    // 更新統計數據
                    $('#converted-count').text(status.converted);
                    $('#failed-count').text(status.failed);
                    $('#last-update').text(status.last_update);
                    
                    // 顯示錯誤訊息（如果有）
                    if (status.last_error) {
                        $('.status-text').html('<strong>' + status.last_error + '</strong>');
                    }
                    
                    // 如果正在進行轉換，繼續更新
                    if (status.in_progress) {
                        setTimeout(updateConversionStatus, 2000);
                    }
                }
            }
        });
    }

    // 如果啟用了 WebP 轉換，開始更新狀態
    if ($('#enable_webp').is(':checked')) {
        $('#webp-conversion-status').show();
        updateConversionStatus();
    }
    
    // 當啟用狀態改變時更新顯示
    $('#enable_webp').on('change', function() {
        $('#webp-conversion-status').toggle($(this).is(':checked'));
        if ($(this).is(':checked')) {
            updateConversionStatus();
        }
    });
});
</script>

<style>
.conversion-progress {
    height: 20px;
    background-color: #f0f0f0;
    border-radius: 3px;
    margin: 10px 0;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background-color: #0073aa;
    width: 0;
    transition: width 0.3s ease-in-out;
}

.webp-status-info ul {
    list-style: none;
    margin: 0;
    padding: 0;
}

.webp-status-info li {
    margin: 5px 0;
}
</style>