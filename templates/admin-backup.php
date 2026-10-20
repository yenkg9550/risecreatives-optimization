<?php
// templates/admin-backup.php

// 防止直接訪問
if (!defined('ABSPATH')) {
    exit;
}

// 檢查權限
if (!current_user_can('manage_options')) {
    wp_die(__('您沒有足夠的權限訪問此頁面。', 'risecreatives-optimization'));
}

$backup   = RiseCreatives_Optimization_Backup::get_instance();
$s        = $backup->get_settings();
$items    = $backup->list_backups();
$status   = $backup->read_status();
$running  = isset($status['state']) && $status['state'] === 'running';
$zip_ok   = $backup->is_zip_available();

$notice_key = isset($_GET['risecreatives_notice']) ? sanitize_key(wp_unslash($_GET['risecreatives_notice'])) : '';
$notice_msg = isset($_GET['risecreatives_msg']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['risecreatives_msg']))) : '';

$notices = [
    'saved'                 => ['success', '備份設定已儲存。'],
    'no_content'            => ['error',   '請至少勾選一項備份內容，設定未儲存。'],
    'github_incomplete'     => ['error',   '要啟用 GitHub，請先填寫儲存庫與 Token。其他設定已儲存，但 GitHub 尚未啟用。'],
    'gdrive_not_authorized' => ['error',   '要啟用 Google Drive，請先完成「授權」。其他設定已儲存，但 Google Drive 尚未啟用。'],
    'gdrive_no_client'      => ['error',   '請先填寫並儲存 Google 的 Client ID 與 Client Secret，再按「授權」。'],
    'gdrive_denied'         => ['error',   'Google 授權已取消或被拒絕。' . ($notice_msg !== '' ? '（' . $notice_msg . '）' : '')],
    'gdrive_error'          => ['error',   'Google 授權失敗：' . $notice_msg],
    'gdrive_ok'             => ['success', 'Google Drive 授權成功。' . ($gd_need_reauth ? '但這次沒有允許存取 Google Drive，無法備份到指定的資料夾，請再授權一次並勾選允許。' : '可以按「測試連線」確認，再勾選「備份完成後上傳到 Google Drive」。')],
    'gdrive_disconnected'   => ['success', '已解除 Google Drive 授權。'],
];

$content_labels = ['db' => '資料庫', 'uploads' => '上傳檔案', 'plugins' => '外掛', 'themes' => '主題', 'core' => 'WordPress 核心'];
$type_labels    = ['auto' => '排程', 'manual' => '手動', 'pre_restore' => '還原前安全備份'];
$freq_labels    = ['off' => '關閉自動備份', 'daily' => '每天', 'weekly' => '每週', 'monthly' => '每月'];

$ajax_nonce = wp_create_nonce(RiseCreatives_Optimization_Backup::NONCE);
$authorized = $s['gdrive_refresh_token'] !== '';
$gd_target_url  = RiseCreatives_Backup_GDrive::folder_url((string) $s['gdrive_target']);
$gd_need_reauth = $s['gdrive_target'] !== '' && $authorized && $s['gdrive_scope'] !== 'full';
$active_tab = strpos($notice_key, 'gdrive') === 0 ? 'gdrive' : 'github';
?>

