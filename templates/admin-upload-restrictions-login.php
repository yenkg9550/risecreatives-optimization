<?php
// templates/admin-upload-restrictions-login.php
?>
<div class="wrap">
    <h1><?php _e('上傳限制設定', 'risecreatives-optimization'); ?></h1>
    
    <form method="post" class="card" style="max-width: 520px; padding: 20px; margin-top: 20px;">
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="settings_password"><?php _e('請輸入密碼', 'risecreatives-optimization'); ?></label>
                </th>
                <td>
                    <input type="password" 
                           id="settings_password" 
                           name="settings_password" 
                           class="regular-text" 
                           required>
                </td>
            </tr>
        </table>
        
        <?php submit_button(__('進入設定', 'risecreatives-optimization')); ?>
    </form>
</div>