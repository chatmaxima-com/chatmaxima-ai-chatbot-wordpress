<?php
/**
 * ChatMaxima Content Sync
 *
 * Handles automatic syncing of WordPress content to ChatMaxima knowledge sources
 *
 * @package ChatMaxima AI Chatbot
 */

// Prevent direct access
if (!defined('ABSPATH'))
{
    exit;
}

class ChatMaxima_Content_Sync
{
    private $api_client;

    public function __construct($api_client)
    {
        $this->api_client = $api_client;

        // Hook into post publish/update actions
        add_action('publish_post', [$this, 'on_post_publish'], 10, 2);
        add_action('publish_page', [$this, 'on_post_publish'], 10, 2);

        // Custom post types
        add_action('wp_insert_post', [$this, 'on_post_save'], 10, 3);

        // Add meta box to post editor
        add_action('add_meta_boxes', [$this, 'add_sync_meta_box']);
        add_action('save_post', [$this, 'save_sync_meta'], 10, 2);

        // Add bulk action
        add_filter('bulk_actions-edit-post', [$this, 'register_bulk_action']);
        add_filter('bulk_actions-edit-page', [$this, 'register_bulk_action']);
        add_filter('handle_bulk_actions-edit-post', [$this, 'handle_bulk_action'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [$this, 'handle_bulk_action'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_action_admin_notice']);

        // Add column to posts list
        add_filter('manage_posts_columns', [$this, 'add_sync_column']);
        add_filter('manage_pages_columns', [$this, 'add_sync_column']);
        add_action('manage_posts_custom_column', [$this, 'sync_column_content'], 10, 2);
        add_action('manage_pages_custom_column', [$this, 'sync_column_content'], 10, 2);

        // Meta box and posts-list column assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_editor_assets']);
    }

    /**
     * Enqueue the sync meta box script and the status styles on the post editor and posts list
     */
    public function enqueue_editor_assets($hook)
    {
        if (!in_array($hook, ['post.php', 'post-new.php', 'edit.php'], true))
        {
            return;
        }

        wp_enqueue_style('chatmaxima-meta-box', CHATMAXIMA_PLUGIN_URL . 'assets/meta-box.css', [], CHATMAXIMA_VERSION);

        if ($hook === 'edit.php')
        {
            return; // the posts list only needs the status colours
        }

        wp_enqueue_script('chatmaxima-meta-box', CHATMAXIMA_PLUGIN_URL . 'assets/meta-box.js', ['jquery'], CHATMAXIMA_VERSION, true);
        wp_localize_script('chatmaxima-meta-box', 'chatmaximaMetaBox', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('chatmaxima_sync_single'),
            'strings' => [
                'syncing' => __('Syncing...', 'chatmaxima-ai-chatbot'),
                'synced' => __('Synced!', 'chatmaxima-ai-chatbot'),
                'syncNow' => __('Sync Now', 'chatmaxima-ai-chatbot'),
                'failed' => __('Sync failed. Please try again.', 'chatmaxima-ai-chatbot'),
            ],
        ]);
    }

    /**
     * Auto-sync on post publish
     */
    public function on_post_publish($post_id, $post)
    {
        $auto_sync = get_option('chatmaxima_auto_sync', false);

        if (!$auto_sync)
        {
            return;
        }

        $this->sync_post($post_id); // can_sync_post() inside applies the post type selection and the exclude flag
    }

    /**
     * The one rule for what may be sent to ChatMaxima: a published, public, password-free item of a selected post
     * type that is not excluded. Every sync path (auto, single, bulk, sync all) goes through this.
     */
    public function can_sync_post($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || $post->post_password !== '')
        {
            return false;
        }
        if (function_exists('is_post_publicly_viewable')) // WordPress 5.7+
        {
            if (!is_post_publicly_viewable($post))
            {
                return false;
            }
        }
        else
        {
            $post_type_object = get_post_type_object($post->post_type);
            if (!$post_type_object || !$post_type_object->public)
            {
                return false;
            }
        }
        $sync_post_types = (array) get_option('chatmaxima_sync_post_types', ['post', 'page']);
        if (!in_array($post->post_type, $sync_post_types, true))
        {
            return false;
        }
        if (get_post_meta($post_id, '_chatmaxima_exclude_sync', true) === '1')
        {
            return false;
        }
        return true;
    }

    /**
     * Handle post save for custom post types
     */
    public function on_post_save($post_id, $post, $update)
    {
        // Skip auto-drafts and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id))
        {
            return;
        }

        // Only sync published posts
        if ($post->post_status !== 'publish')
        {
            return;
        }

        $auto_sync = get_option('chatmaxima_auto_sync', false);
        $sync_post_types = get_option('chatmaxima_sync_post_types', ['post', 'page']);

        if (!$auto_sync || !in_array($post->post_type, (array)$sync_post_types))
        {
            return;
        }

        // Check if this post type is already handled by publish_post/publish_page hooks
        if (in_array($post->post_type, ['post', 'page']))
        {
            return;
        }

        $this->sync_post($post_id);
    }

    /**
     * Sync a single post to ChatMaxima
     */
    public function sync_post($post_id)
    {
        if (!$this->can_sync_post($post_id))
        {
            return false;
        }

        $knowledge_source_alias = get_option('chatmaxima_knowledge_source_alias', '');

        if (empty($knowledge_source_alias))
        {
            return false;
        }

        if (!$this->api_client->is_authenticated())
        {
            return false;
        }

        $url = get_permalink($post_id);

        if (!$url)
        {
            return false;
        }

        $result = $this->api_client->add_training_urls($knowledge_source_alias, [$url]);

        if (is_wp_error($result))
        {
            if ($result->get_error_code() === 'PLAN_PAGE_LIMIT')
            {
                self::record_plan_limit($result->get_error_data());
                self::mark_limited($post_id);
                return false;
            }
            // Record the error on the post (shown in the meta box)
            update_post_meta($post_id, '_chatmaxima_sync_status', 'error');
            update_post_meta($post_id, '_chatmaxima_sync_error', $result->get_error_message());
            return false;
        }

        if (is_array($result))
        {
            self::record_plan_limit($result['plan_limit'] ?? null);
            if (!empty($result['skipped_urls']) && in_array($url, (array) $result['skipped_urls'], true))
            {
                self::mark_limited($post_id);
                return false;
            }
        }

        // Update sync status
        update_post_meta($post_id, '_chatmaxima_sync_status', 'synced');
        update_post_meta($post_id, '_chatmaxima_sync_date', current_time('mysql'));
        delete_post_meta($post_id, '_chatmaxima_sync_error');

        return true;
    }

    /**
     * Remember the workspace's page cap from the last API answer (null: no cap), for Home, the meta box and notices
     */
    public static function record_plan_limit($plan_limit)
    {
        if (is_array($plan_limit) && isset($plan_limit['limit']))
        {
            update_option('chatmaxima_plan_limit', [
                'limit' => absint($plan_limit['limit']),
                'used' => absint($plan_limit['used'] ?? 0),
                'reached' => !empty($plan_limit['reached']),
                'upgrade_url' => esc_url_raw((string) ($plan_limit['upgrade_url'] ?? ''))
            ], false);
        }
        else
        {
            delete_option('chatmaxima_plan_limit');
        }
    }

    /**
     * A post that did not fit in the Free plan's page cap
     */
    public static function mark_limited($post_id)
    {
        update_post_meta($post_id, '_chatmaxima_sync_status', 'limited');
        update_post_meta($post_id, '_chatmaxima_sync_error', self::limit_message());
    }

    /**
     * What to tell the user when the cap is reached
     */
    public static function limit_message()
    {
        $limit = self::plan_limit_value();
        /* translators: %d: number of pages the Free plan keeps */
        return sprintf(__('Not synced: the ChatMaxima Free plan keeps your %d most recent posts and pages. Upgrade your plan to sync more.', 'chatmaxima-ai-chatbot'), $limit);
    }

    /**
     * The cap itself, 100 unless the API said otherwise
     */
    public static function plan_limit_value()
    {
        $plan_limit = get_option('chatmaxima_plan_limit', []);
        if (is_array($plan_limit) && !empty($plan_limit['limit']))
        {
            return absint($plan_limit['limit']);
        }
        return 100;
    }

    /**
     * Where to upgrade
     */
    public static function upgrade_url()
    {
        $plan_limit = get_option('chatmaxima_plan_limit', []);
        if (is_array($plan_limit) && !empty($plan_limit['upgrade_url']))
        {
            return $plan_limit['upgrade_url'];
        }
        return 'https://chatmaxima.com/dashboard/plans/';
    }

    /**
     * Add sync meta box to post editor
     */
    public function add_sync_meta_box()
    {
        $sync_post_types = get_option('chatmaxima_sync_post_types', ['post', 'page']);

        foreach ((array)$sync_post_types as $post_type)
        {
            add_meta_box(
                'chatmaxima_sync_meta_box',
                __('ChatMaxima Sync', 'chatmaxima-ai-chatbot'),
                [$this, 'render_sync_meta_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Render sync meta box content
     */
    public function render_sync_meta_box($post)
    {
        $sync_status = get_post_meta($post->ID, '_chatmaxima_sync_status', true);
        $sync_date = get_post_meta($post->ID, '_chatmaxima_sync_date', true);
        $sync_error = get_post_meta($post->ID, '_chatmaxima_sync_error', true);
        $exclude_from_sync = get_post_meta($post->ID, '_chatmaxima_exclude_sync', true);

        wp_nonce_field('chatmaxima_sync_meta', 'chatmaxima_sync_nonce');
        ?>
        <div class="chatmaxima-sync-meta-box">
            <p>
                <label>
                    <input type="checkbox" name="chatmaxima_exclude_sync" value="1" <?php checked($exclude_from_sync, '1'); ?> />
                    <?php esc_html_e('Exclude from sync', 'chatmaxima-ai-chatbot'); ?>
                </label>
            </p>

            <div class="chatmaxima-sync-status">
                <strong><?php esc_html_e('Status:', 'chatmaxima-ai-chatbot'); ?></strong>
                <?php if ($sync_status === 'synced'): ?>
                    <span class="status-synced"><?php esc_html_e('Synced', 'chatmaxima-ai-chatbot'); ?></span>
                    <?php if ($sync_date): ?>
                        <br><small><?php echo esc_html($sync_date); ?></small>
                    <?php endif; ?>
                <?php elseif ($sync_status === 'limited'): ?>
                    <span class="status-error"><?php esc_html_e('Not synced, plan limit', 'chatmaxima-ai-chatbot'); ?></span>
                    <br><small class="error-message"><?php echo esc_html(self::limit_message()); ?></small>
                    <br><a href="<?php echo esc_url(self::upgrade_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Upgrade plan', 'chatmaxima-ai-chatbot'); ?></a>
                <?php elseif ($sync_status === 'error'): ?>
                    <span class="status-error"><?php esc_html_e('Error', 'chatmaxima-ai-chatbot'); ?></span>
                    <?php if ($sync_error): ?>
                        <br><small class="error-message"><?php echo esc_html($sync_error); ?></small>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="status-pending"><?php esc_html_e('Not synced', 'chatmaxima-ai-chatbot'); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($post->post_status === 'publish' && !$exclude_from_sync): ?>
            <p>
                <button type="button" class="button chatmaxima-sync-now-btn" data-post-id="<?php echo esc_attr($post->ID); ?>">
                    <?php esc_html_e('Sync Now', 'chatmaxima-ai-chatbot'); ?>
                </button>
                <span class="chatmaxima-sync-now-message"></span>
            </p>
            <?php endif; ?>
        </div>

        <?php
    }

    /**
     * Save sync meta data
     */
    public function save_sync_meta($post_id, $post)
    {
        if (!isset($_POST['chatmaxima_sync_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['chatmaxima_sync_nonce'])), 'chatmaxima_sync_meta'))
        {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        {
            return;
        }

        if (!current_user_can('edit_post', $post_id))
        {
            return;
        }

        $exclude = isset($_POST['chatmaxima_exclude_sync']) ? '1' : '0';
        update_post_meta($post_id, '_chatmaxima_exclude_sync', $exclude);
    }

    /**
     * Register bulk action
     */
    public function register_bulk_action($bulk_actions)
    {
        $bulk_actions['chatmaxima_sync'] = __('Sync to ChatMaxima', 'chatmaxima-ai-chatbot');
        return $bulk_actions;
    }

    /**
     * Handle bulk action
     */
    public function handle_bulk_action($redirect_to, $doaction, $post_ids)
    {
        if ($doaction !== 'chatmaxima_sync')
        {
            return $redirect_to;
        }

        $synced = 0;
        $failed = 0;
        $limited = 0;

        foreach ($post_ids as $post_id)
        {
            if (!current_user_can('edit_post', $post_id)) // the list table does not check this for custom bulk actions
            {
                continue;
            }

            if (get_post_meta($post_id, '_chatmaxima_exclude_sync', true) === '1')
            {
                continue;
            }

            if ($this->sync_post($post_id))
            {
                $synced++;
            }
            else if (get_post_meta($post_id, '_chatmaxima_sync_status', true) === 'limited')
            {
                $limited++;
            }
            else
            {
                $failed++;
            }
        }

        $redirect_to = add_query_arg([
            'chatmaxima_synced' => $synced,
            'chatmaxima_failed' => $failed,
            'chatmaxima_limited' => $limited
        ], $redirect_to);

        return $redirect_to;
    }

    /**
     * Display bulk action admin notice
     */
    public function bulk_action_admin_notice()
    {
        // Counts added to the redirect URL by handle_bulk_action(); display-only, so no nonce is needed
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'edit' || !current_user_can('edit_posts')) // only on the posts list the bulk action came from
        {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (!empty($_REQUEST['chatmaxima_synced']) || !empty($_REQUEST['chatmaxima_failed']) || !empty($_REQUEST['chatmaxima_limited']))
        {
            $limited = isset($_REQUEST['chatmaxima_limited']) ? absint($_REQUEST['chatmaxima_limited']) : 0;
            if ($limited > 0)
            {
                printf(
                    '<div class="notice notice-warning is-dismissible"><p>%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p></div>',
                    esc_html(sprintf(
                        /* translators: 1: number of posts not synced, 2: number of pages the Free plan keeps */
                        _n(
                            '%1$d post was not synced: the ChatMaxima Free plan keeps up to %2$d posts and pages.',
                            '%1$d posts were not synced: the ChatMaxima Free plan keeps up to %2$d posts and pages.',
                            $limited,
                            'chatmaxima-ai-chatbot'
                        ),
                        $limited,
                        self::plan_limit_value()
                    )),
                    esc_url(self::upgrade_url()),
                    esc_html__('Upgrade your plan to sync more.', 'chatmaxima-ai-chatbot')
                );
            }

            $synced = isset($_REQUEST['chatmaxima_synced']) ? absint($_REQUEST['chatmaxima_synced']) : 0;
            $failed = isset($_REQUEST['chatmaxima_failed']) ? absint($_REQUEST['chatmaxima_failed']) : 0;

            if ($synced > 0)
            {
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    esc_html(sprintf(
                        /* translators: %d: number of posts synced */
                        _n(
                            '%d post synced to ChatMaxima.',
                            '%d posts synced to ChatMaxima.',
                            $synced,
                            'chatmaxima-ai-chatbot'
                        ),
                        $synced
                    ))
                );
            }

            if ($failed > 0)
            {
                printf(
                    '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                    esc_html(sprintf(
                        /* translators: %d: number of posts that failed to sync */
                        _n(
                            '%d post failed to sync.',
                            '%d posts failed to sync.',
                            $failed,
                            'chatmaxima-ai-chatbot'
                        ),
                        $failed
                    ))
                );
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Add sync status column to posts list
     */
    public function add_sync_column($columns)
    {
        $columns['chatmaxima_sync'] = __('ChatMaxima', 'chatmaxima-ai-chatbot');
        return $columns;
    }

    /**
     * Render sync column content
     */
    public function sync_column_content($column, $post_id)
    {
        if ($column !== 'chatmaxima_sync')
        {
            return;
        }

        $sync_status = get_post_meta($post_id, '_chatmaxima_sync_status', true);
        $exclude = get_post_meta($post_id, '_chatmaxima_exclude_sync', true);

        if ($exclude === '1')
        {
            echo '<span class="chatmaxima-column-muted">' . esc_html__('Excluded', 'chatmaxima-ai-chatbot') . '</span>';
        }
        elseif ($sync_status === 'synced')
        {
            echo '<span class="chatmaxima-column-synced">' . esc_html__('Synced', 'chatmaxima-ai-chatbot') . '</span>';
        }
        elseif ($sync_status === 'error')
        {
            echo '<span class="chatmaxima-column-error">' . esc_html__('Error', 'chatmaxima-ai-chatbot') . '</span>';
        }
        elseif ($sync_status === 'limited')
        {
            echo '<span class="chatmaxima-column-error" title="' . esc_attr(self::limit_message()) . '">' . esc_html__('Plan limit', 'chatmaxima-ai-chatbot') . '</span>';
        }
        else
        {
            echo '<span class="chatmaxima-column-muted">-</span>';
        }
    }
}

// AJAX handler for single post sync
add_action('wp_ajax_chatmaxima_sync_single_post', function() {
    check_ajax_referer('chatmaxima_sync_single', 'nonce');

    if (!current_user_can('edit_posts'))
    {
        wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
    }

    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0; // nonce checked by check_ajax_referer() above

    if (!$post_id || !current_user_can('edit_post', $post_id)) // only a post this user can edit, never any id
    {
        wp_send_json_error(['message' => __('Invalid post ID', 'chatmaxima-ai-chatbot')]);
    }

    global $chatmaxima_content_sync;

    if (!$chatmaxima_content_sync)
    {
        wp_send_json_error(['message' => __('Sync not initialized', 'chatmaxima-ai-chatbot')]);
    }

    if (!$chatmaxima_content_sync->can_sync_post($post_id))
    {
        wp_send_json_error(['message' => __('Only published, public items of a selected content type can be synced.', 'chatmaxima-ai-chatbot')]);
    }

    $result = $chatmaxima_content_sync->sync_post($post_id);

    if ($result)
    {
        wp_send_json_success(['message' => __('Synced successfully', 'chatmaxima-ai-chatbot')]);
    }
    else
    {
        $error = get_post_meta($post_id, '_chatmaxima_sync_error', true);
        if ($error === '')
        {
            $error = __('Sync failed', 'chatmaxima-ai-chatbot');
        }
        wp_send_json_error(['message' => $error]);
    }
});
