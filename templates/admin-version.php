<?php
// templates/admin-version.php

// 防止直接訪問
if (!defined('ABSPATH')) {
    exit;
}

// 檢查權限
if (!current_user_can('manage_options')) {
    wp_die(__('您沒有足夠的權限訪問此頁面。', 'risecreatives-optimization'));
}

$updater = RiseCreatives_Optimization_Updater::get_instance();
$status  = $updater->get_status();
$info    = $status['info'];
$has_err = !empty($info['error']);

// 操作結果訊息
$notice_key = isset($_GET['risecreatives_notice']) ? sanitize_key(wp_unslash($_GET['risecreatives_notice'])) : '';
$notice_msg = isset($_GET['risecreatives_msg']) ? sanitize_text_field(wp_unslash($_GET['risecreatives_msg'])) : '';

$notices = [
    'checked'      => ['success', __('已完成檢查更新。', 'risecreatives-optimization')],
    'saved'        => ['success', __('更新來源設定已儲存。', 'risecreatives-optimization')],
    'invalid_repo' => ['error',   __('GitHub 儲存庫格式不正確，請輸入「owner/repo」或完整的 GitHub 網址。', 'risecreatives-optimization')],
    'check_error'  => ['error',   $notice_msg !== '' ? $notice_msg : __('檢查更新失敗。', 'risecreatives-optimization')],
];

// 時間顯示（使用網站時區）
$format_time = function ($timestamp) {
    return $timestamp ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $timestamp) : '—';
};

$performance_options = RiseCreatives_Optimization_Performance::get_instance()->get_options();
?>

