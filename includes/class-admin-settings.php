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
        add_action('wp_ajax_chatmaxima_save_sync_settings', [$this, 'ajax_save_sync_settings']);
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

        $sync_counts = $this->get_sync_counts();

        wp_localize_script('chatmaxima-admin', 'chatmaximaAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('chatmaxima_admin_nonce'),
            'isAuthenticated' => $this->api_client->is_authenticated(),
            'selectedKnowledgeSource' => get_option('chatmaxima_knowledge_source_alias', ''),
            'widgetInstalled' => get_option('chatmaxima_installed_channel', '') !== '',
            'totalPosts' => $sync_counts['total'],
            'syncedPosts' => $sync_counts['synced'],
            'strings' => [
                'connecting' => __('Connecting...', 'chatmaxima-ai-chatbot'),
                'connected' => __('Connected', 'chatmaxima-ai-chatbot'),
                'disconnected' => __('Disconnected', 'chatmaxima-ai-chatbot'),
                'error' => __('Error', 'chatmaxima-ai-chatbot'),
                'success' => __('Success', 'chatmaxima-ai-chatbot'),
                'syncing' => __('Syncing...', 'chatmaxima-ai-chatbot'),
                'syncComplete' => __('Sync complete!', 'chatmaxima-ai-chatbot'),
                'saved' => __('Saved', 'chatmaxima-ai-chatbot'),
                'saveFailed' => __('Could not save. Please try again.', 'chatmaxima-ai-chatbot'),
                /* translators: 1: number of synced items, 2: total published items */
                'syncedOf' => __('%1$s of %2$s published items synced', 'chatmaxima-ai-chatbot'),
                /* translators: 1: completed setup steps, 2: total setup steps */
                'stepsDone' => __('%1$s of %2$s steps done', 'chatmaxima-ai-chatbot'),
                'stepKnowledgeNone' => __('Choose where your content goes', 'chatmaxima-ai-chatbot'),
                'stepSyncNone' => __('Nothing synced yet', 'chatmaxima-ai-chatbot'),
                'stepWidgetNone' => __('Pick a web channel', 'chatmaxima-ai-chatbot'),
                'stepWidgetPicked' => __('Selected, not live yet', 'chatmaxima-ai-chatbot'),
                'stepWidgetLive' => __('Live on your site', 'chatmaxima-ai-chatbot'),
                'widgetLive' => __('Live on your site', 'chatmaxima-ai-chatbot'),
                'widgetOff' => __('Not shown to visitors', 'chatmaxima-ai-chatbot'),
                'enabling' => __('Turning on...', 'chatmaxima-ai-chatbot'),
                'disabling' => __('Turning off...', 'chatmaxima-ai-chatbot'),
                'selectChannelFirst' => __('Please select a channel first', 'chatmaxima-ai-chatbot'),
                'copied' => __('Copied', 'chatmaxima-ai-chatbot'),
                'previewWidget' => __('Preview in admin', 'chatmaxima-ai-chatbot'),
                'hidePreview' => __('Hide preview', 'chatmaxima-ai-chatbot')
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
        register_setting('chatmaxima_settings', 'chatmaxima_token_id', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('chatmaxima_settings', 'chatmaxima_theme_color', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_theme_color']]);
        register_setting('chatmaxima_settings', 'chatmaxima_social_media', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_text_array']]);
        register_setting('chatmaxima_settings', 'chatmaxima_knowledge_source_alias', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('chatmaxima_settings', 'chatmaxima_auto_sync', ['type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('chatmaxima_settings', 'chatmaxima_sync_post_types', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_post_types']]);

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
     * Sanitize the widget theme colour, falling back to the default when it is not a hex colour
     */
    public function sanitize_theme_color($value)
    {
        $color = sanitize_hex_color($value);
        if (empty($color))
        {
            return '#007bff';
        }
        return $color;
    }

    /**
     * Sanitize an array of text values keyed by platform (social media handles)
     */
    public function sanitize_text_array($value)
    {
        $clean = [];
        foreach ((array) $value as $key => $item)
        {
            $clean[sanitize_key($key)] = sanitize_text_field($item);
        }
        return $clean;
    }

    /**
     * Keep only registered post type names in the sync list
     */
    public function sanitize_post_types($value)
    {
        $clean = [];
        foreach ((array) $value as $post_type)
        {
            $post_type = sanitize_key($post_type);
            if (post_type_exists($post_type))
            {
                $clean[] = $post_type;
            }
        }
        return $clean;
    }

    /**
     * Published items in the selected post types, and how many of them are synced
     */
    private function get_sync_counts()
    {
        $post_types = (array) get_option('chatmaxima_sync_post_types', ['post', 'page']);
        $counts = ['total' => 0, 'synced' => 0];

        if (empty($post_types))
        {
            return $counts;
        }

        $base = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false
        ];

        $all = new WP_Query($base);
        $counts['total'] = (int) $all->found_posts;

        $synced_args = $base;
        $synced_args['meta_key'] = '_chatmaxima_sync_status'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one count query on the plugin's own admin page
        $synced_args['meta_value'] = 'synced'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above
        $synced = new WP_Query($synced_args);
        $counts['synced'] = (int) $synced->found_posts;

        return $counts;
    }

    /**
     * API section callback
     */
    public function api_section_callback()
    {
        echo '<p>' . esc_html__('Connect to ChatMaxima API to manage knowledge sources and sync content.', 'chatmaxima-ai-chatbot') . '</p>';
    }

    /**
     * Knowledge section callback
     */
    public function knowledge_section_callback()
    {
        echo '<p>' . esc_html__('Select or create a knowledge source to store your WordPress content.', 'chatmaxima-ai-chatbot') . '</p>';
    }

    /**
     * Sync section callback
     */
    public function sync_section_callback()
    {
        echo '<p>' . esc_html__('Sync your WordPress posts and pages to the selected knowledge source.', 'chatmaxima-ai-chatbot') . '</p>';
    }

    /**
     * Chatbot section callback
     */
    public function chatbot_section_callback()
    {
        echo '<p>' . esc_html__('Configure your chatbot appearance and behavior.', 'chatmaxima-ai-chatbot') . '</p>';
    }

    /**
     * Token ID field render
     */
    public function token_id_render()
    {
        $token_id = get_option('chatmaxima_token_id', '');
        ?>
        <input type="text" id="chatmaxima_token_id" name="chatmaxima_token_id" value="<?php echo esc_attr($token_id); ?>" class="regular-text" />
        <p class="description"><?php esc_html_e('Enter your ChatMaxima Token ID for the chatbot widget.', 'chatmaxima-ai-chatbot'); ?></p>
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
        <p class="description"><?php esc_html_e('Choose the theme color for your chatbot.', 'chatmaxima-ai-chatbot'); ?></p>
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
        echo '<p class="description">' . esc_html__('Enter your social media handles and contact information.', 'chatmaxima-ai-chatbot') . '</p>';
    }

    /**
     * Settings page
     */
    public function settings_page()
    {
        $is_authenticated = $this->api_client->is_authenticated();
        $user_info = get_option('chatmaxima_user_info', []);
        $auto_sync = get_option('chatmaxima_auto_sync', false);
        $sync_post_types = get_option('chatmaxima_sync_post_types', ['post', 'page']);
        $installed_channel = get_option('chatmaxima_installed_channel', '');
        $is_widget_installed = !empty($installed_channel);
        ?>
        <div class="wrap chatmaxima-settings-wrap cm-app">
            <div class="cm-header">
                <div class="cm-brand">
                    <span class="cm-brand-mark dashicons dashicons-format-chat" aria-hidden="true"></span>
                    <div>
                        <h1><?php esc_html_e('ChatMaxima AI Chatbot', 'chatmaxima-ai-chatbot'); ?></h1>
                        <p class="cm-subtitle"><?php esc_html_e('Answer your visitors from your own posts and pages.', 'chatmaxima-ai-chatbot'); ?></p>
                    </div>
                </div>

                <?php if ($is_authenticated): ?>
                <div class="cm-account">
                    <div id="chatmaxima-connection-status" class="chatmaxima-status connected">
                        <span class="status-indicator"></span>
                        <span class="status-text">
                            <?php
                            if (!empty($user_info))
                            {
                                /* translators: 1: account name, 2: account email */
                                printf(esc_html__('Connected as %1$s (%2$s)', 'chatmaxima-ai-chatbot'), esc_html($user_info['name']), esc_html($user_info['email']));
                            }
                            else
                            {
                                esc_html_e('Connected', 'chatmaxima-ai-chatbot');
                            }
                            ?>
                        </span>
                    </div>
                    <div class="cm-account-actions">
                        <button type="button" id="chatmaxima-test-btn" class="button"><?php esc_html_e('Test Connection', 'chatmaxima-ai-chatbot'); ?></button>
                        <button type="button" id="chatmaxima-logout-btn" class="button cm-button-quiet"><?php esc_html_e('Disconnect', 'chatmaxima-ai-chatbot'); ?></button>
                    </div>
                    <span id="chatmaxima-connection-message" class="chatmaxima-message"></span>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$is_authenticated): ?>
            <div class="cm-connect">
                <div class="chatmaxima-card cm-card cm-connect-form">
                    <h2><?php esc_html_e('Connect your ChatMaxima account', 'chatmaxima-ai-chatbot'); ?></h2>
                    <div id="chatmaxima-connection-status" class="chatmaxima-status disconnected">
                        <span class="status-indicator"></span>
                        <span class="status-text"><?php esc_html_e('Not connected', 'chatmaxima-ai-chatbot'); ?></span>
                    </div>
                    <div id="chatmaxima-login-form" class="chatmaxima-login-form">
                        <div class="cm-field">
                            <label for="chatmaxima_email"><?php esc_html_e('Email', 'chatmaxima-ai-chatbot'); ?></label>
                            <input type="email" id="chatmaxima_email" class="regular-text" autocomplete="username" />
                        </div>
                        <div class="cm-field">
                            <label for="chatmaxima_password"><?php esc_html_e('Password', 'chatmaxima-ai-chatbot'); ?></label>
                            <input type="password" id="chatmaxima_password" class="regular-text" autocomplete="current-password" />
                        </div>
                        <div class="cm-actions">
                            <button type="button" id="chatmaxima-login-btn" class="button button-primary button-large"><?php esc_html_e('Connect', 'chatmaxima-ai-chatbot'); ?></button>
                            <span id="chatmaxima-login-message" class="chatmaxima-message"></span>
                        </div>
                        <p class="description">
                            <?php esc_html_e('No account yet?', 'chatmaxima-ai-chatbot'); ?>
                            <a href="<?php echo esc_url('https://chatmaxima.com/'); ?>" target="_blank" rel="noopener"><?php esc_html_e('Create one on chatmaxima.com', 'chatmaxima-ai-chatbot'); ?></a>
                        </p>
                    </div>
                </div>

                <div class="chatmaxima-card cm-card cm-connect-benefits">
                    <h2><?php esc_html_e('What you get', 'chatmaxima-ai-chatbot'); ?></h2>
                    <ul class="cm-benefits">
                        <li>
                            <span class="dashicons dashicons-database" aria-hidden="true"></span>
                            <div><strong><?php esc_html_e('Your content as the chatbot\'s knowledge', 'chatmaxima-ai-chatbot'); ?></strong><span><?php esc_html_e('Sync posts and pages into a ChatMaxima knowledge source.', 'chatmaxima-ai-chatbot'); ?></span></div>
                        </li>
                        <li>
                            <span class="dashicons dashicons-update" aria-hidden="true"></span>
                            <div><strong><?php esc_html_e('Stays up to date', 'chatmaxima-ai-chatbot'); ?></strong><span><?php esc_html_e('Turn on Auto Sync and new posts are added when you publish them.', 'chatmaxima-ai-chatbot'); ?></span></div>
                        </li>
                        <li>
                            <span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
                            <div><strong><?php esc_html_e('A chat widget on your site', 'chatmaxima-ai-chatbot'); ?></strong><span><?php esc_html_e('Preview it in the admin first, then show it to visitors with one switch.', 'chatmaxima-ai-chatbot'); ?></span></div>
                        </li>
                    </ul>
                </div>
            </div>
            <?php else: ?>

            <ol class="cm-steps" id="chatmaxima-steps" aria-label="<?php esc_attr_e('Setup progress', 'chatmaxima-ai-chatbot'); ?>">
                <li class="cm-step is-done" data-step="connect">
                    <span class="cm-step-num" aria-hidden="true">1</span>
                    <div class="cm-step-body">
                        <span class="cm-step-title"><?php esc_html_e('Connect account', 'chatmaxima-ai-chatbot'); ?></span>
                        <span class="cm-step-sub" id="cm-step-connect-sub"><?php esc_html_e('Connected', 'chatmaxima-ai-chatbot'); ?></span>
                    </div>
                </li>
                <li class="cm-step" data-step="knowledge">
                    <a href="#cm-section-knowledge" class="cm-step-link">
                        <span class="cm-step-num" aria-hidden="true">2</span>
                        <div class="cm-step-body">
                            <span class="cm-step-title"><?php esc_html_e('Knowledge source', 'chatmaxima-ai-chatbot'); ?></span>
                            <span class="cm-step-sub" id="cm-step-knowledge-sub"><?php esc_html_e('Loading...', 'chatmaxima-ai-chatbot'); ?></span>
                        </div>
                    </a>
                </li>
                <li class="cm-step" data-step="sync">
                    <a href="#cm-section-sync" class="cm-step-link">
                        <span class="cm-step-num" aria-hidden="true">3</span>
                        <div class="cm-step-body">
                            <span class="cm-step-title"><?php esc_html_e('Sync content', 'chatmaxima-ai-chatbot'); ?></span>
                            <span class="cm-step-sub" id="cm-step-sync-sub"><?php esc_html_e('Loading...', 'chatmaxima-ai-chatbot'); ?></span>
                        </div>
                    </a>
                </li>
                <li class="cm-step" data-step="widget">
                    <a href="#cm-section-widget" class="cm-step-link">
                        <span class="cm-step-num" aria-hidden="true">4</span>
                        <div class="cm-step-body">
                            <span class="cm-step-title"><?php esc_html_e('Chat widget', 'chatmaxima-ai-chatbot'); ?></span>
                            <span class="cm-step-sub" id="cm-step-widget-sub"><?php esc_html_e('Loading...', 'chatmaxima-ai-chatbot'); ?></span>
                        </div>
                    </a>
                </li>
            </ol>

            <div class="cm-grid">
                <div class="cm-col-main">
                    <!-- Workspace and knowledge source -->
                    <section class="chatmaxima-card cm-card" id="cm-section-knowledge">
                        <div class="cm-card-head">
                            <h2><?php esc_html_e('Knowledge source', 'chatmaxima-ai-chatbot'); ?></h2>
                            <p><?php esc_html_e('Where your WordPress content is stored for the chatbot to answer from.', 'chatmaxima-ai-chatbot'); ?></p>
                        </div>

                        <div class="cm-field-row">
                            <div class="cm-field">
                                <label for="chatmaxima_team"><?php esc_html_e('Workspace', 'chatmaxima-ai-chatbot'); ?></label>
                                <div class="cm-inline">
                                    <select id="chatmaxima_team">
                                        <option value=""><?php esc_html_e('-- Loading workspaces... --', 'chatmaxima-ai-chatbot'); ?></option>
                                    </select>
                                    <button type="button" id="chatmaxima-refresh-teams-btn" class="button cm-icon-button" aria-label="<?php esc_attr_e('Refresh workspaces', 'chatmaxima-ai-chatbot'); ?>"><?php esc_html_e('Refresh', 'chatmaxima-ai-chatbot'); ?></button>
                                </div>
                                <span id="chatmaxima-team-message" class="chatmaxima-message cm-hint"></span>
                            </div>

                            <div class="cm-field">
                                <label for="chatmaxima_knowledge_source"><?php esc_html_e('Knowledge source', 'chatmaxima-ai-chatbot'); ?></label>
                                <div class="cm-inline">
                                    <select id="chatmaxima_knowledge_source">
                                        <option value=""><?php esc_html_e('-- Select Knowledge Source --', 'chatmaxima-ai-chatbot'); ?></option>
                                    </select>
                                    <button type="button" id="chatmaxima-refresh-ks-btn" class="button cm-icon-button" aria-label="<?php esc_attr_e('Refresh knowledge sources', 'chatmaxima-ai-chatbot'); ?>"><?php esc_html_e('Refresh', 'chatmaxima-ai-chatbot'); ?></button>
                                </div>
                            </div>
                        </div>

                        <div id="chatmaxima-ks-details" class="cm-stats" style="display: none;">
                            <div class="cm-stat">
                                <span class="cm-stat-label"><?php esc_html_e('Name', 'chatmaxima-ai-chatbot'); ?></span>
                                <span class="cm-stat-value cm-stat-name" id="ks-detail-name"></span>
                            </div>
                            <div class="cm-stat">
                                <span class="cm-stat-label"><?php esc_html_e('Documents', 'chatmaxima-ai-chatbot'); ?></span>
                                <span class="cm-stat-value" id="ks-detail-docs"></span>
                            </div>
                            <div class="cm-stat">
                                <span class="cm-stat-label"><?php esc_html_e('Storage', 'chatmaxima-ai-chatbot'); ?></span>
                                <span class="cm-stat-value" id="ks-detail-storage"></span>
                            </div>
                            <div class="cm-stat">
                                <span class="cm-stat-label"><?php esc_html_e('Type', 'chatmaxima-ai-chatbot'); ?></span>
                                <span class="cm-stat-value" id="ks-detail-type"></span>
                            </div>
                        </div>

                        <details class="cm-disclosure">
                            <summary><?php esc_html_e('Create a new knowledge source', 'chatmaxima-ai-chatbot'); ?></summary>
                            <div class="cm-field-row">
                                <div class="cm-field">
                                    <label for="chatmaxima_new_ks_name"><?php esc_html_e('Name', 'chatmaxima-ai-chatbot'); ?></label>
                                    <input type="text" id="chatmaxima_new_ks_name" placeholder="<?php esc_attr_e('e.g., My WordPress Site', 'chatmaxima-ai-chatbot'); ?>" />
                                </div>
                                <div class="cm-field cm-field-narrow">
                                    <label for="chatmaxima_new_ks_llm"><?php esc_html_e('AI model', 'chatmaxima-ai-chatbot'); ?></label>
                                    <select id="chatmaxima_new_ks_llm">
                                        <option value="openai">OpenAI</option>
                                        <option value="claude">Claude</option>
                                        <option value="gemini">Gemini</option>
                                    </select>
                                </div>
                            </div>
                            <div class="cm-actions">
                                <button type="button" id="chatmaxima-create-ks-btn" class="button button-primary"><?php esc_html_e('Create Knowledge Source', 'chatmaxima-ai-chatbot'); ?></button>
                                <span id="chatmaxima-create-ks-message" class="chatmaxima-message"></span>
                            </div>
                        </details>
                    </section>

                    <!-- Content sync -->
                    <section class="chatmaxima-card cm-card" id="cm-section-sync">
                        <div class="cm-card-head">
                            <h2><?php esc_html_e('Content sync', 'chatmaxima-ai-chatbot'); ?></h2>
                            <p><?php esc_html_e('Choose what to send to the knowledge source. Changes save automatically.', 'chatmaxima-ai-chatbot'); ?></p>
                            <span id="chatmaxima-sync-settings-message" class="chatmaxima-message cm-saved" aria-live="polite"></span>
                        </div>

                        <div class="cm-field">
                            <span class="cm-label"><?php esc_html_e('What to sync', 'chatmaxima-ai-chatbot'); ?></span>
                            <div class="cm-chips">
                                <?php
                                $post_types = get_post_types(['public' => true], 'objects');
                                foreach ($post_types as $post_type)
                                {
                                    if ($post_type->name === 'attachment')
                                    {
                                        continue;
                                    }
                                    echo '<label class="cm-chip">';
                                    echo '<input type="checkbox" name="chatmaxima_sync_post_types[]" value="' . esc_attr($post_type->name) . '" ' . checked(in_array($post_type->name, (array) $sync_post_types, true), true, false) . ' class="chatmaxima-sync-post-type" />';
                                    echo '<span>' . esc_html($post_type->labels->name) . '</span>';
                                    echo '</label>';
                                }
                                ?>
                            </div>
                        </div>

                        <label class="cm-switch-row" for="chatmaxima_auto_sync">
                            <span class="cm-switch">
                                <input type="checkbox" id="chatmaxima_auto_sync" name="chatmaxima_auto_sync" value="1" role="switch" <?php checked($auto_sync, true); ?> />
                                <span class="cm-switch-track" aria-hidden="true"></span>
                            </span>
                            <span class="cm-switch-text">
                                <strong><?php esc_html_e('Auto Sync', 'chatmaxima-ai-chatbot'); ?></strong>
                                <span><?php esc_html_e('Add new and updated posts automatically when you publish them.', 'chatmaxima-ai-chatbot'); ?></span>
                            </span>
                        </label>

                        <div class="cm-sync-panel">
                            <div class="cm-sync-summary">
                                <div class="cm-meter" aria-hidden="true"><span class="cm-meter-fill" id="cm-sync-meter"></span></div>
                                <span class="cm-sync-count" id="cm-sync-count"></span>
                            </div>
                            <div id="chatmaxima-sync-progress" class="chatmaxima-progress" style="display: none;">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 0%;"></div>
                                </div>
                                <p class="progress-text"><?php esc_html_e('Syncing...', 'chatmaxima-ai-chatbot'); ?> <span class="progress-count">0/0</span></p>
                            </div>
                            <div class="cm-actions">
                                <button type="button" id="chatmaxima-sync-all-btn" class="button button-primary"><?php esc_html_e('Sync All Content', 'chatmaxima-ai-chatbot'); ?></button>
                                <span id="chatmaxima-sync-message" class="chatmaxima-message"></span>
                            </div>
                        </div>
                    </section>
                </div>

                <aside class="cm-col-side">
                    <!-- Chat widget -->
                    <section class="chatmaxima-card cm-card" id="cm-section-widget">
                        <div class="cm-card-head">
                            <h2><?php esc_html_e('Chat widget', 'chatmaxima-ai-chatbot'); ?></h2>
                            <p><?php esc_html_e('The chat bubble your visitors see.', 'chatmaxima-ai-chatbot'); ?></p>
                        </div>

                        <?php
                        $widget_state_class = 'is-off';
                        if ($is_widget_installed)
                        {
                            $widget_state_class = 'is-live';
                        }
                        ?>
                        <div id="chatmaxima-widget-state" class="cm-widget-state <?php echo esc_attr($widget_state_class); ?>">
                            <span class="cm-widget-dot" aria-hidden="true"></span>
                            <span id="chatmaxima-widget-state-text">
                                <?php
                                if ($is_widget_installed)
                                {
                                    esc_html_e('Live on your site', 'chatmaxima-ai-chatbot');
                                }
                                else
                                {
                                    esc_html_e('Not shown to visitors', 'chatmaxima-ai-chatbot');
                                }
                                ?>
                            </span>
                        </div>

                        <div class="cm-field">
                            <label for="chatmaxima_channel"><?php esc_html_e('Web channel', 'chatmaxima-ai-chatbot'); ?></label>
                            <div class="cm-inline">
                                <select id="chatmaxima_channel">
                                    <option value=""><?php esc_html_e('-- Loading channels... --', 'chatmaxima-ai-chatbot'); ?></option>
                                </select>
                                <button type="button" id="chatmaxima-refresh-channels-btn" class="button cm-icon-button" aria-label="<?php esc_attr_e('Refresh channels', 'chatmaxima-ai-chatbot'); ?>"><?php esc_html_e('Refresh', 'chatmaxima-ai-chatbot'); ?></button>
                            </div>
                            <span id="chatmaxima-channel-message" class="chatmaxima-message cm-hint"></span>
                        </div>

                        <div id="chatmaxima-widget-script" style="display: none;">
                            <label class="cm-switch-row cm-switch-row-strong" for="chatmaxima-live-toggle">
                                <span class="cm-switch">
                                    <input type="checkbox" id="chatmaxima-live-toggle" role="switch" <?php checked($is_widget_installed, true); ?> />
                                    <span class="cm-switch-track" aria-hidden="true"></span>
                                </span>
                                <span class="cm-switch-text">
                                    <strong><?php esc_html_e('Show on your site', 'chatmaxima-ai-chatbot'); ?></strong>
                                    <span><?php esc_html_e('Adds the chat bubble to every page for all visitors.', 'chatmaxima-ai-chatbot'); ?></span>
                                </span>
                            </label>

                            <div class="cm-actions">
                                <button type="button" id="chatmaxima-preview-widget-btn" class="button"><?php esc_html_e('Preview in admin', 'chatmaxima-ai-chatbot'); ?></button>
                                <span class="cm-hint"><?php esc_html_e('Only you see the preview.', 'chatmaxima-ai-chatbot'); ?></span>
                            </div>
                            <span id="chatmaxima-install-message" class="chatmaxima-message cm-block-message" aria-live="polite"></span>

                            <details class="cm-disclosure">
                                <summary><?php esc_html_e('Embed code for other sites', 'chatmaxima-ai-chatbot'); ?></summary>
                                <p class="description"><?php esc_html_e('Not needed on this site: the switch above adds it for you.', 'chatmaxima-ai-chatbot'); ?></p>
                                <div class="cm-code">
                                    <pre id="chatmaxima-script-code"></pre>
                                    <button type="button" id="chatmaxima-copy-script-btn" class="button button-small"><?php esc_html_e('Copy', 'chatmaxima-ai-chatbot'); ?></button>
                                </div>
                            </details>
                        </div>
                    </section>

                    <section class="chatmaxima-card cm-card cm-help">
                        <h2><?php esc_html_e('Need help?', 'chatmaxima-ai-chatbot'); ?></h2>
                        <ul>
                            <li><a href="<?php echo esc_url('https://docs.chatmaxima.com/'); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-book" aria-hidden="true"></span><?php esc_html_e('Documentation', 'chatmaxima-ai-chatbot'); ?></a></li>
                            <li><a href="<?php echo esc_url('https://chatmaxima.com/dashboard/'); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-external" aria-hidden="true"></span><?php esc_html_e('Open ChatMaxima dashboard', 'chatmaxima-ai-chatbot'); ?></a></li>
                            <li><a href="<?php echo esc_url('https://chatmaxima.com/contact/'); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-sos" aria-hidden="true"></span><?php esc_html_e('Contact support', 'chatmaxima-ai-chatbot'); ?></a></li>
                        </ul>
                    </section>
                </aside>
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
            <h1><?php esc_html_e('Knowledge Sources', 'chatmaxima-ai-chatbot'); ?></h1>

            <?php if (!$is_authenticated): ?>
            <div class="chatmaxima-card">
                <h2><?php esc_html_e('Not Connected', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php esc_html_e('Please connect to ChatMaxima first.', 'chatmaxima-ai-chatbot'); ?></p>
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=chatmaxima-settings')); ?>" class="button button-primary"><?php esc_html_e('Go to Settings', 'chatmaxima-ai-chatbot'); ?></a></p>
            </div>
            <?php else: ?>
            <div class="chatmaxima-card">
                <h2><?php esc_html_e('Your Knowledge Sources', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php esc_html_e('Manage your knowledge sources for AI training.', 'chatmaxima-ai-chatbot'); ?></p>

                <table class="form-table">
                    <tr>
                        <th><label for="chatmaxima_knowledge_source"><?php esc_html_e('Select Knowledge Source', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <select id="chatmaxima_knowledge_source" class="regular-text">
                                <option value=""><?php esc_html_e('-- Select Knowledge Source --', 'chatmaxima-ai-chatbot'); ?></option>
                            </select>
                            <button type="button" id="chatmaxima-refresh-ks-btn" class="button"><?php esc_html_e('Refresh', 'chatmaxima-ai-chatbot'); ?></button>
                        </td>
                    </tr>
                </table>

                <div id="chatmaxima-ks-details" class="chatmaxima-ks-details" style="display: none; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                    <h3><?php esc_html_e('Knowledge Source Details', 'chatmaxima-ai-chatbot'); ?></h3>
                    <p><strong><?php esc_html_e('Name:', 'chatmaxima-ai-chatbot'); ?></strong> <span id="ks-detail-name">-</span></p>
                    <p><strong><?php esc_html_e('Documents:', 'chatmaxima-ai-chatbot'); ?></strong> <span id="ks-detail-docs">-</span></p>
                    <p><strong><?php esc_html_e('Storage:', 'chatmaxima-ai-chatbot'); ?></strong> <span id="ks-detail-storage">-</span></p>
                    <p><strong><?php esc_html_e('Type:', 'chatmaxima-ai-chatbot'); ?></strong> <span id="ks-detail-type">-</span></p>
                </div>
            </div>

            <div class="chatmaxima-card">
                <h2><?php esc_html_e('Create New Knowledge Source', 'chatmaxima-ai-chatbot'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="chatmaxima_new_ks_name"><?php esc_html_e('Name', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td><input type="text" id="chatmaxima_new_ks_name" class="regular-text" placeholder="<?php esc_attr_e('e.g., My WordPress Site', 'chatmaxima-ai-chatbot'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="chatmaxima_new_ks_llm"><?php esc_html_e('LLM Type', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <select id="chatmaxima_new_ks_llm">
                                <option value="openai">OpenAI</option>
                                <option value="claude">Claude</option>
                                <option value="gemini">Gemini</option>
                            </select>
                            <p class="description"><?php esc_html_e('Select the AI model to use for this knowledge source.', 'chatmaxima-ai-chatbot'); ?></p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="button" id="chatmaxima-create-ks-btn" class="button button-primary"><?php esc_html_e('Create Knowledge Source', 'chatmaxima-ai-chatbot'); ?></button>
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
            <h1><?php esc_html_e('Content Sync', 'chatmaxima-ai-chatbot'); ?></h1>

            <?php if (!$is_authenticated): ?>
            <div class="chatmaxima-card">
                <h2><?php esc_html_e('Not Connected', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php esc_html_e('Please connect to ChatMaxima first.', 'chatmaxima-ai-chatbot'); ?></p>
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=chatmaxima-settings')); ?>" class="button button-primary"><?php esc_html_e('Go to Settings', 'chatmaxima-ai-chatbot'); ?></a></p>
            </div>
            <?php elseif (empty($knowledge_source_alias)): ?>
            <div class="chatmaxima-card">
                <h2><?php esc_html_e('No Knowledge Source Selected', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php esc_html_e('Please select a knowledge source first.', 'chatmaxima-ai-chatbot'); ?></p>
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=chatmaxima-knowledge-sources')); ?>" class="button button-primary"><?php esc_html_e('Select Knowledge Source', 'chatmaxima-ai-chatbot'); ?></a></p>
            </div>
            <?php else: ?>
            <div class="chatmaxima-card">
                <h2><?php esc_html_e('Sync Settings', 'chatmaxima-ai-chatbot'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th><label><?php esc_html_e('Knowledge Source', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <select id="chatmaxima_knowledge_source" class="regular-text">
                                <option value=""><?php esc_html_e('-- Select Knowledge Source --', 'chatmaxima-ai-chatbot'); ?></option>
                            </select>
                            <button type="button" id="chatmaxima-refresh-ks-btn" class="button"><?php esc_html_e('Refresh', 'chatmaxima-ai-chatbot'); ?></button>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Post Types to Sync', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <?php
                            $post_types = get_post_types(['public' => true], 'objects');
                            foreach ($post_types as $post_type)
                            {
                                if ($post_type->name === 'attachment') continue;
                                echo '<label style="display: block; margin-bottom: 5px;">';
                                echo '<input type="checkbox" name="chatmaxima_sync_post_types[]" value="' . esc_attr($post_type->name) . '" ' . checked(in_array($post_type->name, (array) $sync_post_types, true), true, false) . ' class="chatmaxima-sync-post-type" /> ';
                                echo esc_html($post_type->labels->name);
                                echo '</label>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="chatmaxima_auto_sync"><?php esc_html_e('Auto Sync', 'chatmaxima-ai-chatbot'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="chatmaxima_auto_sync" name="chatmaxima_auto_sync" value="1" <?php checked($auto_sync, true); ?> />
                                <?php esc_html_e('Automatically sync new posts when published', 'chatmaxima-ai-chatbot'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="chatmaxima-card">
                <h2><?php esc_html_e('Bulk Sync', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php esc_html_e('Sync all published content to the selected knowledge source.', 'chatmaxima-ai-chatbot'); ?></p>

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

                <?php /* translators: %d: number of posts that will be synced */ ?>
                <p><strong><?php printf(esc_html__('Total posts to sync: %d', 'chatmaxima-ai-chatbot'), absint($total_posts)); ?></strong></p>

                <div id="chatmaxima-sync-progress" class="chatmaxima-progress" style="display: none;">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 0%;"></div>
                    </div>
                    <p class="progress-text"><?php esc_html_e('Syncing...', 'chatmaxima-ai-chatbot'); ?> <span class="progress-count">0/0</span></p>
                </div>

                <p>
                    <button type="button" id="chatmaxima-sync-all-btn" class="button button-primary button-hero"><?php esc_html_e('Sync All Content', 'chatmaxima-ai-chatbot'); ?></button>
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

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? wp_unslash($_POST['password']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password is sent to the API as typed, sanitizing would alter it

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

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $llm_type = isset($_POST['llm_type']) ? sanitize_text_field(wp_unslash($_POST['llm_type'])) : '';

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

        $post_types = isset($_POST['post_types']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['post_types'])) : ['post', 'page'];
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
            foreach ($posts as $post)
            {
                // Same per-post record as a single sync, so the meta box shows the error
                update_post_meta($post->ID, '_chatmaxima_sync_status', 'error');
                update_post_meta($post->ID, '_chatmaxima_sync_error', $error_msg);
            }
            // Add context about which knowledge source was used
            if (strpos($error_msg, 'not found') !== false)
            {
                $error_msg .= sprintf(' (alias: %s)', $knowledge_source_alias);
            }
            wp_send_json_error(['message' => $error_msg]);
        }

        $sync_date = current_time('mysql');
        foreach ($posts as $post)
        {
            // Record each post as synced, as a single or bulk-action sync does, so the Posts column and meta box agree
            update_post_meta($post->ID, '_chatmaxima_sync_status', 'synced');
            update_post_meta($post->ID, '_chatmaxima_sync_date', $sync_date);
            delete_post_meta($post->ID, '_chatmaxima_sync_error');
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

        $alias = isset($_POST['alias']) ? sanitize_text_field(wp_unslash($_POST['alias'])) : '';
        update_option('chatmaxima_knowledge_source_alias', $alias);

        wp_send_json_success(['message' => __('Knowledge source saved', 'chatmaxima-ai-chatbot')]);
    }

    /**
     * AJAX: Get teams
     */
    public function ajax_get_teams()
    {
        // Verify nonce - use wp_verify_nonce instead of check_ajax_referer to handle error gracefully
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'chatmaxima_admin_nonce'))
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

        $team_alias = isset($_POST['team_alias']) ? sanitize_text_field(wp_unslash($_POST['team_alias'])) : '';

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
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'chatmaxima_admin_nonce'))
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
        $platform = isset($_POST['platform']) ? sanitize_text_field(wp_unslash($_POST['platform'])) : 'livechatwidget';

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

        $alias = isset($_POST['alias']) ? sanitize_text_field(wp_unslash($_POST['alias'])) : '';
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

        $alias = isset($_POST['alias']) ? sanitize_text_field(wp_unslash($_POST['alias'])) : '';

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

    /**
     * AJAX: Save the content sync options (post types and auto sync) as soon as they change
     */
    public function ajax_save_sync_settings()
    {
        check_ajax_referer('chatmaxima_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
            return;
        }

        $post_types = isset($_POST['post_types']) ? $this->sanitize_post_types(wp_unslash((array) $_POST['post_types'])) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_post_types() runs sanitize_key and post_type_exists on each value
        $auto_sync = isset($_POST['auto_sync']) && rest_sanitize_boolean(sanitize_text_field(wp_unslash($_POST['auto_sync'])));

        update_option('chatmaxima_sync_post_types', $post_types);
        update_option('chatmaxima_auto_sync', $auto_sync);

        $counts = $this->get_sync_counts();

        wp_send_json_success([
            'message' => __('Saved', 'chatmaxima-ai-chatbot'),
            'total' => $counts['total'],
            'synced' => $counts['synced']
        ]);
    }
}
