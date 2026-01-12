<?php
/**
 * Plugin Name: ChatMaxima AI Chatbot
 * Plugin URI: https://chatmaxima.com/
 * Description: AI-powered chatbot integration for WordPress with customizable themes, social media support, and knowledge source management.
 * Version: 2.0.0
 * Author: ChatMaxima
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: chatmaxima-ai-chatbot
 */

// Prevent direct access
if (!defined('ABSPATH'))
{
    exit;
}

// Define plugin constants
define('CHATMAXIMA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHATMAXIMA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CHATMAXIMA_VERSION', '2.0.0');

// Include required files
require_once CHATMAXIMA_PLUGIN_PATH . 'includes/class-api-client.php';
require_once CHATMAXIMA_PLUGIN_PATH . 'includes/class-admin-settings.php';
require_once CHATMAXIMA_PLUGIN_PATH . 'includes/class-content-sync.php';

// Global instances
global $chatmaxima_api_client;
global $chatmaxima_admin_settings;
global $chatmaxima_content_sync;

class ChatMaximaAIChatbot
{
    private $api_client;
    private $admin_settings;
    private $content_sync;

    public function __construct()
    {
        add_action('init', [$this, 'init']);
    }

    public function init()
    {
        global $chatmaxima_api_client, $chatmaxima_admin_settings, $chatmaxima_content_sync;

        // Initialize API client
        $this->api_client = new ChatMaxima_API_Client();
        $chatmaxima_api_client = $this->api_client;

        // Initialize admin settings (only in admin)
        if (is_admin())
        {
            $this->admin_settings = new ChatMaxima_Admin_Settings($this->api_client);
            $chatmaxima_admin_settings = $this->admin_settings;

            // Initialize content sync
            $this->content_sync = new ChatMaxima_Content_Sync($this->api_client);
            $chatmaxima_content_sync = $this->content_sync;
        }

        // Frontend script - add to both frontend and admin for testing
        add_action('wp_footer', [$this, 'add_chatmaxima_script']);
        add_action('admin_footer', [$this, 'add_chatmaxima_script']);
    }

    /**
     * Add ChatMaxima chatbot script to frontend and admin
     */
    public function add_chatmaxima_script()
    {
        // Use installed channel alias (set via Install Widget button) for frontend
        $installed_channel = get_option('chatmaxima_installed_channel', '');

        // For admin area, also show widget if a channel is selected (for preview)
        // This allows admins to preview the widget without enabling it for visitors
        if (is_admin())
        {
            $selected_channel = get_option('chatmaxima_channel_alias', '');
            $channel_alias = !empty($installed_channel) ? $installed_channel : $selected_channel;
        }
        else
        {
            // For frontend, only show if widget is explicitly installed
            $channel_alias = $installed_channel;
        }

        // Don't output script if no channel is available
        if (empty($channel_alias))
        {
            return;
        }

        // Use attributes to exclude from caching plugin optimization:
        // - data-no-optimize="1" : WP Rocket, LiteSpeed Cache
        // - data-cfasync="false" : Cloudflare Rocket Loader
        // - data-pagespeed-no-defer : PageSpeed module
        // - class="no-defer" : Various caching plugins
        ?>
        <script data-no-optimize="1" data-cfasync="false" data-pagespeed-no-defer class="chatmaxima-config">
            window.chatmaximaConfig = { token: '<?php echo esc_js($channel_alias); ?>' };
        </script>
        <script src="https://widget.chatmaxima.com/embed.min.js" id="chatmaxima-widget-<?php echo esc_attr($channel_alias); ?>" data-no-optimize="1" data-cfasync="false" data-pagespeed-no-defer class="chatmaxima-widget"></script>
        <?php
    }
}

// Initialize the plugin
new ChatMaximaAIChatbot();

