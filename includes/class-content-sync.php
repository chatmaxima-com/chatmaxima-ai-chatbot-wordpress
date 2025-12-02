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

        $this->sync_post($post_id);
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
            // Log error
            error_log('ChatMaxima sync error for post ' . $post_id . ': ' . $result->get_error_message());
            update_post_meta($post_id, '_chatmaxima_sync_status', 'error');
            update_post_meta($post_id, '_chatmaxima_sync_error', $result->get_error_message());
            return false;
        }

        // Update sync status
        update_post_meta($post_id, '_chatmaxima_sync_status', 'synced');
        update_post_meta($post_id, '_chatmaxima_sync_date', current_time('mysql'));
        delete_post_meta($post_id, '_chatmaxima_sync_error');

        return true;
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
                    <?php _e('Exclude from sync', 'chatmaxima-ai-chatbot'); ?>
                </label>
            </p>

            <div class="chatmaxima-sync-status">
                <strong><?php _e('Status:', 'chatmaxima-ai-chatbot'); ?></strong>
                <?php if ($sync_status === 'synced'): ?>
                    <span class="status-synced"><?php _e('Synced', 'chatmaxima-ai-chatbot'); ?></span>
                    <?php if ($sync_date): ?>
                        <br><small><?php echo esc_html($sync_date); ?></small>
                    <?php endif; ?>
                <?php elseif ($sync_status === 'error'): ?>
                    <span class="status-error"><?php _e('Error', 'chatmaxima-ai-chatbot'); ?></span>
                    <?php if ($sync_error): ?>
                        <br><small class="error-message"><?php echo esc_html($sync_error); ?></small>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="status-pending"><?php _e('Not synced', 'chatmaxima-ai-chatbot'); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($post->post_status === 'publish' && !$exclude_from_sync): ?>
            <p>
                <button type="button" class="button chatmaxima-sync-now-btn" data-post-id="<?php echo esc_attr($post->ID); ?>">
                    <?php _e('Sync Now', 'chatmaxima-ai-chatbot'); ?>
                </button>
            </p>
            <?php endif; ?>
        </div>

        <style>
            .chatmaxima-sync-meta-box .status-synced { color: #46b450; }
            .chatmaxima-sync-meta-box .status-error { color: #dc3232; }
            .chatmaxima-sync-meta-box .status-pending { color: #999; }
            .chatmaxima-sync-meta-box .error-message { color: #dc3232; }
        </style>

        <script>
            jQuery(document).ready(function($) {
                $('.chatmaxima-sync-now-btn').on('click', function() {
                    var $btn = $(this);
                    var postId = $btn.data('post-id');

                    $btn.prop('disabled', true).text('<?php _e('Syncing...', 'chatmaxima-ai-chatbot'); ?>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'chatmaxima_sync_single_post',
                            post_id: postId,
                            nonce: '<?php echo wp_create_nonce('chatmaxima_sync_single'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $btn.text('<?php _e('Synced!', 'chatmaxima-ai-chatbot'); ?>');
                                location.reload();
                            } else {
                                $btn.prop('disabled', false).text('<?php _e('Sync Now', 'chatmaxima-ai-chatbot'); ?>');
                                alert(response.data.message);
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false).text('<?php _e('Sync Now', 'chatmaxima-ai-chatbot'); ?>');
                            alert('<?php _e('Sync failed. Please try again.', 'chatmaxima-ai-chatbot'); ?>');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Save sync meta data
     */
    public function save_sync_meta($post_id, $post)
    {
        if (!isset($_POST['chatmaxima_sync_nonce']) || !wp_verify_nonce($_POST['chatmaxima_sync_nonce'], 'chatmaxima_sync_meta'))
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

        foreach ($post_ids as $post_id)
        {
            $exclude = get_post_meta($post_id, '_chatmaxima_exclude_sync', true);

            if ($exclude === '1')
            {
                continue;
            }

            if ($this->sync_post($post_id))
            {
                $synced++;
            }
            else
            {
                $failed++;
            }
        }

        $redirect_to = add_query_arg([
            'chatmaxima_synced' => $synced,
            'chatmaxima_failed' => $failed
        ], $redirect_to);

        return $redirect_to;
    }

    /**
     * Display bulk action admin notice
     */
    public function bulk_action_admin_notice()
    {
        if (!empty($_REQUEST['chatmaxima_synced']) || !empty($_REQUEST['chatmaxima_failed']))
        {
            $synced = intval($_REQUEST['chatmaxima_synced']);
            $failed = intval($_REQUEST['chatmaxima_failed']);

            if ($synced > 0)
            {
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    sprintf(
                        _n(
                            '%d post synced to ChatMaxima.',
                            '%d posts synced to ChatMaxima.',
                            $synced,
                            'chatmaxima-ai-chatbot'
                        ),
                        $synced
                    )
                );
            }

            if ($failed > 0)
            {
                printf(
                    '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                    sprintf(
                        _n(
                            '%d post failed to sync.',
                            '%d posts failed to sync.',
                            $failed,
                            'chatmaxima-ai-chatbot'
                        ),
                        $failed
                    )
                );
            }
        }
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
            echo '<span style="color: #999;">' . __('Excluded', 'chatmaxima-ai-chatbot') . '</span>';
        }
        elseif ($sync_status === 'synced')
        {
            echo '<span style="color: #46b450;">' . __('Synced', 'chatmaxima-ai-chatbot') . '</span>';
        }
        elseif ($sync_status === 'error')
        {
            echo '<span style="color: #dc3232;">' . __('Error', 'chatmaxima-ai-chatbot') . '</span>';
        }
        else
        {
            echo '<span style="color: #999;">-</span>';
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

    $post_id = intval($_POST['post_id']);

    if (!$post_id)
    {
        wp_send_json_error(['message' => __('Invalid post ID', 'chatmaxima-ai-chatbot')]);
    }

    global $chatmaxima_content_sync;

    if (!$chatmaxima_content_sync)
    {
        wp_send_json_error(['message' => __('Sync not initialized', 'chatmaxima-ai-chatbot')]);
    }

    $result = $chatmaxima_content_sync->sync_post($post_id);

    if ($result)
    {
        wp_send_json_success(['message' => __('Synced successfully', 'chatmaxima-ai-chatbot')]);
    }
    else
    {
        $error = get_post_meta($post_id, '_chatmaxima_sync_error', true);
        wp_send_json_error(['message' => $error ?: __('Sync failed', 'chatmaxima-ai-chatbot')]);
    }
});
