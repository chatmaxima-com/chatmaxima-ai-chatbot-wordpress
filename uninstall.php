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

/**
 * Remove every ChatMaxima option, transient and post meta row from the current site
 */
function chatmaxima_uninstall_site()
{
    global $wpdb;

    $options = [
        'chatmaxima_token_id',
        'chatmaxima_theme_color',
        'chatmaxima_social_media',
        'chatmaxima_access_token',
        'chatmaxima_refresh_token',
        'chatmaxima_token_expiry',
        'chatmaxima_user_info',
        'chatmaxima_knowledge_source_alias',
        'chatmaxima_auto_sync',
        'chatmaxima_sync_post_types',
        'chatmaxima_selected_team',
        'chatmaxima_channel_alias',
        'chatmaxima_installed_channel',
        'chatmaxima_setup',
        'chatmaxima_last_account',
        'chatmaxima_plan_limit',
        'chatmaxima_last_full_sync',
    ];
    foreach ($options as $option)
    {
        delete_option($option);
    }

    delete_transient('chatmaxima_knowledge_sources');

    // Post meta written by content sync (_chatmaxima_sync_status, _chatmaxima_sync_date, ...)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_chatmaxima_') . '%'));
}

if (is_multisite())
{
    $chatmaxima_site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($chatmaxima_site_ids as $chatmaxima_site_id)
    {
        switch_to_blog($chatmaxima_site_id);
        chatmaxima_uninstall_site();
        restore_current_blog();
    }
}
else
{
    chatmaxima_uninstall_site();
}