// Activation hook
register_activation_hook(__FILE__, 'chatmaxima_activation');
function chatmaxima_activation()
{
    // Set default options
    add_option('chatmaxima_sync_post_types', ['post', 'page']);
    add_option('chatmaxima_auto_sync', false);

    // Clear any cached data
    delete_transient('chatmaxima_knowledge_sources');
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'chatmaxima_deactivation');
function chatmaxima_deactivation()
{
    // Clear transients
    delete_transient('chatmaxima_knowledge_sources');
}

// Add Settings link on Plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'chatmaxima_plugin_action_links');
function chatmaxima_plugin_action_links($links)
{
    $settings_link = '<a href="' . admin_url('admin.php?page=chatmaxima-settings') . '">' . __('Settings', 'chatmaxima-ai-chatbot') . '</a>';
    $knowledge_link = '<a href="' . admin_url('admin.php?page=chatmaxima-knowledge-sources') . '">' . __('Knowledge Sources', 'chatmaxima-ai-chatbot') . '</a>';

    array_unshift($links, $settings_link, $knowledge_link);
    return $links;
}

// Add row meta links on Plugins page
add_filter('plugin_row_meta', 'chatmaxima_plugin_row_meta', 10, 2);
function chatmaxima_plugin_row_meta($links, $file)
{
    if (plugin_basename(__FILE__) === $file)
    {
        $links[] = '<a href="https://chatmaxima.com/docs/" target="_blank">' . __('Documentation', 'chatmaxima-ai-chatbot') . '</a>';
        $links[] = '<a href="https://chatmaxima.com/support/" target="_blank">' . __('Support', 'chatmaxima-ai-chatbot') . '</a>';
    }
    return $links;
}

/**
 * Exclude ChatMaxima scripts from caching plugin optimization
 * This prevents issues with widget not loading on cached pages
 */

// WP Rocket - Exclude from JS minification and concatenation
add_filter('rocket_exclude_js', 'chatmaxima_exclude_from_wp_rocket');
add_filter('rocket_exclude_defer_js', 'chatmaxima_exclude_from_wp_rocket');
add_filter('rocket_delay_js_exclusions', 'chatmaxima_exclude_from_wp_rocket');
function chatmaxima_exclude_from_wp_rocket($excluded)
{
    $excluded[] = 'widget.chatmaxima.com';
    $excluded[] = 'chatmaximaConfig';
    $excluded[] = 'chatmaxima-widget';
    return $excluded;
}

// LiteSpeed Cache - Exclude from JS optimization
add_filter('litespeed_optimize_js_excludes', 'chatmaxima_exclude_from_litespeed');
function chatmaxima_exclude_from_litespeed($excluded)
{
    $excluded[] = 'widget.chatmaxima.com';
    $excluded[] = 'chatmaximaConfig';
    return $excluded;
}

// Autoptimize - Exclude from JS optimization
add_filter('autoptimize_filter_js_exclude', 'chatmaxima_exclude_from_autoptimize');
function chatmaxima_exclude_from_autoptimize($excluded)
{
    return $excluded . ', widget.chatmaxima.com, chatmaximaConfig, chatmaxima-widget';
}

// W3 Total Cache - Exclude from minification
add_filter('w3tc_minify_js_do_tag_minification', 'chatmaxima_exclude_from_w3tc', 10, 3);
function chatmaxima_exclude_from_w3tc($do_minify, $script_tag, $file)
{
    if (strpos($script_tag, 'chatmaxima') !== false || strpos($script_tag, 'widget.chatmaxima.com') !== false)
    {
        return false;
    }
    return $do_minify;
}

// WP Super Cache - Add exclusion note (requires manual config)
// SG Optimizer - Exclude from JS combination
add_filter('sgo_javascript_combine_exclude_ids', 'chatmaxima_exclude_from_sg_optimizer');
function chatmaxima_exclude_from_sg_optimizer($excluded_ids)
{
    $excluded_ids[] = 'chatmaxima-widget';
    return $excluded_ids;
}
