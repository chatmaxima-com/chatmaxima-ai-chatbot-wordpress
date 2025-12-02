<?php
/**
 * Uninstall ChatMaxima AI Chatbot Plugin
 *
 * This file is called when the plugin is uninstalled via the WordPress admin.
 * It cleans up all plugin data from the database.
 *
 * @package ChatMaxima AI Chatbot
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN'))
{
    exit;
}

// Delete plugin options
delete_option('chatmaxima_token_id');
delete_option('chatmaxima_theme_color');
delete_option('chatmaxima_social_media');

// Delete API-related options
delete_option('chatmaxima_access_token');
delete_option('chatmaxima_refresh_token');
delete_option('chatmaxima_token_expiry');
delete_option('chatmaxima_user_info');

// Delete knowledge source options
delete_option('chatmaxima_knowledge_source_alias');
delete_option('chatmaxima_auto_sync');
delete_option('chatmaxima_sync_post_types');
delete_option('chatmaxima_selected_team');

// Delete transients
delete_transient('chatmaxima_knowledge_sources');

// Delete post meta
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_chatmaxima_%'");

// For multisite installations, delete options from all sites
if (is_multisite())
{
    $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");

    foreach ($blog_ids as $blog_id)
    {
        switch_to_blog($blog_id);

        // Delete all options
        delete_option('chatmaxima_token_id');
        delete_option('chatmaxima_theme_color');
        delete_option('chatmaxima_social_media');
        delete_option('chatmaxima_access_token');
        delete_option('chatmaxima_refresh_token');
        delete_option('chatmaxima_token_expiry');
        delete_option('chatmaxima_user_info');
        delete_option('chatmaxima_knowledge_source_alias');
        delete_option('chatmaxima_auto_sync');
        delete_option('chatmaxima_sync_post_types');
        delete_option('chatmaxima_selected_team');

        // Delete transients
        delete_transient('chatmaxima_knowledge_sources');

        // Delete post meta
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_chatmaxima_%'");

        restore_current_blog();
    }
}
