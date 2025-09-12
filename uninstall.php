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
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('chatmaxima_token_id');
delete_option('chatmaxima_theme_color');
delete_option('chatmaxima_social_media');

// For multisite installations, delete options from all sites
if (is_multisite()) {
    global $wpdb;
    
    $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
    
    foreach ($blog_ids as $blog_id) {
        switch_to_blog($blog_id);
        
        delete_option('chatmaxima_token_id');
        delete_option('chatmaxima_theme_color');
        delete_option('chatmaxima_social_media');
        
        restore_current_blog();
    }
}