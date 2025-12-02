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

        // Frontend script
        add_action('wp_footer', [$this, 'add_chatmaxima_script']);
    }

    /**
     * Add ChatMaxima chatbot script to frontend
     */
    public function add_chatmaxima_script()
    {
        $token_id = get_option('chatmaxima_token_id', '');
        $theme_color = get_option('chatmaxima_theme_color', '');
        $social_media = get_option('chatmaxima_social_media', []);

        // Don't output script if token is empty
        if (empty($token_id))
        {
            return;
        }

        // Format social media data
        $social_media_formatted = [];
        if (!empty($social_media))
        {
            foreach ($social_media as $platform => $handle)
            {
                if (!empty($handle))
                {
                    $social_media_formatted[] = [
                        'platform' => $platform,
                        'handle' => $handle
                    ];
                }
            }
        }

        ?>
        <script type="text/javascript">
            window.chatmaximaConfig = {
                token: '<?php echo esc_js($token_id); ?>',
                theme_color: '<?php echo esc_js($theme_color); ?>',
                social_media: <?php echo wp_json_encode($social_media_formatted); ?>
            };
        </script>
        <script src="https://chatmaxima.com/widget/chatmaxima-widget.js" async></script>
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
