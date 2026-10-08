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
    'gdrive_not_authorized' => ['error',   '要啟用 Google Drive，請先完成「授權 Google Drive」。其他設定已儲存，但 Google Drive 尚未啟用。'],
    'gdrive_no_client'      => ['error',   '請先填寫並儲存 Google 的 Client ID 與 Client Secret，再按「授權」。'],
    'gdrive_denied'         => ['error',   'Google 授權已取消或被拒絕。' . ($notice_msg !== '' ? '（' . $notice_msg . '）' : '')],
    'gdrive_error'          => ['error',   'Google 授權失敗：' . $notice_msg],
    'gdrive_ok'             => ['success', 'Google Drive 授權成功，請勾選「備份到 Google Drive」並儲存。'],
    'gdrive_disconnected'   => ['success', '已解除 Google Drive 授權。'],
];

$content_labels = ['db' => '資料庫', 'uploads' => '上傳檔案', 'plugins' => '外掛', 'themes' => '主題', 'core' => 'WordPress 核心'];
$type_labels    = ['auto' => '排程', 'manual' => '手動', 'pre_restore' => '還原前安全備份'];
$freq_labels    = ['off' => '關閉自動備份', 'daily' => '每天', 'weekly' => '每週', 'monthly' => '每月'];

$ajax_nonce = wp_create_nonce(RiseCreatives_Optimization_Backup::NONCE);
$authorized = $s['gdrive_refresh_token'] !== '';
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
.rc-backup-page .rc-purple { --rc: #6d28d9; }
.rc-backup-page .rc-red    { --rc: #d63638; }
.rc-backup-page .rc-dark   { --rc: #1d2327; }

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
.rc-backup-page .rc-step code { display: inline-block; margin-top: 10px; padding: 8px 12px; }
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
                        <p id="rc-backup-message" class="description" style="margin-top:10px;"></p>
                    </div>
                    <p id="rc-backup-last" class="description" style="margin-top:12px;">
                        <?php
                        if (!$running && !empty($status['message'])) {
                            $when = !empty($status['finished']) ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $status['finished']) : '';
                            echo esc_html('最近一次：' . $status['message'] . ($when ? '（' . $when . '）' : ''));
                        }
                        ?>
                    </p>
                    <p class="description" style="margin-top:12px;">備份在背景執行，可以離開此頁；回來後會顯示最新進度。</p>
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
                    <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $item['created'])); ?></td>
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

                <!-- GitHub -->
                <tr>
                    <th scope="row"><?php _e('備份到 GitHub', 'risecreatives-optimization'); ?></th>
                    <td>
                        <div class="rc-field">
                            <label class="rc-label" for="rc-gh-repo">儲存庫</label>
                            <input type="text" name="backup[github_repo]" id="rc-gh-repo" class="regular-text" placeholder="owner/backup-repo" value="<?php echo esc_attr($s['github_repo']); ?>">
                        </div>
                        <div class="rc-field">
                            <label class="rc-label" for="rc-gh-token">存取 Token</label>
                            <input type="password" name="backup[github_token]" id="rc-gh-token" class="regular-text" autocomplete="new-password"
                                   data-set-placeholder="已設定（留空表示不變更）"
                                   placeholder="<?php echo $s['github_token'] !== '' ? esc_attr('已設定（留空表示不變更）') : ''; ?>">
                            <label id="rc-gh-clear" style="display:<?php echo $s['github_token'] !== '' ? 'block' : 'none'; ?>; margin-top:12px;"><input type="checkbox" name="backup[clear_github_token]" value="1"> 清除已儲存的 Token</label>
                        </div>
                        <div class="rc-field">
                            <span class="rc-label">保留份數</span>
                            <div class="rc-keep">
                                <input type="number" name="backup[keep_github]" value="<?php echo (int) $s['keep_github']; ?>" min="1" max="365" class="small-text"> 份
                            </div>
                        </div>
                        <div class="rc-field rc-checks">
                            <label><input type="checkbox" name="backup[github_enabled]" value="1" <?php checked($s['github_enabled']); ?>> 備份完成後上傳到 GitHub</label>
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
                    </td>
                </tr>

                <!-- Google Drive -->
                <tr>
                    <th scope="row"><?php _e('備份到 Google Drive', 'risecreatives-optimization'); ?></th>
                    <td>
                        <div class="rc-field">
                            <label class="rc-label" for="rc-gd-id">Client ID</label>
                            <input type="text" name="backup[gdrive_client_id]" id="rc-gd-id" class="regular-text" value="<?php echo esc_attr($s['gdrive_client_id']); ?>">
                        </div>
                        <div class="rc-field">
                            <label class="rc-label" for="rc-gd-secret">Client Secret</label>
                            <input type="password" name="backup[gdrive_client_secret]" id="rc-gd-secret" class="regular-text" autocomplete="new-password"
                                   data-set-placeholder="已設定（留空表示不變更）"
                                   placeholder="<?php echo $s['gdrive_client_secret'] !== '' ? esc_attr('已設定（留空表示不變更）') : ''; ?>">
                        </div>
                        <div class="rc-field">
                            <span class="rc-label">保留份數</span>
                            <div class="rc-keep">
                                <input type="number" name="backup[keep_gdrive]" value="<?php echo (int) $s['keep_gdrive']; ?>" min="1" max="365" class="small-text"> 份
                            </div>
                        </div>
                        <div class="rc-field rc-checks">
                            <label><input type="checkbox" name="backup[gdrive_enabled]" value="1" <?php checked($s['gdrive_enabled']); ?>> 備份完成後上傳到 Google Drive</label>
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
                                <code style="user-select:all;"><?php echo esc_html($backup->get_gdrive_redirect_uri()); ?></code>
                            </div>
                            <div class="rc-step">③ 把 Client ID 與 Client Secret 填入上方（填完會自動儲存）。</div>
                            <div class="rc-step">④ 再按下方「授權 Google Drive」。</div>
                            <div class="rc-step">
                                <strong>重要：</strong>OAuth 同意畫面的發布狀態請改為「正式版（In production）」，維持「測試中」的話，授權會在 7 天後失效。
                            </div>
                            <div class="rc-step">
                                外掛只要求 <code>drive.file</code> 範圍，只能存取它自己建立的檔案與資料夾（資料夾名稱：RiseCreatives Backups - 網域）。
                            </div>
                        </div>
                    </td>
                </tr>
            </table>

        </form>

        <div class="rc-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="rc-gd-auth-form">
                <input type="hidden" name="action" value="risecreatives_backup_gdrive_auth">
                <?php wp_nonce_field('risecreatives_backup_gdrive_auth'); ?>
                <input type="submit" id="rc-gd-auth-btn" class="button rc-btn rc-btn-lg rc-orange" value="<?php echo esc_attr($authorized ? '重新授權 Google Drive' : '授權 Google Drive'); ?>" <?php disabled(!($s['gdrive_client_id'] !== '' && $s['gdrive_client_secret'] !== '')); ?>>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="rc-gd-disconnect-form" style="<?php echo $authorized ? '' : 'display:none;'; ?>">
                <input type="hidden" name="action" value="risecreatives_backup_gdrive_disconnect">
                <?php wp_nonce_field('risecreatives_backup_gdrive_disconnect'); ?>
                <input type="submit" class="button rc-btn rc-btn-lg rc-red" value="解除授權">
            </form>
        </div>
    </div>
