<?php
/**
 * ChatMaxima Admin Settings
 *
 * Handles the admin settings page with API authentication and knowledge source management
 *
 * @package ChatMaxima AI Chatbot
 */

// Prevent direct access
if (!defined('ABSPATH'))
{
    exit;
}

class ChatMaxima_Admin_Settings
{
    private $api_client;

    public function __construct($api_client)
    {
        $this->api_client = $api_client;

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);

        // AJAX handlers
        add_action('wp_ajax_chatmaxima_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_chatmaxima_login', [$this, 'ajax_login']);
        add_action('wp_ajax_chatmaxima_logout', [$this, 'ajax_logout']);
        add_action('wp_ajax_chatmaxima_get_knowledge_sources', [$this, 'ajax_get_knowledge_sources']);
        add_action('wp_ajax_chatmaxima_create_knowledge_source', [$this, 'ajax_create_knowledge_source']);
        add_action('wp_ajax_chatmaxima_sync_posts', [$this, 'ajax_sync_posts']);
        add_action('wp_ajax_chatmaxima_save_ks_selection', [$this, 'ajax_save_ks_selection']);
        add_action('wp_ajax_chatmaxima_get_teams', [$this, 'ajax_get_teams']);
        add_action('wp_ajax_chatmaxima_switch_team', [$this, 'ajax_switch_team']);
        add_action('wp_ajax_chatmaxima_get_channels', [$this, 'ajax_get_channels']);
        add_action('wp_ajax_chatmaxima_save_channel_selection', [$this, 'ajax_save_channel_selection']);
        add_action('wp_ajax_chatmaxima_install_widget', [$this, 'ajax_install_widget']);
        add_action('wp_ajax_chatmaxima_uninstall_widget', [$this, 'ajax_uninstall_widget']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        // Add main menu in sidebar
        add_menu_page(
            __('ChatMaxima AI Chatbot', 'chatmaxima-ai-chatbot'),
            __('ChatMaxima', 'chatmaxima-ai-chatbot'),
            'manage_options',
            'chatmaxima-settings',
            [$this, 'settings_page'],
            'dashicons-format-chat',
            30
        );

        // Add submenu for settings
        add_submenu_page(
            'chatmaxima-settings',
            __('Settings', 'chatmaxima-ai-chatbot'),
            __('Settings', 'chatmaxima-ai-chatbot'),
            'manage_options',
            'chatmaxima-settings',
            [$this, 'settings_page']
        );

        // Add submenu for Knowledge Sources
        add_submenu_page(
            'chatmaxima-settings',
            __('Knowledge Sources', 'chatmaxima-ai-chatbot'),
            __('Knowledge Sources', 'chatmaxima-ai-chatbot'),
            'manage_options',
            'chatmaxima-knowledge-sources',
            [$this, 'knowledge_sources_page']
        );

        // Add submenu for Sync
        add_submenu_page(
            'chatmaxima-settings',
            __('Content Sync', 'chatmaxima-ai-chatbot'),
            __('Content Sync', 'chatmaxima-ai-chatbot'),
            'manage_options',
            'chatmaxima-sync',
            [$this, 'sync_page']
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook)
    {
        // Load on all ChatMaxima admin pages
        $allowed_pages = [
            'toplevel_page_chatmaxima-settings',
            'chatmaxima_page_chatmaxima-knowledge-sources',
            'chatmaxima_page_chatmaxima-sync'
        ];

        if (!in_array($hook, $allowed_pages))
        {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        wp_enqueue_style(
            'chatmaxima-admin',
            CHATMAXIMA_PLUGIN_URL . 'assets/admin.css',
            [],
            CHATMAXIMA_VERSION
        );

        wp_enqueue_script(
            'chatmaxima-admin',
            CHATMAXIMA_PLUGIN_URL . 'assets/admin.js',
            ['jquery', 'wp-color-picker'],
            CHATMAXIMA_VERSION,
            true
        );

        wp_localize_script('chatmaxima-admin', 'chatmaximaAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('chatmaxima_admin_nonce'),
            'isAuthenticated' => $this->api_client->is_authenticated(),
            'selectedKnowledgeSource' => get_option('chatmaxima_knowledge_source_alias', ''),
            'strings' => [
                'connecting' => __('Connecting...', 'chatmaxima-ai-chatbot'),
                'connected' => __('Connected', 'chatmaxima-ai-chatbot'),
                'disconnected' => __('Disconnected', 'chatmaxima-ai-chatbot'),
                'error' => __('Error', 'chatmaxima-ai-chatbot'),
                'success' => __('Success', 'chatmaxima-ai-chatbot'),
                'syncing' => __('Syncing...', 'chatmaxima-ai-chatbot'),
                'syncComplete' => __('Sync complete!', 'chatmaxima-ai-chatbot')
            ]
        ]);
    }

    /**
     * Initialize settings
     */
    public function settings_init()
    {
        // API Settings Section
        add_settings_section(
            'chatmaxima_api_section',
            __('API Connection', 'chatmaxima-ai-chatbot'),
            [$this, 'api_section_callback'],
            'chatmaxima_settings'
        );

        // Knowledge Source Section
        add_settings_section(
            'chatmaxima_knowledge_section',
            __('Knowledge Source', 'chatmaxima-ai-chatbot'),
            [$this, 'knowledge_section_callback'],
            'chatmaxima_settings'
        );

        // Content Sync Section
        add_settings_section(
            'chatmaxima_sync_section',
            __('Content Sync', 'chatmaxima-ai-chatbot'),
            [$this, 'sync_section_callback'],
            'chatmaxima_settings'
        );

        // Chatbot Settings Section
        add_settings_section(
            'chatmaxima_chatbot_section',
            __('Chatbot Settings', 'chatmaxima-ai-chatbot'),
            [$this, 'chatbot_section_callback'],
            'chatmaxima_settings'
        );

        // Register settings
        register_setting('chatmaxima_settings', 'chatmaxima_token_id');
        register_setting('chatmaxima_settings', 'chatmaxima_theme_color');
        register_setting('chatmaxima_settings', 'chatmaxima_social_media');
        register_setting('chatmaxima_settings', 'chatmaxima_knowledge_source_alias');
        register_setting('chatmaxima_settings', 'chatmaxima_auto_sync');
        register_setting('chatmaxima_settings', 'chatmaxima_sync_post_types');

        // Token ID field
        add_settings_field(
            'chatmaxima_token_id',
            __('Token ID', 'chatmaxima-ai-chatbot'),
            [$this, 'token_id_render'],
            'chatmaxima_settings',
            'chatmaxima_chatbot_section'
        );

        // Theme color field
        add_settings_field(
            'chatmaxima_theme_color',
            __('Theme Color', 'chatmaxima-ai-chatbot'),
            [$this, 'theme_color_render'],
            'chatmaxima_settings',
            'chatmaxima_chatbot_section'
        );

        // Social media field
        add_settings_field(
            'chatmaxima_social_media',
            __('Social Media Details', 'chatmaxima-ai-chatbot'),
            [$this, 'social_media_render'],
            'chatmaxima_settings',
            'chatmaxima_chatbot_section'
        );
    }

    /**
     * API section callback
     */
    public function api_section_callback()
    {
        echo '<p>' . __('Connect to ChatMaxima API to manage knowledge sources and sync content.', 'chatmaxima-ai-chatbot') . '</p>';
    }

    /**
     * Knowledge section callback
     */
    public function knowledge_section_callback()
    {
        echo '<p>' . __('Select or create a knowledge source to store your WordPress content.', 'chatmaxima-ai-chatbot') . '</p>';
    }

    /**
     * Sync section callback
     */
    public function sync_section_callback()
    {
        echo '<p>' . __('Sync your WordPress posts and pages to the selected knowledge source.', 'chatmaxima-ai-chatbot') . '</p>';
    }

    /**
     * Chatbot section callback
     */
    public function chatbot_section_callback()
    {
        echo '<p>' . __('Configure your chatbot appearance and behavior.', 'chatmaxima-ai-chatbot') . '</p>';
    }

    /**
     * Token ID field render
     */
    public function token_id_render()
    {
        $token_id = get_option('chatmaxima_token_id', '');
        ?>
        <input type="text" id="chatmaxima_token_id" name="chatmaxima_token_id" value="<?php echo esc_attr($token_id); ?>" class="regular-text" />
        <p class="description"><?php _e('Enter your ChatMaxima Token ID for the chatbot widget.', 'chatmaxima-ai-chatbot'); ?></p>
        <?php
    }

    /**
     * Theme color field render
     */
    public function theme_color_render()
    {
        $theme_color = get_option('chatmaxima_theme_color', '#007bff');
        ?>
        <input type="text" id="chatmaxima_theme_color" name="chatmaxima_theme_color" value="<?php echo esc_attr($theme_color); ?>" class="chatmaxima-color-field" />
        <p class="description"><?php _e('Choose the theme color for your chatbot.', 'chatmaxima-ai-chatbot'); ?></p>
        <?php
    }

    /**
     * Social media field render
     */
    public function social_media_render()
    {
        $social_media = get_option('chatmaxima_social_media', []);

        $platforms = [
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'telegram' => 'Telegram',
            'whatsapp' => 'WhatsApp',
            'mail' => 'Email',
            'call' => 'Phone',
            'sms' => 'SMS'
        ];

        echo '<table class="form-table chatmaxima-social-table">';
        foreach ($platforms as $platform => $label)
        {
            $value = isset($social_media[$platform]) ? $social_media[$platform] : '';
            echo '<tr>';
            echo '<td style="padding: 5px 10px 5px 0; width: 100px;"><label for="social_' . esc_attr($platform) . '">' . esc_html($label) . ':</label></td>';
            echo '<td style="padding: 5px 0;"><input type="text" id="social_' . esc_attr($platform) . '" name="chatmaxima_social_media[' . esc_attr($platform) . ']" value="' . esc_attr($value) . '" class="regular-text" /></td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<p class="description">' . __('Enter your social media handles and contact information.', 'chatmaxima-ai-chatbot') . '</p>';
    }

    /**
     * Settings page
     */
    public function settings_page()
    {
        $is_authenticated = $this->api_client->is_authenticated();
        $user_info = get_option('chatmaxima_user_info', []);
        $knowledge_source_alias = get_option('chatmaxima_knowledge_source_alias', '');
        $auto_sync = get_option('chatmaxima_auto_sync', false);
        $sync_post_types = get_option('chatmaxima_sync_post_types', ['post', 'page']);
        ?>
        <div class="wrap chatmaxima-settings-wrap">
            <h1><?php _e('ChatMaxima AI Chatbot Settings', 'chatmaxima-ai-chatbot'); ?></h1>

            <!-- API Connection Section -->
            <div class="chatmaxima-card">
                <h2><?php _e('API Connection', 'chatmaxima-ai-chatbot'); ?></h2>

                <div id="chatmaxima-connection-status" class="chatmaxima-status <?php echo $is_authenticated ? 'connected' : 'disconnected'; ?>">
                    <span class="status-indicator"></span>
                    <span class="status-text">
                        <?php
                        if ($is_authenticated && !empty($user_info))
                        {
                            printf(__('Connected as %s (%s)', 'chatmaxima-ai-chatbot'), esc_html($user_info['name']), esc_html($user_info['email']));
                            if (!empty($user_info['team_alias']))
                            {
                                echo '<br><small>' . sprintf(__('Workspace ID: %s', 'chatmaxima-ai-chatbot'), esc_html($user_info['team_alias'])) . '</small>';
                            }
                        }
                        else
                        {
                            _e('Not connected', 'chatmaxima-ai-chatbot');
                        }
                        ?>
                    </span>
                </div>

                <?php if (!$is_authenticated): ?>
                <div id="chatmaxima-login-form" class="chatmaxima-login-form">
                    <p><?php _e('Enter your ChatMaxima credentials to connect:', 'chatmaxima-ai-chatbot'); ?></p>
                    <table class="form-table">
                        <tr>
                            <th><label for="chatmaxima_email"><?php _e('Email', 'chatmaxima-ai-chatbot'); ?></label></th>
                            <td><input type="email" id="chatmaxima_email" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th><label for="chatmaxima_password"><?php _e('Password', 'chatmaxima-ai-chatbot'); ?></label></th>
                            <td><input type="password" id="chatmaxima_password" class="regular-text" /></td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" id="chatmaxima-login-btn" class="button button-primary"><?php _e('Connect', 'chatmaxima-ai-chatbot'); ?></button>
                        <span id="chatmaxima-login-message" class="chatmaxima-message"></span>
                    </p>
                </div>
                <?php else: ?>
                <p>
                    <button type="button" id="chatmaxima-logout-btn" class="button"><?php _e('Disconnect', 'chatmaxima-ai-chatbot'); ?></button>
                    <button type="button" id="chatmaxima-test-btn" class="button"><?php _e('Test Connection', 'chatmaxima-ai-chatbot'); ?></button>
                    <span id="chatmaxima-connection-message" class="chatmaxima-message"></span>
                </p>

                <!-- Workspace Selection -->
                <div class="chatmaxima-team-selector" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <h3><?php _e('Workspace Selection', 'chatmaxima-ai-chatbot'); ?></h3>
                    <p class="description"><?php _e('Select the workspace to use for knowledge sources and content sync.', 'chatmaxima-ai-chatbot'); ?></p>
                    <table class="form-table">
                        <tr>
                            <th><label for="chatmaxima_team"><?php _e('Workspace', 'chatmaxima-ai-chatbot'); ?></label></th>
                            <td>
                                <select id="chatmaxima_team" class="regular-text">
                                    <option value=""><?php _e('-- Loading workspaces... --', 'chatmaxima-ai-chatbot'); ?></option>
                                </select>
                                <button type="button" id="chatmaxima-refresh-teams-btn" class="button"><?php _e('Refresh', 'chatmaxima-ai-chatbot'); ?></button>
                                <span id="chatmaxima-team-message" class="chatmaxima-message"></span>
                            </td>
                        </tr>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($is_authenticated): ?>
            <!-- Knowledge Source Section -->
            <div class="chatmaxima-card">
                <h2><?php _e('Knowledge Source', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php _e('Select an existing knowledge source or create a new one to store your WordPress content.', 'chatmaxima-ai-chatbot'); ?></p>

                <table class="form-table">
                    <tr>
                        <th><label for="chatmaxima_knowledge_source"><?php _e('Knowledge Source', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <select id="chatmaxima_knowledge_source" class="regular-text">
                                <option value=""><?php _e('-- Select Knowledge Source --', 'chatmaxima-ai-chatbot'); ?></option>
                            </select>
                            <button type="button" id="chatmaxima-refresh-ks-btn" class="button"><?php _e('Refresh', 'chatmaxima-ai-chatbot'); ?></button>
                            <p class="description"><?php _e('Select the knowledge source where your content will be synced.', 'chatmaxima-ai-chatbot'); ?></p>
                        </td>
                    </tr>
                </table>

                <div class="chatmaxima-create-ks">
                    <h3><?php _e('Create New Knowledge Source', 'chatmaxima-ai-chatbot'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="chatmaxima_new_ks_name"><?php _e('Name', 'chatmaxima-ai-chatbot'); ?></label></th>
                            <td><input type="text" id="chatmaxima_new_ks_name" class="regular-text" placeholder="<?php esc_attr_e('e.g., My WordPress Site', 'chatmaxima-ai-chatbot'); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="chatmaxima_new_ks_llm"><?php _e('LLM Type', 'chatmaxima-ai-chatbot'); ?></label></th>
                            <td>
                                <select id="chatmaxima_new_ks_llm">
                                    <option value="openai">OpenAI</option>
                                    <option value="claude">Claude</option>
                                    <option value="gemini">Gemini</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" id="chatmaxima-create-ks-btn" class="button button-primary"><?php _e('Create Knowledge Source', 'chatmaxima-ai-chatbot'); ?></button>
                        <span id="chatmaxima-create-ks-message" class="chatmaxima-message"></span>
                    </p>
                </div>
            </div>

            <!-- Content Sync Section -->
            <div class="chatmaxima-card">
                <h2><?php _e('Content Sync', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php _e('Sync your WordPress posts and pages to the selected knowledge source.', 'chatmaxima-ai-chatbot'); ?></p>

                <table class="form-table">
                    <tr>
                        <th><label><?php _e('Post Types to Sync', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <?php
                            $post_types = get_post_types(['public' => true], 'objects');
                            foreach ($post_types as $post_type)
                            {
                                if ($post_type->name === 'attachment') continue;
                                $checked = in_array($post_type->name, (array)$sync_post_types) ? 'checked' : '';
                                echo '<label style="display: block; margin-bottom: 5px;">';
                                echo '<input type="checkbox" name="chatmaxima_sync_post_types[]" value="' . esc_attr($post_type->name) . '" ' . $checked . ' class="chatmaxima-sync-post-type" /> ';
                                echo esc_html($post_type->labels->name);
                                echo '</label>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="chatmaxima_auto_sync"><?php _e('Auto Sync', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="chatmaxima_auto_sync" name="chatmaxima_auto_sync" value="1" <?php checked($auto_sync, true); ?> />
                                <?php _e('Automatically sync new posts when published', 'chatmaxima-ai-chatbot'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <div class="chatmaxima-sync-actions">
                    <h3><?php _e('Bulk Sync', 'chatmaxima-ai-chatbot'); ?></h3>
                    <p><?php _e('Sync all published content to the selected knowledge source.', 'chatmaxima-ai-chatbot'); ?></p>

                    <div id="chatmaxima-sync-progress" class="chatmaxima-progress" style="display: none;">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: 0%;"></div>
                        </div>
                        <p class="progress-text"><?php _e('Syncing...', 'chatmaxima-ai-chatbot'); ?> <span class="progress-count">0/0</span></p>
                    </div>

                    <p>
                        <button type="button" id="chatmaxima-sync-all-btn" class="button button-primary"><?php _e('Sync All Content', 'chatmaxima-ai-chatbot'); ?></button>
                        <span id="chatmaxima-sync-message" class="chatmaxima-message"></span>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($is_authenticated): ?>
            <?php
            $installed_channel = get_option('chatmaxima_installed_channel', '');
            $is_widget_installed = !empty($installed_channel);
            ?>
            <!-- Web Channels Section -->
            <div class="chatmaxima-card">
                <h2><?php _e('Chatbot Widget', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php _e('Select a web channel and install the chatbot widget on your WordPress site.', 'chatmaxima-ai-chatbot'); ?></p>

                <table class="form-table">
                    <tr>
                        <th><label for="chatmaxima_channel"><?php _e('Channel', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <select id="chatmaxima_channel" class="regular-text">
                                <option value=""><?php _e('-- Loading channels... --', 'chatmaxima-ai-chatbot'); ?></option>
                            </select>
                            <button type="button" id="chatmaxima-refresh-channels-btn" class="button"><?php _e('Refresh', 'chatmaxima-ai-chatbot'); ?></button>
                            <span id="chatmaxima-channel-message" class="chatmaxima-message"></span>
                            <p class="description"><?php _e('Select the web channel to embed on your WordPress site.', 'chatmaxima-ai-chatbot'); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- Widget Script Preview -->
                <div id="chatmaxima-widget-script" style="display: none; margin-top: 20px;">
                    <h3><?php _e('Widget Script', 'chatmaxima-ai-chatbot'); ?></h3>
                    <p class="description"><?php _e('This script will be automatically added to your website footer when installed.', 'chatmaxima-ai-chatbot'); ?></p>
                    <div style="position: relative;">
                        <pre id="chatmaxima-script-code" style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; overflow-x: auto; font-family: 'Courier New', monospace; font-size: 13px;"></pre>
                        <button type="button" id="chatmaxima-copy-script-btn" class="button" style="position: absolute; top: 10px; right: 10px;"><?php _e('Copy', 'chatmaxima-ai-chatbot'); ?></button>
                    </div>

                    <div style="margin-top: 15px; display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-start;">
                        <!-- Admin Playground Button -->
                        <div style="flex: 1; min-width: 200px; padding: 15px; background: #f0f6fc; border: 1px solid #0366d6; border-radius: 6px;">
                            <h4 style="margin: 0 0 8px 0; color: #0366d6;"><?php _e('Admin Preview', 'chatmaxima-ai-chatbot'); ?></h4>
                            <p style="margin: 0 0 12px 0; font-size: 12px; color: #586069;"><?php _e('Test the widget in this admin area only. Visitors won\'t see it.', 'chatmaxima-ai-chatbot'); ?></p>
                            <button type="button" id="chatmaxima-preview-widget-btn" class="button button-secondary">
                                <?php _e('Preview Widget', 'chatmaxima-ai-chatbot'); ?>
                            </button>
                        </div>

                        <!-- Enable for Users Button -->
                        <div style="flex: 1; min-width: 200px; padding: 15px; background: <?php echo $is_widget_installed ? '#d4edda' : '#fff3cd'; ?>; border: 1px solid <?php echo $is_widget_installed ? '#c3e6cb' : '#ffc107'; ?>; border-radius: 6px;">
                            <h4 style="margin: 0 0 8px 0; color: <?php echo $is_widget_installed ? '#155724' : '#856404'; ?>;"><?php _e('Live on Site', 'chatmaxima-ai-chatbot'); ?></h4>
                            <p style="margin: 0 0 12px 0; font-size: 12px; color: #586069;"><?php _e('Enable the widget for all visitors on your website.', 'chatmaxima-ai-chatbot'); ?></p>
                            <button type="button" id="chatmaxima-install-widget-btn" class="button button-primary" <?php echo $is_widget_installed ? 'style="display:none;"' : ''; ?>>
                                <?php _e('Enable for Visitors', 'chatmaxima-ai-chatbot'); ?>
                            </button>
                            <button type="button" id="chatmaxima-uninstall-widget-btn" class="button button-secondary" <?php echo !$is_widget_installed ? 'style="display:none;"' : ''; ?>>
                                <?php _e('Disable for Visitors', 'chatmaxima-ai-chatbot'); ?>
                            </button>
                        </div>
                    </div>

                    <span id="chatmaxima-install-message" class="chatmaxima-message" style="display: block; margin-top: 10px;"></span>

                    <?php if ($is_widget_installed): ?>
                    <div id="chatmaxima-widget-status" style="margin-top: 15px; padding: 10px 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;">
                        <strong><?php _e('Status:', 'chatmaxima-ai-chatbot'); ?></strong> <?php _e('Widget is enabled and visible to all visitors on your site.', 'chatmaxima-ai-chatbot'); ?>
                    </div>
                    <?php else: ?>
                    <div id="chatmaxima-widget-status" style="display: none; margin-top: 15px; padding: 10px 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;">
                        <strong><?php _e('Status:', 'chatmaxima-ai-chatbot'); ?></strong> <?php _e('Widget is enabled and visible to all visitors on your site.', 'chatmaxima-ai-chatbot'); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Knowledge Sources page
     */
    public function knowledge_sources_page()
    {
        $is_authenticated = $this->api_client->is_authenticated();
        ?>
        <div class="wrap chatmaxima-settings-wrap">
            <h1><?php _e('Knowledge Sources', 'chatmaxima-ai-chatbot'); ?></h1>

            <?php if (!$is_authenticated): ?>
            <div class="chatmaxima-card">
                <h2><?php _e('Not Connected', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php _e('Please connect to ChatMaxima first.', 'chatmaxima-ai-chatbot'); ?></p>
                <p><a href="<?php echo admin_url('admin.php?page=chatmaxima-settings'); ?>" class="button button-primary"><?php _e('Go to Settings', 'chatmaxima-ai-chatbot'); ?></a></p>
            </div>
            <?php else: ?>
            <div class="chatmaxima-card">
                <h2><?php _e('Your Knowledge Sources', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php _e('Manage your knowledge sources for AI training.', 'chatmaxima-ai-chatbot'); ?></p>

                <table class="form-table">
                    <tr>
                        <th><label for="chatmaxima_knowledge_source"><?php _e('Select Knowledge Source', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <select id="chatmaxima_knowledge_source" class="regular-text">
                                <option value=""><?php _e('-- Select Knowledge Source --', 'chatmaxima-ai-chatbot'); ?></option>
                            </select>
                            <button type="button" id="chatmaxima-refresh-ks-btn" class="button"><?php _e('Refresh', 'chatmaxima-ai-chatbot'); ?></button>
                        </td>
                    </tr>
                </table>

                <div id="chatmaxima-ks-details" class="chatmaxima-ks-details" style="display: none; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                    <h3><?php _e('Knowledge Source Details', 'chatmaxima-ai-chatbot'); ?></h3>
                    <p><strong><?php _e('Name:', 'chatmaxima-ai-chatbot'); ?></strong> <span id="ks-detail-name">-</span></p>
                    <p><strong><?php _e('Documents:', 'chatmaxima-ai-chatbot'); ?></strong> <span id="ks-detail-docs">-</span></p>
                    <p><strong><?php _e('Storage:', 'chatmaxima-ai-chatbot'); ?></strong> <span id="ks-detail-storage">-</span></p>
                    <p><strong><?php _e('Type:', 'chatmaxima-ai-chatbot'); ?></strong> <span id="ks-detail-type">-</span></p>
                </div>
            </div>

            <div class="chatmaxima-card">
                <h2><?php _e('Create New Knowledge Source', 'chatmaxima-ai-chatbot'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="chatmaxima_new_ks_name"><?php _e('Name', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td><input type="text" id="chatmaxima_new_ks_name" class="regular-text" placeholder="<?php esc_attr_e('e.g., My WordPress Site', 'chatmaxima-ai-chatbot'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="chatmaxima_new_ks_llm"><?php _e('LLM Type', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <select id="chatmaxima_new_ks_llm">
                                <option value="openai">OpenAI</option>
                                <option value="claude">Claude</option>
                                <option value="gemini">Gemini</option>
                            </select>
                            <p class="description"><?php _e('Select the AI model to use for this knowledge source.', 'chatmaxima-ai-chatbot'); ?></p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="button" id="chatmaxima-create-ks-btn" class="button button-primary"><?php _e('Create Knowledge Source', 'chatmaxima-ai-chatbot'); ?></button>
                    <span id="chatmaxima-create-ks-message" class="chatmaxima-message"></span>
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Content Sync page
     */
    public function sync_page()
    {
        $is_authenticated = $this->api_client->is_authenticated();
        $knowledge_source_alias = get_option('chatmaxima_knowledge_source_alias', '');
        $auto_sync = get_option('chatmaxima_auto_sync', false);
        $sync_post_types = get_option('chatmaxima_sync_post_types', ['post', 'page']);
        ?>
        <div class="wrap chatmaxima-settings-wrap">
            <h1><?php _e('Content Sync', 'chatmaxima-ai-chatbot'); ?></h1>

            <?php if (!$is_authenticated): ?>
            <div class="chatmaxima-card">
                <h2><?php _e('Not Connected', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php _e('Please connect to ChatMaxima first.', 'chatmaxima-ai-chatbot'); ?></p>
                <p><a href="<?php echo admin_url('admin.php?page=chatmaxima-settings'); ?>" class="button button-primary"><?php _e('Go to Settings', 'chatmaxima-ai-chatbot'); ?></a></p>
            </div>
            <?php elseif (empty($knowledge_source_alias)): ?>
            <div class="chatmaxima-card">
                <h2><?php _e('No Knowledge Source Selected', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php _e('Please select a knowledge source first.', 'chatmaxima-ai-chatbot'); ?></p>
                <p><a href="<?php echo admin_url('admin.php?page=chatmaxima-knowledge-sources'); ?>" class="button button-primary"><?php _e('Select Knowledge Source', 'chatmaxima-ai-chatbot'); ?></a></p>
            </div>
            <?php else: ?>
            <div class="chatmaxima-card">
                <h2><?php _e('Sync Settings', 'chatmaxima-ai-chatbot'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th><label><?php _e('Knowledge Source', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <select id="chatmaxima_knowledge_source" class="regular-text">
                                <option value=""><?php _e('-- Select Knowledge Source --', 'chatmaxima-ai-chatbot'); ?></option>
                            </select>
                            <button type="button" id="chatmaxima-refresh-ks-btn" class="button"><?php _e('Refresh', 'chatmaxima-ai-chatbot'); ?></button>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Post Types to Sync', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <?php
                            $post_types = get_post_types(['public' => true], 'objects');
                            foreach ($post_types as $post_type)
                            {
                                if ($post_type->name === 'attachment') continue;
                                $checked = in_array($post_type->name, (array)$sync_post_types) ? 'checked' : '';
                                echo '<label style="display: block; margin-bottom: 5px;">';
                                echo '<input type="checkbox" name="chatmaxima_sync_post_types[]" value="' . esc_attr($post_type->name) . '" ' . $checked . ' class="chatmaxima-sync-post-type" /> ';
                                echo esc_html($post_type->labels->name);
                                echo '</label>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="chatmaxima_auto_sync"><?php _e('Auto Sync', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="chatmaxima_auto_sync" name="chatmaxima_auto_sync" value="1" <?php checked($auto_sync, true); ?> />
                                <?php _e('Automatically sync new posts when published', 'chatmaxima-ai-chatbot'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="chatmaxima-card">
                <h2><?php _e('Bulk Sync', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php _e('Sync all published content to the selected knowledge source.', 'chatmaxima-ai-chatbot'); ?></p>

                <?php
                // Count posts
                $total_posts = 0;
                foreach ((array)$sync_post_types as $pt)
                {
                    $count = wp_count_posts($pt);
                    if (isset($count->publish))
                    {
                        $total_posts += $count->publish;
                    }
                }
                ?>

                <p><strong><?php printf(__('Total posts to sync: %d', 'chatmaxima-ai-chatbot'), $total_posts); ?></strong></p>

                <div id="chatmaxima-sync-progress" class="chatmaxima-progress" style="display: none;">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 0%;"></div>
                    </div>
                    <p class="progress-text"><?php _e('Syncing...', 'chatmaxima-ai-chatbot'); ?> <span class="progress-count">0/0</span></p>
                </div>

                <p>
                    <button type="button" id="chatmaxima-sync-all-btn" class="button button-primary button-hero"><?php _e('Sync All Content', 'chatmaxima-ai-chatbot'); ?></button>
                    <span id="chatmaxima-sync-message" class="chatmaxima-message"></span>
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection()
    {
        check_ajax_referer('chatmaxima_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
        }

        $result = $this->api_client->test_connection();

        if (is_wp_error($result))
        {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Login
     */
    public function ajax_login()
    {
        check_ajax_referer('chatmaxima_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
        }

        $email = sanitize_email($_POST['email']);
        $password = $_POST['password'];

        if (empty($email) || empty($password))
        {
            wp_send_json_error(['message' => __('Email and password are required', 'chatmaxima-ai-chatbot')]);
        }

        $result = $this->api_client->login($email, $password);

        if (is_wp_error($result))
        {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Connected successfully', 'chatmaxima-ai-chatbot')]);
    }

    /**
     * AJAX: Logout
     */
    public function ajax_logout()
    {
        check_ajax_referer('chatmaxima_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
        }

        $this->api_client->clear_tokens();
        delete_option('chatmaxima_user_info');
        delete_option('chatmaxima_knowledge_source_alias');
        delete_option('chatmaxima_selected_team');

        wp_send_json_success(['message' => __('Disconnected successfully', 'chatmaxima-ai-chatbot')]);
    }

    /**
     * AJAX: Get knowledge sources
     */
    public function ajax_get_knowledge_sources()
    {
        check_ajax_referer('chatmaxima_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
        }

        $result = $this->api_client->list_knowledge_sources();

        if (is_wp_error($result))
        {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $selected = get_option('chatmaxima_knowledge_source_alias', '');

        wp_send_json_success([
            'knowledge_sources' => $result['knowledge_sources'],
            'selected' => $selected
        ]);
    }

    /**
     * AJAX: Create knowledge source
     */
    public function ajax_create_knowledge_source()
    {
        check_ajax_referer('chatmaxima_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
        }

        $name = sanitize_text_field($_POST['name']);
        $llm_type = sanitize_text_field($_POST['llm_type']);

        if (empty($name))
        {
            wp_send_json_error(['message' => __('Name is required', 'chatmaxima-ai-chatbot')]);
        }

        $result = $this->api_client->create_knowledge_source($name, $llm_type, 'web');

        if (is_wp_error($result))
        {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Auto-select the newly created knowledge source
        update_option('chatmaxima_knowledge_source_alias', $result['knowledge_source_alias']);

        wp_send_json_success([
            'message' => __('Knowledge source created successfully', 'chatmaxima-ai-chatbot'),
            'knowledge_source' => $result
        ]);
    }

    /**
     * AJAX: Sync posts
     */
    public function ajax_sync_posts()
    {
        check_ajax_referer('chatmaxima_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
        }

        $knowledge_source_alias = get_option('chatmaxima_knowledge_source_alias', '');

        if (empty($knowledge_source_alias))
        {
            wp_send_json_error(['message' => __('Please select a knowledge source first', 'chatmaxima-ai-chatbot')]);
        }

        $post_types = isset($_POST['post_types']) ? array_map('sanitize_text_field', $_POST['post_types']) : ['post', 'page'];
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = 10;

        // Get posts
        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC'
        ];

        $query = new WP_Query($args);
        $total = $query->found_posts;
        $posts = $query->posts;

        if (empty($posts))
        {
            wp_send_json_success([
                'complete' => true,
                'message' => __('Sync complete!', 'chatmaxima-ai-chatbot'),
                'synced' => $offset,
                'total' => $total
            ]);
        }

        // Collect URLs
        $urls = [];
        foreach ($posts as $post)
        {
            $urls[] = get_permalink($post->ID);
        }

        // Send to API
        $result = $this->api_client->add_training_urls($knowledge_source_alias, $urls);

        if (is_wp_error($result))
        {
            $error_msg = $result->get_error_message();
            // Add context about which knowledge source was used
            if (strpos($error_msg, 'not found') !== false)
            {
                $error_msg .= sprintf(' (alias: %s)', $knowledge_source_alias);
            }
            wp_send_json_error(['message' => $error_msg]);
        }

        $synced = $offset + count($posts);

        wp_send_json_success([
            'complete' => $synced >= $total,
            'synced' => $synced,
            'total' => $total,
            'next_offset' => $synced
        ]);
    }

    /**
     * AJAX: Save knowledge source selection
     */
    public function ajax_save_ks_selection()
    {
        check_ajax_referer('chatmaxima_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
        }

        $alias = sanitize_text_field($_POST['alias']);
        update_option('chatmaxima_knowledge_source_alias', $alias);

        wp_send_json_success(['message' => __('Knowledge source saved', 'chatmaxima-ai-chatbot')]);
    }

    /**
     * AJAX: Get teams
     */
    public function ajax_get_teams()
    {
        // Verify nonce - use wp_verify_nonce instead of check_ajax_referer to handle error gracefully
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatmaxima_admin_nonce'))
        {
            wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'chatmaxima-ai-chatbot')]);
            return;
        }

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
            return;
        }

        // Check if authenticated first
        if (!$this->api_client->is_authenticated())
        {
            wp_send_json_error(['message' => __('Not authenticated. Please login first.', 'chatmaxima-ai-chatbot')]);
            return;
        }

        $result = $this->api_client->list_teams();

        if (is_wp_error($result))
        {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $user_info = get_option('chatmaxima_user_info', []);
        $selected = isset($user_info['team_alias']) ? $user_info['team_alias'] : '';

        wp_send_json_success([
            'teams' => isset($result['teams']) ? $result['teams'] : [],
            'selected' => $selected
        ]);
    }

    /**
     * AJAX: Switch team
     */
    public function ajax_switch_team()
    {
        check_ajax_referer('chatmaxima_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
        }

        $team_alias = sanitize_text_field($_POST['team_alias']);

        if (empty($team_alias))
        {
            wp_send_json_error(['message' => __('Workspace alias is required', 'chatmaxima-ai-chatbot')]);
        }

        $result = $this->api_client->switch_team($team_alias);

        if (is_wp_error($result))
        {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Clear knowledge source selection when workspace changes
        delete_option('chatmaxima_knowledge_source_alias');

        wp_send_json_success([
            'message' => __('Workspace switched successfully', 'chatmaxima-ai-chatbot'),
            'team' => isset($result['team']) ? $result['team'] : null
        ]);
    }

    /**
     * AJAX: Get channels (web platform only for chatbot widget)
     */
    public function ajax_get_channels()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatmaxima_admin_nonce'))
        {
            wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'chatmaxima-ai-chatbot')]);
            return;
        }

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
            return;
        }

        // Check if authenticated first
        if (!$this->api_client->is_authenticated())
        {
            wp_send_json_error(['message' => __('Not authenticated. Please login first.', 'chatmaxima-ai-chatbot')]);
            return;
        }

        // Get platform filter from request (default to 'livechatwidget' for chatbot widget)
        $platform = isset($_POST['platform']) ? sanitize_text_field($_POST['platform']) : 'livechatwidget';

        $result = $this->api_client->list_channels($platform, 'Y');

        if (is_wp_error($result))
        {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $selected = get_option('chatmaxima_channel_alias', '');

        wp_send_json_success([
            'channels' => isset($result['channels']) ? $result['channels'] : [],
            'total_count' => isset($result['total_count']) ? $result['total_count'] : 0,
            'selected' => $selected
        ]);
    }

    /**
     * AJAX: Save channel selection
     */
    public function ajax_save_channel_selection()
    {
        check_ajax_referer('chatmaxima_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
            return;
        }

        $alias = isset($_POST['alias']) ? sanitize_text_field($_POST['alias']) : '';
        update_option('chatmaxima_channel_alias', $alias);

        wp_send_json_success(['message' => __('Channel saved', 'chatmaxima-ai-chatbot')]);
    }

    /**
     * AJAX: Install widget (save channel alias for frontend injection)
     */
    public function ajax_install_widget()
    {
        check_ajax_referer('chatmaxima_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
            return;
        }

        $alias = sanitize_text_field($_POST['alias']);

        if (empty($alias))
        {
            wp_send_json_error(['message' => __('Please select a channel first', 'chatmaxima-ai-chatbot')]);
            return;
        }

        // Save the installed channel alias
        update_option('chatmaxima_installed_channel', $alias);

        wp_send_json_success([
            'message' => __('Widget installed successfully! The chatbot is now active on your site.', 'chatmaxima-ai-chatbot'),
            'alias' => $alias
        ]);
    }

    /**
     * AJAX: Uninstall widget (remove channel alias)
     */
    public function ajax_uninstall_widget()
    {
        check_ajax_referer('chatmaxima_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
            return;
        }

        // Remove the installed channel
        delete_option('chatmaxima_installed_channel');

        wp_send_json_success([
            'message' => __('Widget uninstalled. The chatbot has been removed from your site.', 'chatmaxima-ai-chatbot')
        ]);
    }
}
