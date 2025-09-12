<?php
/**
 * Plugin Name: ChatMaxima AI Chatbot
 * Plugin URI: https://chatmaxima.com/
 * Description: AI-powered chatbot integration for WordPress with customizable themes and social media support.
 * Version: 1.0.0
 * Author: ChatMaxima
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: chatmaxima-ai-chatbot
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CHATMAXIMA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHATMAXIMA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CHATMAXIMA_VERSION', '1.0.0');

class ChatMaximaAIChatbot {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        
        // Frontend script
        add_action('wp_footer', array($this, 'add_chatmaxima_script'));
        
        // Enqueue admin styles
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }
    
    public function add_admin_menu() {
        add_options_page(
            'ChatMaxima AI Chatbot Settings',
            'ChatMaxima Chatbot',
            'manage_options',
            'chatmaxima-settings',
            array($this, 'settings_page')
        );
    }
    
    public function admin_enqueue_scripts($hook) {
        if ($hook !== 'settings_page_chatmaxima-settings') {
            return;
        }
        
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_script('chatmaxima-admin', CHATMAXIMA_PLUGIN_URL . 'assets/admin.js', array('wp-color-picker'), CHATMAXIMA_VERSION, true);
    }
    
    public function settings_init() {
        register_setting('chatmaxima_settings', 'chatmaxima_token_id');
        register_setting('chatmaxima_settings', 'chatmaxima_theme_color');
        register_setting('chatmaxima_settings', 'chatmaxima_social_media');
        
        add_settings_section(
            'chatmaxima_general_section',
            __('General Settings', 'chatmaxima-ai-chatbot'),
            array($this, 'settings_section_callback'),
            'chatmaxima_settings'
        );
        
        add_settings_field(
            'chatmaxima_token_id',
            __('Token ID', 'chatmaxima-ai-chatbot'),
            array($this, 'token_id_render'),
            'chatmaxima_settings',
            'chatmaxima_general_section'
        );
        
        add_settings_field(
            'chatmaxima_theme_color',
            __('Theme Color', 'chatmaxima-ai-chatbot'),
            array($this, 'theme_color_render'),
            'chatmaxima_settings',
            'chatmaxima_general_section'
        );
        
        add_settings_field(
            'chatmaxima_social_media',
            __('Social Media Details', 'chatmaxima-ai-chatbot'),
            array($this, 'social_media_render'),
            'chatmaxima_settings',
            'chatmaxima_general_section'
        );
    }
    
    public function settings_section_callback() {
        echo __('Configure your ChatMaxima AI Chatbot settings below.', 'chatmaxima-ai-chatbot');
    }
    
    public function token_id_render() {
        $token_id = get_option('chatmaxima_token_id', '');
        ?>
        <input type="text" id="chatmaxima_token_id" name="chatmaxima_token_id" value="<?php echo esc_attr($token_id); ?>" class="regular-text" />
        <p class="description"><?php _e('Enter your ChatMaxima Token ID.', 'chatmaxima-ai-chatbot'); ?></p>
        <?php
    }
    
    public function theme_color_render() {
        $theme_color = get_option('chatmaxima_theme_color', '');
        ?>
        <input type="text" id="chatmaxima_theme_color" name="chatmaxima_theme_color" value="<?php echo esc_attr($theme_color); ?>" class="chatmaxima-color-field" />
        <p class="description"><?php _e('Choose the theme color for your chatbot.', 'chatmaxima-ai-chatbot'); ?></p>
        <?php
    }
    
    public function social_media_render() {
        $social_media = get_option('chatmaxima_social_media', array());
        
        $platforms = array(
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'telegram' => 'Telegram',
            'whatsapp' => 'WhatsApp',
            'mail' => 'Email',
            'call' => 'Phone',
            'sms' => 'SMS'
        );
        
        echo '<table class="form-table">';
        foreach ($platforms as $platform => $label) {
            $value = isset($social_media[$platform]) ? $social_media[$platform] : '';
            echo '<tr>';
            echo '<td style="padding: 10px 0;"><label for="social_' . esc_attr($platform) . '">' . esc_html($label) . ':</label></td>';
            echo '<td style="padding: 10px 0;"><input type="text" id="social_' . esc_attr($platform) . '" name="chatmaxima_social_media[' . esc_attr($platform) . ']" value="' . esc_attr($value) . '" class="regular-text" /></td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<p class="description">' . __('Enter your social media handles and contact information.', 'chatmaxima-ai-chatbot') . '</p>';
    }
    
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('ChatMaxima AI Chatbot Settings', 'chatmaxima-ai-chatbot'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('chatmaxima_settings');
                do_settings_sections('chatmaxima_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    public function add_chatmaxima_script() {
        $token_id = get_option('chatmaxima_token_id', '');
        $theme_color = get_option('chatmaxima_theme_color', '');
        $social_media = get_option('chatmaxima_social_media', array());
        
        // Don't output script if token is empty
        if (empty($token_id)) {
            return;
        }
        
        // Format social media data
        $social_media_formatted = array();
        if (!empty($social_media)) {
            foreach ($social_media as $platform => $handle) {
                if (!empty($handle)) {
                    $social_media_formatted[] = array(
                        'platform' => $platform,
                        'handle' => $handle
                    );
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
        <?php
    }
}

// Initialize the plugin
new ChatMaximaAIChatbot();

// Activation hook
register_activation_hook(__FILE__, 'chatmaxima_activation');
function chatmaxima_activation() {
    // Plugin activated - no default values set
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'chatmaxima_deactivation');
function chatmaxima_deactivation() {
    // Clean up if needed
}