</div>

<script>
(function ($) {
    var ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var nonce   = <?php echo wp_json_encode($ajax_nonce); ?>;
    var timer   = null;
    var tracking = false;

    function setBusy(busy) {
        $('#rc-backup-start, .rc-restore, .rc-delete').prop('disabled', busy);
    }

    function render(st) {
        var running = st && st.state === 'running';
        if (running) {
            $('#rc-backup-progress').show();
            $('#rc-backup-bar').css('width', (st.percent || 0) + '%');
            $('#rc-backup-message').text(st.message || '');
        }
        return running;
    }

    function poll() {
        $.post(ajaxurl, { action: 'risecreatives_backup_status', nonce: nonce }).done(function (res) {
            var st = res && res.success ? res.data : null;
            if (!st) { timer = setTimeout(poll, 4000); return; }

            if (render(st)) {
                setBusy(true);
                timer = setTimeout(poll, 2500);
                return;
            }

            // 結束
            $('#rc-backup-progress').hide();
            if (tracking) {
                alert(st.message || '作業已結束');
                window.location.reload();
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
        clearTimeout(timer);
        timer = setTimeout(poll, 1500);
    }

    $('#rc-backup-start').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        // 伺服器會先回應再在背景執行；即使連線被中斷，作業仍會繼續
        $.post(ajaxurl, { action: 'risecreatives_backup_start', nonce: nonce }).fail(function (xhr) {
            if (xhr.status && xhr.status !== 200) {
                alert('無法開始備份（HTTP ' + xhr.status + '）');
                $btn.prop('disabled', false);
            }
        }).done(function (res) {
            if (res && res.success === false) {
                alert(res.data && res.data.message ? res.data.message : '無法開始備份');
                $btn.prop('disabled', false);
                tracking = false;
                clearTimeout(timer);
            }
        });
        startTracking();
    });

    $('#rc-backup-list').on('click', '.rc-restore', function () {
        var base = $(this).closest('tr').data('backup');
        var msg = '確定要還原這份備份嗎？\n\n' +
            '・目前網站的資料庫與同路徑的檔案會被備份內容覆蓋（只還原備份有勾選的項目）。\n' +
            '・還原前會先自動備份目前的資料庫，作為安全備份。\n' +
            '・若備份含 WordPress 核心，會一併覆蓋 wp-admin、wp-includes 與根目錄檔案（不含 wp-config.php）。\n' +
            '・還原後可能需要重新登入。\n' +
            '・只能還原網址與資料表前綴相同的網站。\n\n建議先下載一份備份再繼續。';
        if (!window.confirm(msg)) { return; }
        if (!window.confirm('最後確認：真的要開始還原嗎？')) { return; }

        $.post(ajaxurl, { action: 'risecreatives_backup_restore', nonce: nonce, backup: base }).done(function (res) {
            if (res && res.success === false) {
                alert(res.data && res.data.message ? res.data.message : '無法開始還原');
                tracking = false;
                setBusy(false);
                clearTimeout(timer);
            }
        });
        startTracking();
    });

    $('#rc-backup-list').on('click', '.rc-delete', function () {
        var $row = $(this).closest('tr');
        if (!window.confirm('確定要刪除這份備份嗎？若已上傳到 GitHub／Google Drive，遠端的副本也會一併刪除，無法復原。')) { return; }
        $.post(ajaxurl, { action: 'risecreatives_backup_delete', nonce: nonce, backup: $row.data('backup') }).done(function (res) {
            var data = res && res.data ? res.data : {};
            if (data.message && data.message !== '已刪除。') { alert(data.message); }
            window.location.reload();
        }).fail(function (xhr) {
            var d = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
            alert(d.message || '刪除失敗');
        });
    });

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

        $('#rc-gd-state').css('color', st.authorized ? '#00a32a' : '#d63638').text(st.authorized ? '已授權' : '尚未授權');
        $('#rc-gd-test-row').toggle(!!st.authorized);
        $('#rc-gd-disconnect-form').toggle(!!st.authorized);
        $('#rc-gd-auth-btn').val(st.authorized ? '重新授權 Google Drive' : '授權 Google Drive').prop('disabled', !st.has_gd_client);
    }

    function runIdle() {
        var q = idleQueue; idleQueue = [];
        $.each(q, function (i, fn) { fn(); });
    }

    function doSave() {
        saveTimer = null;
        if (saving) { dirty = true; return; }
        saving = true; dirty = false;
        flashStatus('儲存中…', '#646970', true);

        var data = $form.serializeArray();
        data.push({ name: 'action', value: 'risecreatives_backup_autosave' });
        data.push({ name: 'nonce', value: nonce });

        $.post(ajaxurl, data).done(function (res) {
            var r = res && res.data ? res.data : null;
            if (!r) { flashStatus('儲存失敗，請重新整理頁面再試', '#d63638', true); return; }
            applyState(r.state);
            if (r.level === 'ok') {
                flashStatus('✓ ' + r.message, '#00a32a');
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
    render(<?php echo wp_json_encode($status); ?>);
    poll();
    <?php endif; ?>
})(jQuery);
</script>