<style>
/* 按鈕：以 --rc 保存底色，hover／focus／active 時維持同色並略為加深（避免被 WordPress 預設的淺灰色 hover 蓋掉） */
.rc-backup-page .rc-btn.button,
.rc-backup-page a.rc-btn {
    display: inline-block;
    box-sizing: border-box;
    min-height: 44px;
    padding: 0 28px;
    margin: 0 10px 10px 0;
    border: 0;
    border-radius: 6px;
    background: var(--rc, #2271b1);
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    line-height: 44px;
    text-decoration: none;
    cursor: pointer;
    box-shadow: none;
    vertical-align: middle;
    transition: filter .15s, transform .15s;
}
.rc-backup-page .rc-btn.button:hover,
.rc-backup-page .rc-btn.button:focus,
.rc-backup-page .rc-btn.button:active,
.rc-backup-page a.rc-btn:hover,
.rc-backup-page a.rc-btn:focus,
.rc-backup-page a.rc-btn:active {
    background: var(--rc, #2271b1);
    color: #fff;
    border: 0;
    box-shadow: none;
    outline: none;
    filter: brightness(.88);
}
.rc-backup-page .rc-btn.button:active,
.rc-backup-page a.rc-btn:active { transform: translateY(1px); }
.rc-backup-page .rc-btn.button:disabled,
.rc-backup-page .rc-btn.button[disabled] { color: #fff !important; background: #8c8f94 !important; opacity: .6; cursor: not-allowed; filter: none; transform: none; }
.rc-backup-page .rc-btn-lg.button { min-height: 54px; line-height: 54px; padding: 0 44px; font-size: 17px; }
.rc-backup-page .rc-btn-sm.button,
.rc-backup-page a.rc-btn-sm { min-height: 38px; line-height: 38px; padding: 0 20px; font-size: 14px; margin: 3px 8px 3px 0; }
.rc-backup-page .rc-blue   { --rc: #2271b1; }
.rc-backup-page .rc-green  { --rc: #1a8a3a; }
.rc-backup-page .rc-teal   { --rc: #0e7490; }
.rc-backup-page .rc-orange { --rc: #c2410c; }
.rc-backup-page .rc-spinner { flex: 0 0 auto; width: 18px; height: 18px; box-sizing: border-box; border: 3px solid #dcdcde; border-top-color: #2271b1; border-radius: 50%; animation: rc-spin .8s linear infinite; }
@keyframes rc-spin { to { transform: rotate(360deg); } }
.rc-backup-page .rc-test.button, .rc-backup-page #rc-gd-auth-btn.button { min-width: 116px; }
.rc-backup-page .rc-tab-panel { display: none; background: #fff; border: 1px solid #c3c4c7; border-top: 0; padding: 12px 24px 24px; }
.rc-backup-page .rc-tab-panel.is-active { display: block; }
.rc-backup-page .rc-tabs { margin-top: 28px; }
.rc-backup-page .rc-purple { --rc: #6d28d9; }
.rc-backup-page .rc-red    { --rc: #d63638; }
.rc-backup-page .rc-dark   { --rc: #1d2327; }

/* 對話框（取代瀏覽器預設的 alert／confirm） */
.rc-modal-overlay { position: fixed; inset: 0; z-index: 100100; display: flex; align-items: center; justify-content: center; padding: 20px; background: rgba(15, 23, 42, .55); opacity: 0; transition: opacity .18s; }
.rc-modal-overlay.is-open { opacity: 1; }
.rc-modal { position: relative; width: 100%; max-width: 560px; max-height: calc(100vh - 40px); overflow: auto; box-sizing: border-box; background: #fff; border-radius: 14px; box-shadow: 0 24px 60px rgba(0, 0, 0, .28); padding: 30px 32px 26px; transform: translateY(12px) scale(.98); transition: transform .18s; color: #1d2327; }
.rc-modal-overlay.is-open .rc-modal { transform: none; }
.rc-modal-head { display: flex; align-items: center; gap: 14px; margin: 0 0 18px; }
.rc-modal-icon { flex: 0 0 auto; width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; line-height: 1; }
.rc-modal-title { margin: 0; font-size: 21px; font-weight: 700; line-height: 1.4; color: #1d2327; }
.rc-modal-body { font-size: 16px; line-height: 1.75; color: #2c3338; }
.rc-modal-body p { font-size: 16px; line-height: 1.75; margin: 0 0 12px; }
.rc-modal-body ul { margin: 0 0 14px; padding: 0; list-style: none; }
.rc-modal-body li { position: relative; margin: 0 0 10px; padding-left: 22px; font-size: 16px; line-height: 1.7; }
.rc-modal-body li::before { content: ""; position: absolute; left: 4px; top: .7em; width: 7px; height: 7px; border-radius: 50%; background: var(--rc-tone, #2271b1); }
.rc-modal-note { margin: 4px 0 0; padding: 12px 16px; border-radius: 8px; background: #fef3c7; color: #78350f; font-size: 15px; line-height: 1.6; }
.rc-modal-check { display: flex; align-items: flex-start; gap: 10px; margin: 18px 0 0; padding: 14px 16px; border: 1px solid #dcdcde; border-radius: 8px; font-size: 16px; line-height: 1.6; cursor: pointer; }
.rc-modal-check input[type="checkbox"] { margin: 4px 0 0; width: 18px; height: 18px; }
.rc-modal-foot { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 26px; }
.rc-modal-foot .rc-mbtn { min-height: 46px; padding: 0 26px; border: 0; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: filter .15s; }
.rc-modal-foot .rc-mbtn:hover, .rc-modal-foot .rc-mbtn:focus { filter: brightness(.9); outline: none; }
.rc-modal-foot .rc-mbtn:focus-visible { box-shadow: 0 0 0 3px rgba(34, 113, 177, .35); }
.rc-modal-foot .rc-mbtn-cancel { background: #f0f0f1; color: #1d2327; }
.rc-modal-foot .rc-mbtn-ok { background: var(--rc-tone, #2271b1); color: #fff; }
.rc-modal-foot .rc-mbtn-ok:disabled { background: #a7aaad; cursor: not-allowed; filter: none; }
.rc-modal.rc-tone-info    { --rc-tone: #2271b1; } .rc-tone-info .rc-modal-icon    { background: #e7f0f8; color: #2271b1; }
.rc-modal.rc-tone-success { --rc-tone: #1a8a3a; } .rc-tone-success .rc-modal-icon { background: #e6f4ea; color: #1a8a3a; }
.rc-modal.rc-tone-warn    { --rc-tone: #c2410c; } .rc-tone-warn .rc-modal-icon    { background: #ffedd5; color: #c2410c; }
.rc-modal.rc-tone-danger  { --rc-tone: #d63638; } .rc-tone-danger .rc-modal-icon  { background: #fde8e8; color: #d63638; }
@media (max-width: 600px) { .rc-modal { padding: 24px 20px 20px; } .rc-modal-foot .rc-mbtn { flex: 1 1 auto; } }

/* 間距 */
.rc-backup-page .form-table td { padding-top: 24px; padding-bottom: 24px; }
.rc-backup-page .form-table th { padding-top: 28px; }
.rc-backup-page .rc-field { margin: 0 0 30px; }
.rc-backup-page .rc-field:last-child { margin-bottom: 0; }
.rc-backup-page .rc-field > .rc-label { display: block; margin: 0 0 10px; font-weight: 600; }
.rc-backup-page .rc-field input[type="text"],
.rc-backup-page .rc-field input[type="password"] { display: block; margin: 0; height: 42px; }
.rc-backup-page .rc-checks label { display: block; margin: 0 0 16px; line-height: 1.6; }
.rc-backup-page .rc-checks label:last-child { margin-bottom: 0; }
.rc-backup-page .rc-keep { display: flex; align-items: center; gap: 10px; }
.rc-backup-page .rc-keep input { height: 40px; margin: 0; }
.rc-backup-page .rc-note { margin: 22px 0 0; line-height: 1.9; }
.rc-backup-page .rc-steps { margin: 24px 0 0; }
.rc-backup-page .rc-step { margin: 0 0 18px; line-height: 1.9; color: #50575e; }
.rc-backup-page .rc-step code { padding: 2px 6px; }
.rc-backup-page .rc-step code.rc-uri { display: inline-block; margin-top: 10px; padding: 8px 12px; }
.rc-backup-page .rc-test-row { display: flex; align-items: center; flex-wrap: wrap; }
.rc-backup-page .rc-test-result { margin-left: 4px; }
.rc-backup-page .rc-actions { margin: 32px 0 0; }
.rc-backup-page .rc-actions form { display: inline-block; margin: 0; }
.rc-backup-page #rc-backup-list td { vertical-align: middle; padding-top: 14px; padding-bottom: 14px; }
</style>

<div class="wrap risecreatives-wrap rc-backup-page">
    <div class="risecreatives-header">
        <h1><?php _e('備份管理', 'risecreatives-optimization'); ?></h1>
    </div>

    <?php if (isset($notices[$notice_key])): ?>
        <div class="notice notice-<?php echo esc_attr($notices[$notice_key][0]); ?> is-dismissible">
            <p><?php echo esc_html($notices[$notice_key][1]); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!$zip_ok): ?>
        <div class="notice notice-error"><p>此主機沒有安裝 PHP ZipArchive 擴充，無法建立備份。請聯絡主機商開啟 zip 擴充。</p></div>
    <?php endif; ?>

    <div class="risecreatives-content">

        <!-- 立即備份與狀態 -->
        <h2 class="title"><?php _e('備份狀態', 'risecreatives-optimization'); ?></h2>
        <table class="form-table risecreatives-form-table" role="presentation">
            <tr>
                <th scope="row"><?php _e('立即備份', 'risecreatives-optimization'); ?></th>
                <td>
                    <button type="button" class="button rc-btn rc-green" id="rc-backup-start" <?php disabled(!$zip_ok || $running); ?>>
                        <?php _e('立即備份', 'risecreatives-optimization'); ?>
                    </button>
                    <div id="rc-backup-progress" style="display:none; margin-top:16px; max-width:560px;">
                        <div style="background:#dcdcde; border-radius:4px; height:16px; overflow:hidden;">
                            <div id="rc-backup-bar" style="background:#2271b1; height:16px; width:0; transition:width .4s;"></div>
                        </div>
                        <div style="display:flex; align-items:center; gap:10px; margin-top:10px;"><span class="rc-spinner" aria-hidden="true"></span><p id="rc-backup-message" class="description" style="margin:0;"></p></div>
                        <div id="rc-backup-controls" style="display:none; margin-top:12px;">
                            <button type="button" class="button rc-btn rc-btn-sm rc-orange rc-ctrl" data-cmd="pause" id="rc-backup-pause">暫停</button>
                            <button type="button" class="button rc-btn rc-btn-sm rc-green rc-ctrl" data-cmd="resume" id="rc-backup-resume" style="display:none;">繼續</button>
                            <button type="button" class="button rc-btn rc-btn-sm rc-red rc-ctrl" data-cmd="cancel" id="rc-backup-cancel">取消備份</button>
                        </div>
                    </div>
                    <p id="rc-backup-last" class="description" style="margin-top:12px;">
                        <?php
                        if (!$running && !empty($status['message'])) {
                            $when = !empty($status['finished']) ? wp_date(RISECREATIVES_DATETIME_FORMAT, (int) $status['finished']) : '';
                            echo esc_html('最近一次：' . $status['message'] . ($when ? '（' . $when . '）' : ''));
                        }
                        ?>
                    </p>
                    <p class="description" style="margin-top:12px;">備份在背景執行，可以離開此頁；回來後會顯示最新進度。備份途中可以暫停、繼續或取消（還原時不提供，以免網站停在還原一半）。</p>
                </td>
            </tr>
                <tr>
                    <th scope="row"><?php _e('備份內容', 'risecreatives-optimization'); ?></th>
                    <td>
                        <div class="rc-checks">
                            <label><input type="checkbox" name="backup[include_db]" form="rc-backup-form" value="1" <?php checked($s['include_db']); ?>> 資料庫（文章、設定、使用者等）</label>
                            <label><input type="checkbox" name="backup[include_uploads]" form="rc-backup-form" value="1" <?php checked($s['include_uploads']); ?>> 上傳檔案（wp-content/uploads）</label>
                            <label><input type="checkbox" name="backup[include_plugins]" form="rc-backup-form" value="1" <?php checked($s['include_plugins']); ?>> 外掛（wp-content/plugins）</label>
                            <label><input type="checkbox" name="backup[include_themes]" form="rc-backup-form" value="1" <?php checked($s['include_themes']); ?>> 主題（wp-content/themes）</label>
                            <label><input type="checkbox" name="backup[include_core]" form="rc-backup-form" value="1" <?php checked($s['include_core']); ?>> WordPress 核心（wp-admin、wp-includes、網站根目錄檔案與 wp-config.php）</label>
                        </div>
                        <p class="description rc-note">還原時不會覆蓋 wp-config.php 與本外掛本身。變更後會自動儲存。</p>
                    </td>
                </tr>
            <tr>
                <th scope="row"><?php _e('下次自動備份', 'risecreatives-optimization'); ?></th>
                <td>
                    <span id="rc-next-run"><?php echo esc_html($backup->describe_next_run()); ?></span>
                    <p class="description" style="margin-top:12px;">排程依賴 WP-Cron，網站需要有訪客瀏覽才會觸發；流量低的網站建議在主機設定一個定時呼叫 wp-cron.php 的工作。</p>
                </td>
            </tr>
        </table>

        <!-- 備份清單 -->
        <h2 class="title"><?php _e('備份清單', 'risecreatives-optimization'); ?></h2>
        <table class="wp-list-table widefat fixed striped" id="rc-backup-list">
            <thead>
                <tr>
                    <th style="width:170px;">時間</th>
                    <th>內容</th>
                    <th style="width:100px;">類型</th>
                    <th style="width:90px;">大小</th>
                    <th style="width:180px;">存放位置</th>
                    <th style="width:290px;">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$items): ?>
                <tr><td colspan="6">目前沒有備份。</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $item):
                $labels = [];
                foreach ((array) (isset($item['contents']) ? $item['contents'] : []) as $c) {
                    if (isset($content_labels[$c])) {
                        $labels[] = $content_labels[$c];
                    }
                }
                $type = isset($item['type']) ? $item['type'] : 'manual';
                $dl   = wp_nonce_url(admin_url('admin-post.php?action=risecreatives_backup_download&backup=' . rawurlencode($item['base'])), 'risecreatives_backup_download');
                ?>
                <tr data-backup="<?php echo esc_attr($item['base']); ?>">
                    <td><?php echo esc_html(wp_date(RISECREATIVES_DATETIME_FORMAT, $item['created'])); ?></td>
                    <td>
                        <?php echo esc_html(implode('、', $labels)); ?>
                        <?php if (!empty($item['skipped'])): ?>
                            <br><span class="description">略過 <?php echo (int) $item['skipped']; ?> 個無法讀取的檔案</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(isset($type_labels[$type]) ? $type_labels[$type] : $type); ?></td>
                    <td><?php echo $item['size'] ? esc_html(size_format($item['size'])) : '—'; ?></td>
                    <td>
                        <?php if ($item['zip_exists']): ?>
                            <span>本機 ✓</span>
                        <?php else: ?>
                            <span class="description">本機已刪除</span>
                        <?php endif; ?>
                        <?php foreach (['github' => 'GitHub', 'gdrive' => 'Google Drive'] as $key => $label):
                            if (empty($item['remote'][$key]['status'])) { continue; }
                            $info = $item['remote'][$key];
                            if ($info['status'] === 'ok'): ?>
                                <br><?php if (!empty($info['url'])): ?><a href="<?php echo esc_url($info['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($label); ?> ✓</a><?php else: echo esc_html($label . ' ✓'); endif; ?>
                            <?php elseif ($info['status'] === 'failed'): ?>
                                <br><span style="color:#d63638;" title="<?php echo esc_attr(isset($info['error']) ? $info['error'] : ''); ?>"><?php echo esc_html($label); ?> ✗ 上傳失敗</span>
                            <?php endif;
                        endforeach; ?>
                    </td>
                    <td>
                        <?php if ($item['zip_exists']): ?>
                            <a href="<?php echo esc_url($dl); ?>" class="rc-btn rc-btn-sm rc-teal">下載</a>
                            <button type="button" class="button rc-btn rc-btn-sm rc-purple rc-restore" <?php disabled($running); ?>>還原</button>
                        <?php endif; ?>
                        <button type="button" class="button rc-btn rc-btn-sm rc-red rc-delete" <?php disabled($running); ?>>刪除</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- 設定 -->
        <h2 class="title" style="margin-top:40px;"><?php _e('備份設定', 'risecreatives-optimization'); ?>
            <span id="rc-save-status" style="margin-left:14px; font-size:14px; font-weight:400;" aria-live="polite"></span>
        </h2>
        <p class="description">所有設定變更後會自動儲存，不需要另外按儲存。</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="rc-backup-form">
            <input type="hidden" name="action" value="risecreatives_backup_save">
            <?php wp_nonce_field('risecreatives_backup_save'); ?>

            <table class="form-table risecreatives-form-table" role="presentation">
                <tr>
                    <th scope="row"><?php _e('自動備份', 'risecreatives-optimization'); ?></th>
                    <td>
                        <div class="rc-field">
                            <span class="rc-label">頻率</span>
                            <select name="backup[frequency]">
                                <?php foreach ($freq_labels as $k => $label): ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($s['frequency'], $k); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="rc-field">
                            <span class="rc-label">時間</span>
                            <select name="backup[hour]">
                                <?php for ($h = 0; $h < 24; $h++): ?>
                                    <option value="<?php echo $h; ?>" <?php selected((int) $s['hour'], $h); ?>><?php echo esc_html(sprintf('%02d:00', $h)); ?></option>
                                <?php endfor; ?>
                            </select>
                            <p class="description" style="margin-top:10px;">時間為網站時區（<?php echo esc_html(wp_timezone_string()); ?>）。建議選凌晨訪客較少的時段。</p>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('本機保留份數', 'risecreatives-optimization'); ?></th>
                    <td>
                        <div class="rc-keep">
                            <input type="number" name="backup[keep_local]" value="<?php echo (int) $s['keep_local']; ?>" min="1" max="365" class="small-text"> 份
                        </div>
                        <p class="description rc-note">超過的舊備份會自動刪除。本機一律保存；其他位置為額外的異地備份。</p>
                    </td>
                </tr>
            </table>
        </form>

        <h2 class="nav-tab-wrapper rc-tabs">
            <a href="#rc-tab-github" class="nav-tab<?php echo $active_tab === 'github' ? ' nav-tab-active' : ''; ?>" data-tab="github">備份到 GitHub</a>
            <a href="#rc-tab-gdrive" class="nav-tab<?php echo $active_tab === 'gdrive' ? ' nav-tab-active' : ''; ?>" data-tab="gdrive">備份到 Google Drive</a>
        </h2>

        <div class="rc-tab-panel<?php echo $active_tab === 'github' ? ' is-active' : ''; ?>" id="rc-tab-github">
                        <div class="rc-field">
                            <label class="rc-label" for="rc-gh-repo">儲存庫</label>
                            <input form="rc-backup-form" type="text" name="backup[github_repo]" id="rc-gh-repo" class="regular-text" placeholder="owner/backup-repo" value="<?php echo esc_attr($s['github_repo']); ?>">
                        </div>
                        <div class="rc-field">
                            <label class="rc-label" for="rc-gh-token">存取 Token</label>
                            <input form="rc-backup-form" type="password" name="backup[github_token]" id="rc-gh-token" class="regular-text" autocomplete="new-password"
                                   data-set-placeholder="已設定（留空表示不變更）"
                                   placeholder="<?php echo $s['github_token'] !== '' ? esc_attr('已設定（留空表示不變更）') : ''; ?>">
                            <label id="rc-gh-clear" style="display:<?php echo $s['github_token'] !== '' ? 'block' : 'none'; ?>; margin-top:12px;"><input form="rc-backup-form" type="checkbox" name="backup[clear_github_token]" value="1"> 清除已儲存的 Token</label>
                        </div>
                        <div class="rc-field">
                            <span class="rc-label">保留份數</span>
                            <div class="rc-keep">
                                <input form="rc-backup-form" type="number" name="backup[keep_github]" value="<?php echo (int) $s['keep_github']; ?>" min="1" max="365" class="small-text"> 份
                            </div>
                        </div>
                        <div class="rc-field rc-checks">
                            <label><input form="rc-backup-form" type="checkbox" name="backup[github_enabled]" value="1" <?php checked($s['github_enabled']); ?>> 備份完成後上傳到 GitHub</label>
                        </div>
                        <div class="rc-field rc-test-row">
                            <button type="button" class="button rc-btn rc-green rc-test" data-dest="github">測試連線</button>
                            <span class="rc-test-result description" data-dest="github"></span>
                        </div>
                        <p class="description rc-note">
                            <strong>必須使用「私有」儲存庫</strong>（公開儲存庫會被拒絕，因為備份含有資料庫）。<br>
                            建議專門建立一個只放備份的私有儲存庫，Token 使用 Fine-grained token，只授權該儲存庫的 <code>Contents：Read and write</code>。<br>
                            備份會以「草稿 Release」的附件保存，單檔上限 2 GB，不會出現在儲存庫的程式碼裡。<br>
                            Token 存放在資料庫，且不會被包含在備份檔中。
                        </p>
                    </div>

        <div class="rc-tab-panel<?php echo $active_tab === 'gdrive' ? ' is-active' : ''; ?>" id="rc-tab-gdrive">
                        <div class="rc-field">
                            <label class="rc-label" for="rc-gd-id">Client ID</label>
                            <input form="rc-backup-form" type="text" name="backup[gdrive_client_id]" id="rc-gd-id" class="regular-text" value="<?php echo esc_attr($s['gdrive_client_id']); ?>">
                        </div>
                        <div class="rc-field">
                            <label class="rc-label" for="rc-gd-secret">Client Secret</label>
                            <input form="rc-backup-form" type="password" name="backup[gdrive_client_secret]" id="rc-gd-secret" class="regular-text" autocomplete="new-password"
                                   data-set-placeholder="已設定（留空表示不變更）"
                                   placeholder="<?php echo $s['gdrive_client_secret'] !== '' ? esc_attr('已設定（留空表示不變更）') : ''; ?>">
                        </div>
                        <div class="rc-field">
                            <label class="rc-label" for="rc-gd-target">備份資料夾（選填）</label>
                            <input form="rc-backup-form" type="text" name="backup[gdrive_target]" id="rc-gd-target" class="large-text" style="max-width:640px; height:42px;"
                                   placeholder="https://drive.google.com/drive/folders/…" value="<?php echo esc_attr($gd_target_url); ?>">
                            <p class="description" style="margin-top:10px;">貼上 Google Drive 資料夾的網址，備份就會上傳到那個資料夾。留空則自動建立「RiseCreatives Backups - 網域」資料夾。<br>授權的 Google 帳號需要對這個資料夾有編輯權限。</p>
                            <p id="rc-gd-reauth" style="margin:10px 0 0; color:#b45309; font-weight:600; <?php echo $gd_need_reauth ? '' : 'display:none;'; ?>">指定資料夾需要較完整的 Google Drive 權限，請按下方「重新授權」，並在 Google 畫面勾選允許存取。</p>
                        </div>
                        <div class="rc-field">
                            <span class="rc-label">保留份數</span>
                            <div class="rc-keep">
                                <input form="rc-backup-form" type="number" name="backup[keep_gdrive]" value="<?php echo (int) $s['keep_gdrive']; ?>" min="1" max="365" class="small-text"> 份
                            </div>
                        </div>
                        <div class="rc-field rc-checks">
                            <label><input form="rc-backup-form" type="checkbox" name="backup[gdrive_enabled]" value="1" <?php checked($s['gdrive_enabled']); ?>> 備份完成後上傳到 Google Drive</label>
                        </div>
                        <div class="rc-field">
                            <span class="rc-label">授權狀態</span>
                            <strong id="rc-gd-state" style="color:<?php echo $authorized ? '#00a32a' : '#d63638'; ?>;"><?php echo $authorized ? '已授權' : '尚未授權'; ?></strong>
                            <div class="rc-test-row" id="rc-gd-test-row" style="margin-top:14px; <?php echo $authorized ? '' : 'display:none;'; ?>">
                                <button type="button" class="button rc-btn rc-green rc-test" data-dest="gdrive">測試連線</button>
                                <span class="rc-test-result description" data-dest="gdrive"></span>
                            </div>
                        </div>

                        <div class="description rc-steps">
                            <div class="rc-step"><strong>設定步驟</strong></div>
                            <div class="rc-step">① 到 Google Cloud Console 建立專案，並啟用 Google Drive API。</div>
                            <div class="rc-step">
                                ② 建立「OAuth 用戶端 ID」，類型選「網頁應用程式」，並在「已授權的重新導向 URI」填入：<br>
                                <code class="rc-uri" style="user-select:all;"><?php echo esc_html($backup->get_gdrive_redirect_uri()); ?></code>
                            </div>
                            <div class="rc-step">③ 把 Client ID 與 Client Secret 填入上方（填完會自動儲存）。</div>
                            <div class="rc-step">④ 想備份到自己的資料夾：在 Google Drive 打開那個資料夾，複製瀏覽器網址列的網址，貼到上方「備份資料夾」。不指定就留空，外掛會自動建立「RiseCreatives Backups - 網域」資料夾。</div>
                            <div class="rc-step">⑤ 按下方「授權」，選擇要存放備份的 Google 帳號。有指定資料夾時，這個帳號必須對該資料夾有編輯權限，並且要在 Google 畫面上勾選允許存取 Google Drive。</div>
                            <div class="rc-step">⑥ 按「測試連線」，看到「連線成功」（有指定資料夾時會一併顯示資料夾名稱）後，勾選「備份完成後上傳到 Google Drive」。</div>
                            <div class="rc-step">
                                <strong>已經授權過、之後才指定或更換資料夾：</strong>從「自動建立的資料夾」改成「指定資料夾」時，需要再按一次「重新授權」，因為需要的權限比較大；頁面上出現橘色提醒時就代表需要重新授權。在不同的指定資料夾之間更換則不用。
                            </div>
                            <div class="rc-step">
                                <strong>關於權限：</strong>沒有指定資料夾時，外掛只要求 <code>drive.file</code> 權限，只能存取它自己建立的檔案與資料夾；指定資料夾時，需要完整的 Google Drive 存取權（<code>drive</code>），才能把備份放進你自己建立的資料夾。
                            </div>
                            <div class="rc-step">
                                <strong>如果看到「這個應用程式未經驗證」：</strong>這是因為 Client ID 是你自己建立的，按「進階」→「前往（不安全）」即可繼續。
                            </div>
                            <div class="rc-step">
                                <strong>重要：</strong>OAuth 同意畫面的發布狀態請改為「正式版（In production）」，維持「測試中」的話，授權會在 7 天後失效。
                            </div>
                        </div>
                    
<div class="rc-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="rc-gd-auth-form">
                <input type="hidden" name="action" value="risecreatives_backup_gdrive_auth">
                <?php wp_nonce_field('risecreatives_backup_gdrive_auth'); ?>
                <input type="submit" id="rc-gd-auth-btn" class="button rc-btn rc-orange" value="<?php echo esc_attr($authorized ? '重新授權' : '授權'); ?>" <?php disabled(!($s['gdrive_client_id'] !== '' && $s['gdrive_client_secret'] !== '')); ?>>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="rc-gd-disconnect-form" style="<?php echo $authorized ? '' : 'display:none;'; ?>">
                <input type="hidden" name="action" value="risecreatives_backup_gdrive_disconnect">
                <?php wp_nonce_field('risecreatives_backup_gdrive_disconnect'); ?>
                <input type="submit" class="button rc-btn rc-btn-lg rc-red" value="解除授權">
            </form>
        </div>
    </div>
    </div>
</div>

<script>
(function ($) {
    var ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var nonce   = <?php echo wp_json_encode($ajax_nonce); ?>;

    /**
     * 對話框：取代 alert／confirm。回傳 Promise，按確定為 true，取消／關閉為 false。
     * opts: { title, message, items: [], note, check, tone: info|success|warn|danger, ok, cancel(false 表示只有一個按鈕) }
     */
    function rcDialog(opts) {
        opts = opts || {};
        var tone  = opts.tone || 'info';
        var icons = { info: 'i', success: '✓', warn: '!', danger: '!' };
        var esc   = function (t) { return $('<div>').text(t == null ? '' : String(t)).html(); };

        var body = '';
        if (opts.message) {
            $.each(String(opts.message).split(/\n\n+/), function (i, para) {
                body += '<p>' + esc(para).replace(/\n/g, '<br>') + '</p>';
            });
        }
        if (opts.items && opts.items.length) {
            body += '<ul>' + $.map(opts.items, function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ul>';
        }
        if (opts.note) { body += '<div class="rc-modal-note">' + esc(opts.note) + '</div>'; }
        if (opts.check) { body += '<label class="rc-modal-check"><input type="checkbox"><span>' + esc(opts.check) + '</span></label>'; }

        var hasCancel = opts.cancel !== false;
        var $ov = $(
            '<div class="rc-modal-overlay" role="presentation">' +
              '<div class="rc-modal rc-tone-' + tone + '" role="dialog" aria-modal="true" aria-labelledby="rc-modal-title">' +
                '<div class="rc-modal-head"><span class="rc-modal-icon" aria-hidden="true">' + icons[tone] + '</span>' +
                '<h2 class="rc-modal-title" id="rc-modal-title">' + esc(opts.title || '提示') + '</h2></div>' +
                '<div class="rc-modal-body">' + body + '</div>' +
                '<div class="rc-modal-foot">' +
                  (hasCancel ? '<button type="button" class="rc-mbtn rc-mbtn-cancel">' + esc(opts.cancel || '取消') + '</button>' : '') +
                  '<button type="button" class="rc-mbtn rc-mbtn-ok">' + esc(opts.ok || '確定') + '</button>' +
                '</div>' +
              '</div>' +
            '</div>'
        );

        return new Promise(function (resolve) {
            var lastFocus = document.activeElement;
            var $ok = $ov.find('.rc-mbtn-ok');
            var $chk = $ov.find('.rc-modal-check input');
            if ($chk.length) {
                $ok.prop('disabled', true);
                $chk.on('change', function () { $ok.prop('disabled', !this.checked); });
            }
            function close(result) {
                $(document).off('keydown.rcmodal');
                $ov.removeClass('is-open');
                setTimeout(function () { $ov.remove(); }, 180);
                if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
                resolve(result);
            }
            $ok.on('click', function () { close(true); });
            $ov.find('.rc-mbtn-cancel').on('click', function () { close(false); });
            $ov.on('mousedown', function (e) { if (e.target === this) { close(!hasCancel); } });
            $(document).on('keydown.rcmodal', function (e) {
                if (e.key === 'Escape') { e.preventDefault(); close(!hasCancel); }
                if (e.key === 'Tab') {
                    var $f = $ov.find('button:not(:disabled), input');
                    var first = $f.get(0), last = $f.get($f.length - 1);
                    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
                }
            });
            $('body').append($ov);
            requestAnimationFrame(function () { $ov.addClass('is-open'); });
            (($chk.length ? $chk : (hasCancel && tone === 'danger' ? $ov.find('.rc-mbtn-cancel') : $ok)).get(0)).focus();
        });
    }
    function rcAlert(message, tone, title) {
        return rcDialog({ title: title || (tone === 'danger' ? '發生錯誤' : '提示'), message: message, tone: tone || 'info', cancel: false });
    }
    // 頁籤切換（記住上次的頁籤）
    function rcShowTab(tab) {
        $('.rc-tabs .nav-tab').removeClass('nav-tab-active').filter('[data-tab="' + tab + '"]').addClass('nav-tab-active');
        $('.rc-tab-panel').removeClass('is-active').filter('#rc-tab-' + tab).addClass('is-active');
        try { localStorage.setItem('rcBackupTab', tab); } catch (e) {}
    }
    $('.rc-tabs .nav-tab').on('click', function (e) {
        e.preventDefault();
        rcShowTab($(this).data('tab'));
    });
    (function () {
        var fromServer = <?php echo wp_json_encode($notice_key !== ''); ?>;
        if (fromServer) { return; }
        try {
            var t = localStorage.getItem('rcBackupTab');
            if (t === 'github' || t === 'gdrive') { rcShowTab(t); }
        } catch (e) {}
    })();

    var timer   = null;
    var tracking = false;

    function setBusy(busy) {
        $('#rc-backup-start, .rc-restore, .rc-delete').prop('disabled', busy);
    }

    var ctrlBusy = false;

    function render(st) {
        var running = st && st.state === 'running';
        if (running) {
            var paused    = !!st.paused;
            var control   = st.control || '';
            var canCtrl   = st.kind === 'backup';
            var msg       = st.message || '';
            if (control === 'cancel') {
                msg = '正在取消…（目前的步驟告一段落後就會停止並清除暫存檔）';
            } else if (control === 'pause' && !paused) {
                msg = '正在暫停…（' + msg + '）';
            } else if (!control && paused) {
                msg = '正在繼續…';
            }
            $('#rc-backup-progress').show();
            $('#rc-backup-bar').css({ width: (st.percent || 0) + '%', background: paused ? '#8c8f94' : '#2271b1' });
            $('#rc-backup-message').text(msg);
            $('#rc-backup-progress .rc-spinner').toggle(!(paused && control === 'pause'));

            $('#rc-backup-controls').toggle(canCtrl);
            var pausing = control === 'pause';
            $('#rc-backup-pause').toggle(!pausing);
            $('#rc-backup-resume').toggle(pausing);
            $('#rc-backup-controls .rc-ctrl').prop('disabled', ctrlBusy || control === 'cancel');
        } else {
            $('#rc-backup-controls').hide();
        }
        return running;
    }

    $('#rc-backup-controls').on('click', '.rc-ctrl', function () {
        var cmd = $(this).data('cmd');
        if (cmd === 'cancel') {
            rcDialog({
                title: '要取消這次備份嗎？',
                message: '已經處理的部分會被捨棄，暫存檔也會一併清除。已有的舊備份不受影響。',
                tone: 'danger', ok: '取消備份', cancel: '繼續備份'
            }).then(function (yes) { if (yes) { sendControl(cmd); } });
            return;
        }
        sendControl(cmd);
    });

    function sendControl(cmd) {
        ctrlBusy = true;
        $('#rc-backup-controls .rc-ctrl').prop('disabled', true);
        $.post(ajaxurl, { action: 'risecreatives_backup_control', nonce: nonce, cmd: cmd }).done(function (res) {
            ctrlBusy = false;
            if (res && res.success) {
                render(res.data);
            } else {
                rcAlert(res && res.data && res.data.message ? res.data.message : '操作失敗', 'danger');
                $('#rc-backup-controls .rc-ctrl').prop('disabled', false);
            }
        }).fail(function (xhr) {
            ctrlBusy = false;
            rcAlert('操作失敗（HTTP ' + (xhr.status || '?') + '）', 'danger');
            $('#rc-backup-controls .rc-ctrl').prop('disabled', false);
        });
    }

    function poll() {
        $.post(ajaxurl, { action: 'risecreatives_backup_status', nonce: nonce }).done(function (res) {
            var st = res && res.success ? res.data : null;
            if (!st) { timer = setTimeout(poll, 4000); return; }

            if (render(st)) {
                setBusy(true);
                timer = setTimeout(poll, st.paused ? 4000 : 2500);
                return;
            }

            // 結束
            $('#rc-backup-progress').hide();
            if (tracking) {
                var isRestore = st.kind === 'restore';
                var what = isRestore ? '還原' : '備份';
                var map = {
                    done:      { tone: 'success', title: what + '完成' },
                    cancelled: { tone: 'info',    title: what + '已取消' },
                    failed:    { tone: 'danger',  title: what + '失敗' }
                };
                var m = map[st.state] || { tone: 'info', title: '作業已結束' };
                if (st.state === 'done' && st.error) { m = { tone: 'warn', title: what + '完成，但有部分問題' }; }
                rcDialog({ title: m.title, message: st.message || '作業已結束', tone: m.tone, cancel: false, ok: '好' })
                    .then(function () { window.location.reload(); });
            } else {
                setBusy(false);
            }
        }).fail(function () {
            timer = setTimeout(poll, 5000);
        });
    }

    function startTracking() {
        tracking = true;
        setBusy(true);
        $('#rc-backup-progress').show();
        $('#rc-backup-message').text('作業進行中…');
        $('#rc-backup-controls').hide();
        clearTimeout(timer);
        timer = setTimeout(poll, 1500);
    }

    $('#rc-backup-start').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        // 伺服器會先回應再在背景執行；即使連線被中斷，作業仍會繼續
        $.post(ajaxurl, { action: 'risecreatives_backup_start', nonce: nonce }).fail(function (xhr) {
            if (xhr.status && xhr.status !== 200) {
                rcAlert('無法開始備份（HTTP ' + xhr.status + '）', 'danger');
                $btn.prop('disabled', false);
            }
        }).done(function (res) {
            if (res && res.success === false) {
                rcAlert(res.data && res.data.message ? res.data.message : '無法開始備份', 'danger');
                $btn.prop('disabled', false);
                tracking = false;
                clearTimeout(timer);
            }
        });
        startTracking();
    });

    $('#rc-backup-list').on('click', '.rc-restore', function () {
        var base = $(this).closest('tr').data('backup');
        rcDialog({
            title: '確定要還原這份備份嗎？',
            tone: 'warn',
            items: [
                '目前網站的資料庫與同路徑的檔案，會被備份內容覆蓋（只還原備份有勾選的項目）。',
                '還原前會先自動備份目前的資料庫，作為安全備份。',
                '若備份含 WordPress 核心，會一併覆蓋 wp-admin、wp-includes 與根目錄檔案（不含 wp-config.php）。',
                '還原後可能需要重新登入。',
                '只能還原網址與資料表前綴相同的網站。'
            ],
            note: '建議先下載一份備份再繼續。還原開始後無法暫停或取消。',
            check: '我了解目前網站的內容會被覆蓋',
            ok: '開始還原', cancel: '先不要'
        }).then(function (yes) { if (yes) { startRestore(base); } });
    });

    function startRestore(base) {
        $.post(ajaxurl, { action: 'risecreatives_backup_restore', nonce: nonce, backup: base }).done(function (res) {
            if (res && res.success === false) {
                rcAlert(res.data && res.data.message ? res.data.message : '無法開始還原', 'danger');
                tracking = false;
                setBusy(false);
                clearTimeout(timer);
            }
        });
        startTracking();
    }

    $('#rc-backup-list').on('click', '.rc-delete', function () {
        var $row = $(this).closest('tr');
        rcDialog({
            title: '確定要刪除這份備份嗎？',
            message: '若已上傳到 GitHub／Google Drive，遠端的副本也會一併刪除。刪除後無法復原。',
            tone: 'danger', ok: '刪除', cancel: '保留'
        }).then(function (yes) { if (yes) { deleteBackup($row); } });
    });

    function deleteBackup($row) {
        $.post(ajaxurl, { action: 'risecreatives_backup_delete', nonce: nonce, backup: $row.data('backup') }).done(function (res) {
            var data = res && res.data ? res.data : {};
            if (data.message && data.message !== '已刪除。') {
                rcAlert(data.message, 'warn').then(function () { window.location.reload(); });
                return;
            }
            window.location.reload();
        }).fail(function (xhr) {
            var d = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
            rcAlert(d.message || '刪除失敗', 'danger');
        });
    }

    $('.rc-test').on('click', function () {
        var dest = $(this).data('dest');
        var $out = $('.rc-test-result[data-dest="' + dest + '"]').css('color', '').text('測試中…');
        var data = { action: 'risecreatives_backup_test', nonce: nonce, dest: dest };
        if (dest === 'github') {
            data.github_repo  = $('#rc-gh-repo').val();
            data.github_token = $('#rc-gh-token').val();
        }
        $.post(ajaxurl, data).done(function (res) {
            $out.css('color', '#00a32a').text(res.data.message);
        }).fail(function (xhr) {
            var d = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
            $out.css('color', '#d63638').text(d.message || '測試失敗');
        });
    });

    /* ---------- 自動儲存 ---------- */
    var $form      = $('#rc-backup-form');
    var $status    = $('#rc-save-status');
    var saving     = false;
    var dirty      = false;
    var saveTimer  = null;
    var hideTimer  = null;
    var idleQueue  = [];

    function flashStatus(text, color, keep) {
        clearTimeout(hideTimer);
        $status.css('color', color || '').text(text);
        if (!keep) { hideTimer = setTimeout(function () { $status.text(''); }, 4000); }
    }

    function applyState(st) {
        if (!st) { return; }
        $.each(st.checks || {}, function (name, val) {
            $('input[name="backup[' + name + ']"]').prop('checked', !!val);
        });
        $('#rc-gh-token').attr('placeholder', st.has_github_token ? $('#rc-gh-token').data('set-placeholder') : '').val('');
        $('#rc-gd-secret').attr('placeholder', st.has_gd_secret ? $('#rc-gd-secret').data('set-placeholder') : '').val('');
        $('#rc-gh-clear').toggle(!!st.has_github_token).find('input').prop('checked', false);
        $('#rc-next-run').text(st.next_run || '');
        if (!$('#rc-gd-target').is(':focus')) {
            $('#rc-gd-target').val(st.gd_target ? 'https://drive.google.com/drive/folders/' + st.gd_target : '');
        }
        $('#rc-gd-reauth').toggle(!!st.gd_need_reauth);

        $('#rc-gd-state').css('color', st.authorized ? '#00a32a' : '#d63638').text(st.authorized ? '已授權' : '尚未授權');
        $('#rc-gd-test-row').toggle(!!st.authorized);
        $('#rc-gd-disconnect-form').toggle(!!st.authorized);
        $('#rc-gd-auth-btn').val(st.authorized ? '重新授權' : '授權').prop('disabled', !st.has_gd_client);
    }

    function runIdle() {
        var q = idleQueue; idleQueue = [];
        $.each(q, function (i, fn) { fn(); });
    }

    function doSave() {
        saveTimer = null;
        if (saving) { dirty = true; return; }
        saving = true; dirty = false;
        flashStatus('', '', true);

        var data = $form.serializeArray();
        data.push({ name: 'action', value: 'risecreatives_backup_autosave' });
        data.push({ name: 'nonce', value: nonce });

        $.post(ajaxurl, data).done(function (res) {
            var r = res && res.data ? res.data : null;
            if (!r) { flashStatus('儲存失敗，請重新整理頁面再試', '#d63638', true); return; }
            applyState(r.state);
            if (r.level === 'ok') {
                flashStatus('', '', true); // 成功時不顯示文字，只在有問題時提示
            } else if (r.level === 'warn') {
                flashStatus(r.message, '#b45309', true);
            } else {
                flashStatus(r.message, '#d63638', true);
            }
        }).fail(function (xhr) {
            var d = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
            flashStatus(d.message || '儲存失敗（HTTP ' + (xhr.status || '?') + '）', '#d63638', true);
        }).always(function () {
            saving = false;
            if (dirty) { doSave(); } else { runIdle(); }
        });
    }

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(doSave, 350);
    }

    // 勾選、下拉、數字、文字欄位（離開欄位或按 Enter 時）變更就自動儲存
    $form.find('input, select').add('[form="rc-backup-form"]').on('change', scheduleSave);
    $form.on('submit', function (e) { e.preventDefault(); scheduleSave(); });

    // 按「授權／解除授權」前，等尚未完成的儲存結束，避免用到舊的 Client ID
    $('#rc-gd-auth-form, #rc-gd-disconnect-form').on('submit', function (e) {
        if (saving || saveTimer) {
            e.preventDefault();
            var form = this;
            clearTimeout(saveTimer); saveTimer = null;
            idleQueue.push(function () { form.submit(); });
            doSave();
        }
    });

    <?php if ($running): ?>
    tracking = true;
    setBusy(true);
    render(<?php echo wp_json_encode(array_merge($status, array('control' => $backup->read_control()))); ?>);
    poll();
    <?php endif; ?>
})(jQuery);
</script>