<div class="wrap risecreatives-wrap">
    <div class="risecreatives-header">
        <h1><?php _e('版本資訊', 'risecreatives-optimization'); ?></h1>
    </div>

    <?php if (isset($notices[$notice_key])): ?>
        <div class="notice notice-<?php echo esc_attr($notices[$notice_key][0]); ?> is-dismissible">
            <p><?php echo esc_html($notices[$notice_key][1]); ?></p>
        </div>
    <?php endif; ?>

    <div class="risecreatives-content">

        <!-- 外掛版本 -->
        <h2 class="title"><?php _e('外掛版本', 'risecreatives-optimization'); ?></h2>
        <table class="form-table risecreatives-form-table" role="presentation">
            <tr>
                <th scope="row"><?php _e('目前版本', 'risecreatives-optimization'); ?></th>
                <td><strong>v<?php echo esc_html($status['current']); ?></strong></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('最新版本', 'risecreatives-optimization'); ?></th>
                <td>
                    <?php if ($has_err): ?>
                        <span style="color: #d63638;"><?php echo esc_html($info['error']); ?></span>
                    <?php else: ?>
                        <strong>v<?php echo esc_html($info['version']); ?></strong>
                        <?php if (!empty($info['published_at'])): ?>
                            <span class="description">
                                （<?php echo esc_html(sprintf(__('發布於 %s', 'risecreatives-optimization'), wp_date(get_option('date_format'), strtotime($info['published_at'])))); ?>）
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('狀態', 'risecreatives-optimization'); ?></th>
                <td>
                    <?php if ($has_err): ?>
                        <span>—</span>
                    <?php elseif ($status['has_update']): ?>
                        <span style="color: #d63638; font-weight: 600;"><?php _e('有新版本可更新', 'risecreatives-optimization'); ?></span>
                        <?php if (!empty($info['package'])): ?>
                            <p>
                                <a href="<?php echo esc_url($updater->get_update_url()); ?>" class="button button-primary">
                                    <?php _e('立即更新', 'risecreatives-optimization'); ?>
                                </a>
                                <?php if (!empty($info['html_url'])): ?>
                                    <a href="<?php echo esc_url($info['html_url']); ?>" target="_blank" rel="noopener noreferrer" class="button">
                                        <?php _e('查看 Release 頁面', 'risecreatives-optimization'); ?>
                                    </a>
                                <?php endif; ?>
                            </p>
                            <p class="description">
                                <?php _e('更新前建議先備份網站。更新完成後，請清除快取並確認網站運作正常。', 'risecreatives-optimization'); ?>
                            </p>
                        <?php else: ?>
                            <p class="description" style="color: #d63638;">
                                <?php _e('此 Release 沒有可下載的 ZIP 檔，請到 GitHub 確認 Release 內容。', 'risecreatives-optimization'); ?>
                            </p>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color: #00a32a; font-weight: 600;"><?php _e('已是最新版本', 'risecreatives-optimization'); ?></span>
                    <?php endif; ?>

                    <?php if (!empty($performance_options['disable_auto_updates'])): ?>
                        <p class="description">
                            <?php _e('提示：「效能設定」已停用自動更新，需手動按「立即更新」。', 'risecreatives-optimization'); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('上次檢查', 'risecreatives-optimization'); ?></th>
                <td>
                    <?php echo esc_html($format_time(isset($info['checked_at']) ? $info['checked_at'] : 0)); ?>
                    <span class="description">（<?php _e('系統每 12 小時自動檢查一次', 'risecreatives-optimization'); ?>）</span>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 8px;">
                        <input type="hidden" name="action" value="risecreatives_check_update">
                        <?php wp_nonce_field('risecreatives_check_update'); ?>
                        <?php submit_button(__('立即檢查更新', 'risecreatives-optimization'), 'secondary', 'submit', false); ?>
                    </form>
                </td>
            </tr>
            <?php if (!$has_err && !empty($info['body'])): ?>
            <tr>
                <th scope="row"><?php _e('最新版更新說明', 'risecreatives-optimization'); ?></th>
                <td>
                    <pre style="white-space: pre-wrap; max-width: 720px; background: #fff; border: 1px solid #dcdcde; padding: 12px; margin: 0;"><?php echo esc_html($info['body']); ?></pre>
                </td>
            </tr>
            <?php endif; ?>
        </table>

        <!-- 更新來源設定 -->
        <h2 class="title"><?php _e('更新來源設定', 'risecreatives-optimization'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="risecreatives_save_updater">
            <?php wp_nonce_field('risecreatives_save_updater'); ?>

            <table class="form-table risecreatives-form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="github_repo"><?php _e('GitHub 儲存庫', 'risecreatives-optimization'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="github_repo"
                               name="github_repo"
                               class="regular-text"
                               placeholder="owner/repo"
                               value="<?php echo esc_attr($status['repo']); ?>"
                               <?php disabled($updater->is_repo_locked()); ?>>
                        <p class="description">
                            <?php _e('格式為「owner/repo」，也可貼上完整的 GitHub 網址。外掛會讀取該儲存庫最新的 Release（tag 名稱即版本號，例如 v1.3.1），並使用 Release 附加的 ZIP 檔更新。', 'risecreatives-optimization'); ?>
                        </p>
                        <?php if ($updater->is_repo_locked()): ?>
                            <p class="description"><?php _e('此欄位已由 wp-config.php 的 RISECREATIVES_GITHUB_REPO 常數鎖定。', 'risecreatives-optimization'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="github_token"><?php _e('存取 Token（選填）', 'risecreatives-optimization'); ?></label>
                    </th>
                    <td>
                        <input type="password"
                               id="github_token"
                               name="github_token"
                               class="regular-text"
                               autocomplete="new-password"
                               placeholder="<?php echo $status['has_token'] ? esc_attr__('已設定（留空表示不變更）', 'risecreatives-optimization') : ''; ?>"
                               <?php disabled($updater->is_token_locked()); ?>>
                        <?php if ($status['has_token'] && !$updater->is_token_locked()): ?>
                            <label style="margin-left: 8px;">
                                <input type="checkbox" name="clear_github_token" value="1">
                                <?php _e('清除已儲存的 Token', 'risecreatives-optimization'); ?>
                            </label>
                        <?php endif; ?>
                        <p class="description">
                            <?php _e('公開儲存庫不需要。私有儲存庫請使用僅具備唯讀權限的 Fine-grained Token。Token 會儲存在資料庫中，若不想存在資料庫，可改在 wp-config.php 定義 RISECREATIVES_GITHUB_TOKEN。', 'risecreatives-optimization'); ?>
                        </p>
                        <?php if ($updater->is_token_locked()): ?>
                            <p class="description"><?php _e('此欄位已由 wp-config.php 的 RISECREATIVES_GITHUB_TOKEN 常數鎖定。', 'risecreatives-optimization'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('儲存設定', 'risecreatives-optimization')); ?>
        </form>

        <!-- 系統資訊（原放在「效能設定」） -->
        <h2 class="title"><?php _e('系統資訊', 'risecreatives-optimization'); ?></h2>
        <table class="form-table risecreatives-form-table" role="presentation">
            <tr>
                <th scope="row"><?php _e('PHP 版本', 'risecreatives-optimization'); ?></th>
                <td><?php echo esc_html(PHP_VERSION); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('WordPress 版本', 'risecreatives-optimization'); ?></th>
                <td><?php echo esc_html(get_bloginfo('version')); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('記憶體限制', 'risecreatives-optimization'); ?></th>
                <td>
                    <?php
                    // 獲取 WordPress 的實際記憶體限制
                    $wp_memory_limit     = wp_convert_hr_to_bytes(WP_MEMORY_LIMIT);
                    $wp_max_memory_limit = wp_convert_hr_to_bytes(WP_MAX_MEMORY_LIMIT);

                    // 獲取 PHP 的記憶體限制
                    $php_memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));

                    // 使用最大值
                    echo esc_html(size_format(max($wp_memory_limit, $wp_max_memory_limit, $php_memory_limit)));
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('最大執行時間', 'risecreatives-optimization'); ?></th>
                <td><?php echo esc_html(ini_get('max_execution_time')); ?> <?php _e('秒', 'risecreatives-optimization'); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('上傳檔案大小限制', 'risecreatives-optimization'); ?></th>
                <td><?php echo esc_html(ini_get('upload_max_filesize')); ?></td>
            </tr>
        </table>
    </div>
</div>
