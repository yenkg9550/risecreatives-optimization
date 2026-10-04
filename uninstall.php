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
