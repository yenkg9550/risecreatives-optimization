<?php
// 如果沒有通過 WordPress 呼叫，則退出
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 清理所有外掛選項
delete_option('risecreatives_opt_general');
delete_option('risecreatives_opt_scripts');
delete_option('risecreatives_opt_login');
delete_option('risecreatives_upload_restrictions');
delete_option('risecreatives_opt_performance_hash');
delete_option('risecreatives_image_sizes_restored');
delete_option('risecreatives_opt_editor');
delete_option('risecreatives_opt_performance');
delete_option('risecreatives_opt_updater');

// 備份設定與排程（已建立的備份檔保留在 wp-content/risecreatives-backups-*，不會自動刪除）
delete_option('risecreatives_opt_backup');
delete_option('risecreatives_opt_backup_dir');
wp_clear_scheduled_hook('risecreatives_opt_backup_cron');
delete_transient('risecreatives_opt_update_info');
delete_option('risecreatives_htaccess_sync_failed');

// 移除 .htaccess 中的安全標頭規則
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
$risecreatives_htaccess = get_home_path() . '.htaccess';
if (file_exists($risecreatives_htaccess) && is_writable($risecreatives_htaccess)) {
    insert_with_markers($risecreatives_htaccess, 'RiseCreatives Security Headers', []);
}
