<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
// Disconnect in settings before uninstalling to revoke the Google OAuth grant.
function aplr_remove_site_data() {
    wp_clear_scheduled_hook('aplr_sync');
    foreach (array('aplr_settings','aplr_vault','aplr_status','aplr_sync_lock','aplr_epoch','aplr_general_reviews','aplr_service_phrases') as $key) { delete_option($key); }
    delete_transient('aplr_public_cache');
    delete_post_meta_by_key('_aplr_review_rule');
    global $wpdb;
    $patterns = array($wpdb->esc_like('_transient_aplr_') . '%', $wpdb->esc_like('_transient_timeout_aplr_') . '%');
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $patterns));
}
if (is_multisite()) {
    $offset = 0;
    do {
        $ids = get_sites(array('fields'=>'ids','number'=>100,'offset'=>$offset));
        foreach ($ids as $id) { switch_to_blog($id); aplr_remove_site_data(); restore_current_blog(); }
        $offset += 100;
    } while (count($ids) === 100);
} else { aplr_remove_site_data(); }
