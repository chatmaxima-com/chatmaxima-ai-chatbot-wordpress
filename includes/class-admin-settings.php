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
    private $wizard;

    public function __construct($api_client)
    {
        $this->api_client = $api_client;
        $this->wizard = new ChatMaxima_Setup_Wizard($api_client); // owns the plugin's admin page

        add_action('admin_menu', [$this, 'add_admin_menu']);

        // AJAX handlers
        add_action('wp_ajax_chatmaxima_login', [$this, 'ajax_login']);
        add_action('wp_ajax_chatmaxima_register', [$this, 'ajax_register']);
        add_action('wp_ajax_chatmaxima_logout', [$this, 'ajax_logout']);
        add_action('wp_ajax_chatmaxima_get_knowledge_sources', [$this, 'ajax_get_knowledge_sources']);
        add_action('wp_ajax_chatmaxima_create_knowledge_source', [$this, 'ajax_create_knowledge_source']);
        add_action('wp_ajax_chatmaxima_sync_posts', [$this, 'ajax_sync_posts']);
        add_action('wp_ajax_chatmaxima_save_ks_selection', [$this, 'ajax_save_ks_selection']);
        add_action('wp_ajax_chatmaxima_get_channels', [$this, 'ajax_get_channels']);
        add_action('wp_ajax_chatmaxima_install_widget', [$this, 'ajax_install_widget']);
        add_action('wp_ajax_chatmaxima_uninstall_widget', [$this, 'ajax_uninstall_widget']);
        add_action('wp_ajax_chatmaxima_save_sync_settings', [$this, 'ajax_save_sync_settings']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        // One page: the setup wizard until setup is done, then Home (ChatMaxima_Setup_Wizard). The old Settings,
        // Knowledge Sources and Content Sync pages are folded into it.
        add_menu_page(
            __('ChatMaxima AI Chatbot', 'chatmaxima-ai-chatbot'),
            __('ChatMaxima', 'chatmaxima-ai-chatbot'),
            'manage_options',
            'chatmaxima-settings',
            [$this->wizard, 'render_page'],
            'dashicons-format-chat',
            30
        );

        $first_label = __('Setup', 'chatmaxima-ai-chatbot');
        if ($this->wizard->is_setup_complete())
        {
            $first_label = __('Home', 'chatmaxima-ai-chatbot');
        }
        add_submenu_page(
            'chatmaxima-settings',
            __('ChatMaxima AI Chatbot', 'chatmaxima-ai-chatbot'),
            $first_label,
            'manage_options',
            'chatmaxima-settings',
            [$this->wizard, 'render_page']
        );

        add_submenu_page(
            'chatmaxima-settings',
            __('Help', 'chatmaxima-ai-chatbot'),
            __('Help', 'chatmaxima-ai-chatbot'),
            'manage_options',
            'chatmaxima-help',
            [$this->wizard, 'render_help']
        );
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
            if ($post_type === 'attachment')
            {
                continue;
            }
            $object = get_post_type_object($post_type);
            if ($object && $object->public) // only types visitors can read, so no revisions, blocks or private types
            {
                $clean[] = $post_type;
            }
        }
        return array_values(array_unique($clean));
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
            'has_password' => false, // same rule as the sync: password-protected items are never sent
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

        $previous_email = $this->stored_account_email(); // read before the sign-in replaces it

        $result = $this->api_client->login($email, $password);

        if (is_wp_error($result))
        {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        }

        $this->wizard->reconcile_account($previous_email); // same account as before: keep the setup; another account: start fresh

        wp_send_json_success(['message' => __('Connected successfully', 'chatmaxima-ai-chatbot')]);
    }

    /**
     * AJAX: Create a ChatMaxima account from the plugin and connect it
     */
    public function ajax_register()
    {
        check_ajax_referer('chatmaxima_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
        }

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? wp_unslash($_POST['password']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password is sent to the API as typed, sanitizing would alter it

        if ($name === '' || $email === '' || $password === '')
        {
            wp_send_json_error(['message' => __('Name, email and password are required', 'chatmaxima-ai-chatbot')]);
        }

        if (!is_email($email))
        {
            wp_send_json_error(['message' => __('Please enter a valid email address', 'chatmaxima-ai-chatbot')]);
        }

        // Same rules the ChatMaxima API applies, checked here so the user gets the message without a round trip
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password))
        {
            wp_send_json_error(['message' => __('Use at least 8 characters with both letters and numbers', 'chatmaxima-ai-chatbot')]);
        }

        $workspace_name = isset($_POST['workspace_name']) ? sanitize_text_field(wp_unslash($_POST['workspace_name'])) : '';
        if ($workspace_name === '')
        {
            $workspace_name = get_bloginfo('name'); // Default to the site title
        }

        $result = $this->api_client->register($name, $email, $password, $workspace_name);

        if (is_wp_error($result))
        {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        }

        if (is_array($result) && !empty($result['verification_required']))
        {
            wp_send_json_success(['verification_required' => true, 'email' => $email]);
        }

        $this->wizard->reconcile_account('', true); // a new account never owns an existing setup

        wp_send_json_success(['message' => __('Account created and connected', 'chatmaxima-ai-chatbot')]);
    }

    /**
     * The email of the ChatMaxima account currently stored, kept even after a session expires
     */
    private function stored_account_email()
    {
        $user_info = get_option('chatmaxima_user_info', []);
        if (is_array($user_info) && !empty($user_info['email']))
        {
            return (string) $user_info['email'];
        }
        return '';
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

        // Remember who was signed in: the setup, knowledge source and channel are kept, and the next sign-in with the
        // same account picks them up again (ChatMaxima_Setup_Wizard::reconcile_account); a different account starts fresh
        $user_info = get_option('chatmaxima_user_info', []);
        if (is_array($user_info) && !empty($user_info['email']))
        {
            update_option('chatmaxima_last_account', ['email' => $user_info['email'], 'team_alias' => isset($user_info['team_alias']) ? $user_info['team_alias'] : ''], false);
        }
        $this->api_client->clear_tokens();
        delete_option('chatmaxima_user_info');

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

        $post_types = isset($_POST['post_types']) ? $this->sanitize_post_types(wp_unslash((array) $_POST['post_types'])) : ['post', 'page']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_post_types() keeps registered public types only
        if (count($post_types) === 0)
        {
            wp_send_json_error(['message' => __('Pick at least one content type.', 'chatmaxima-ai-chatbot')]);
        }
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = 10;

        // Published, public, password-free items only (same rule as ChatMaxima_Content_Sync::can_sync_post), excluded ones skipped
        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'], // newest first: on the Free plan the page cap keeps the most recent posts
            'order' => 'DESC',
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the exclude flag is per post
                'relation' => 'OR',
                ['key' => '_chatmaxima_exclude_sync', 'compare' => 'NOT EXISTS'],
                ['key' => '_chatmaxima_exclude_sync', 'value' => '1', 'compare' => '!=']
            ]
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

        if (is_wp_error($result) && $result->get_error_code() === 'PLAN_PAGE_LIMIT') // the Free plan's page cap was already full
        {
            ChatMaxima_Content_Sync::record_plan_limit($result->get_error_data());
            foreach ($posts as $post)
            {
                ChatMaxima_Content_Sync::mark_limited($post->ID);
            }
            $this->send_limit_reached($offset, $total);
        }

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

        ChatMaxima_Content_Sync::record_plan_limit($result['plan_limit'] ?? null);
        $skipped_urls = [];
        if (is_array($result) && !empty($result['skipped_urls']))
        {
            $skipped_urls = (array) $result['skipped_urls'];
        }

        $sync_date = current_time('mysql');
        $accepted_posts = [];
        foreach ($posts as $post)
        {
            if (in_array(get_permalink($post->ID), $skipped_urls, true)) // did not fit in the Free plan's page cap
            {
                ChatMaxima_Content_Sync::mark_limited($post->ID);
                continue;
            }
            // Record each post as synced, as a single or bulk-action sync does, so the Posts column and meta box agree
            update_post_meta($post->ID, '_chatmaxima_sync_status', 'synced');
            update_post_meta($post->ID, '_chatmaxima_sync_date', $sync_date);
            delete_post_meta($post->ID, '_chatmaxima_sync_error');
            $accepted_posts[] = $post;
        }
        $posts = $accepted_posts;

        if (count($skipped_urls) > 0)
        {
            $this->send_limit_reached($offset + count($posts), $total);
        }

        $synced = $offset + count($posts);

        $items = []; // what this batch sent, for the setup wizard's live list
        foreach ($posts as $post)
        {
            $post_type_object = get_post_type_object($post->post_type);
            $type_label = $post->post_type;
            if ($post_type_object)
            {
                $type_label = $post_type_object->labels->singular_name;
            }
            $items[] = ['title' => html_entity_decode(get_the_title($post->ID), ENT_QUOTES, 'UTF-8'), 'type' => $type_label];
        }

        update_option('chatmaxima_last_full_sync', ['date' => $sync_date, 'synced' => $synced, 'total' => $total], false);

        wp_send_json_success([
            'complete' => $synced >= $total,
            'synced' => $synced,
            'total' => $total,
            'next_offset' => $synced,
            'items' => $items
        ]);
    }

    /**
     * End a full sync at the Free plan's page cap: the newest posts are in, the rest need a higher plan
     */
    private function send_limit_reached($synced, $total)
    {
        $limit = ChatMaxima_Content_Sync::plan_limit_value();
        update_option('chatmaxima_last_full_sync', ['date' => current_time('mysql'), 'synced' => $synced, 'total' => $total, 'limit_reached' => true], false);
        wp_send_json_success([
            'complete' => true,
            'limit_reached' => true,
            'limit' => $limit,
            'upgrade_url' => ChatMaxima_Content_Sync::upgrade_url(),
            /* translators: 1: number of pages the Free plan keeps, 2: number of posts and pages on the site */
            'message' => sprintf(__('Your %1$d most recent posts and pages are synced. The ChatMaxima Free plan keeps up to %1$d, so the other %2$d were not synced. Upgrade your plan to sync them all.', 'chatmaxima-ai-chatbot'), $limit, max(0, $total - $synced)),
            'synced' => $synced,
            'total' => $total,
            'items' => []
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
