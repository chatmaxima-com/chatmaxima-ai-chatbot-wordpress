<?php
/**
 * ChatMaxima setup wizard and Home
 *
 * Four steps (Connect, Knowledge, Chatbot, Install), plus a Workspace step when the account has two or more
 * workspaces. After setup the same menu item opens Home, where each part is edited in place. Progress is kept in
 * the chatmaxima_setup option so the user can leave and come back.
 *
 * @package ChatMaxima AI Chatbot
 */

// Prevent direct access
if (!defined('ABSPATH'))
{
    exit;
}

class ChatMaxima_Setup_Wizard
{
    const STATE_OPTION = 'chatmaxima_setup';
    const PAGE_SLUG = 'chatmaxima-settings';
    const PROVIDERS = ['openai', 'anthropic', 'gemini', 'groq'];

    private $api_client;

    public function __construct($api_client)
    {
        $this->api_client = $api_client;

        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);

        $ajax_actions = [
            'chatmaxima_wiz_state' => 'ajax_state',
            'chatmaxima_wiz_teams' => 'ajax_teams',
            'chatmaxima_wiz_select_team' => 'ajax_select_team',
            'chatmaxima_wiz_save_bot' => 'ajax_save_bot',
            'chatmaxima_wiz_get_bot' => 'ajax_get_bot',
            'chatmaxima_wiz_preview' => 'ajax_preview',
            'chatmaxima_wiz_avatar' => 'ajax_avatar',
            'chatmaxima_wiz_keys' => 'ajax_keys',
            'chatmaxima_wiz_key_test' => 'ajax_key_test',
            'chatmaxima_wiz_key_add' => 'ajax_key_add',
            'chatmaxima_wiz_key_delete' => 'ajax_key_delete',
            'chatmaxima_wiz_models' => 'ajax_models',
            'chatmaxima_wiz_install_channel' => 'ajax_install_channel',
            'chatmaxima_wiz_finish' => 'ajax_finish',
            'chatmaxima_wiz_reset' => 'ajax_reset',
            'chatmaxima_wiz_verify_signup' => 'ajax_verify_signup',
            'chatmaxima_wiz_resend_code' => 'ajax_resend_code',
            'chatmaxima_wiz_training_status' => 'ajax_training_status'
        ];
        foreach ($ajax_actions as $action => $method)
        {
            add_action('wp_ajax_' . $action, [$this, $method]);
        }
    }

    /**
     * Setup progress, with defaults
     */
    public static function get_state()
    {
        $state = get_option(self::STATE_OPTION, []);
        if (!is_array($state))
        {
            $state = [];
        }
        return array_merge([
            'step' => 'connect',
            'completed' => false,
            'bot_alias' => '',
            'bot_name' => '',
            'model_label' => '',
            'workspace_confirmed' => false,
            'completed_at' => '',
            'team_alias' => ''
        ], $state);
    }

    private static function save_state($changes)
    {
        $state = array_merge(self::get_state(), $changes);
        update_option(self::STATE_OPTION, $state, false);
        return $state;
    }

    /**
     * After a sign-in: if it is the account the setup was done with, keep the setup and move back to its workspace;
     * if it is another account, the knowledge source, chatbot and channel belong to someone else, so start again.
     */
    public function reconcile_account()
    {
        $user_info = get_option('chatmaxima_user_info', []);
        $last = get_option('chatmaxima_last_account', []);
        $state = self::get_state();
        if (!is_array($user_info) || empty($user_info['email']))
        {
            return;
        }
        $same_account = is_array($last) && !empty($last['email']) && strtolower($last['email']) === strtolower($user_info['email']);
        if ($same_account)
        {
            $setup_team = '';
            if (!empty($state['team_alias']))
            {
                $setup_team = $state['team_alias'];
            }
            else if (!empty($last['team_alias']))
            {
                $setup_team = $last['team_alias'];
            }
            $current_team = isset($user_info['team_alias']) ? $user_info['team_alias'] : '';
            if ($setup_team !== '' && $setup_team !== $current_team) // sign-in lands on the default workspace; the setup lives in another
            {
                $switched = $this->api_client->switch_team($setup_team);
                if (is_wp_error($switched))
                {
                    $same_account = false; // the workspace is gone or no longer shared with this account
                }
            }
        }
        if (!$same_account)
        {
            foreach (['chatmaxima_knowledge_source_alias', 'chatmaxima_channel_alias', 'chatmaxima_installed_channel', 'chatmaxima_selected_team', 'chatmaxima_last_full_sync', self::STATE_OPTION] as $option)
            {
                delete_option($option);
            }
        }
        delete_option('chatmaxima_last_account');
    }

    /**
     * Setup is done, or an install from before the wizard already has a live widget
     */
    public function is_setup_complete()
    {
        $state = self::get_state();
        if (!empty($state['completed']))
        {
            return true;
        }
        // Installs from before 2.1.0 that already show the widget go straight to Home
        if (get_option(self::STATE_OPTION, null) === null && get_option('chatmaxima_installed_channel', '') !== '')
        {
            return true;
        }
        return false;
    }

    public function enqueue_scripts($hook)
    {
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG)
        {
            return;
        }

        wp_enqueue_style('chatmaxima-setup', CHATMAXIMA_PLUGIN_URL . 'assets/setup.css', [], CHATMAXIMA_VERSION);
        wp_enqueue_script('chatmaxima-setup', CHATMAXIMA_PLUGIN_URL . 'assets/setup.js', ['jquery'], CHATMAXIMA_VERSION, true);

        $state = self::get_state();
        $user_info = get_option('chatmaxima_user_info', []);
        $view = 'wizard';
        if ($this->is_setup_complete() && $this->api_client->is_authenticated())
        {
            $view = 'home';
        }
        $edit = '';
        if (isset($_GET['edit'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which step to show
        {
            $edit = sanitize_key(wp_unslash($_GET['edit'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $view = 'wizard';
        }

        $step = $state['step'];
        if (!$this->api_client->is_authenticated())
        {
            $step = 'connect';
        }
        if ($edit !== '' && in_array($edit, ['workspace', 'knowledge', 'chatbot', 'model', 'install'], true))
        {
            $step = $edit;
        }

        $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $site_is_local = $this->is_local_host($site_host); // ChatMaxima reads pages from the internet, so a local site can never be indexed

        wp_localize_script('chatmaxima-setup', 'chatmaximaSetup', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('chatmaxima_admin_nonce'),
            'view' => $view,
            'step' => $step,
            'editing' => $edit !== '',
            'homeUrl' => admin_url('admin.php?page=' . self::PAGE_SLUG),
            'isAuthenticated' => $this->api_client->is_authenticated(),
            'state' => $state,
            'user' => [
                'name' => isset($user_info['name']) ? $user_info['name'] : '',
                'email' => isset($user_info['email']) ? $user_info['email'] : '',
                'teamName' => isset($user_info['team_name']) ? $user_info['team_name'] : '',
                'teamAlias' => isset($user_info['team_alias']) ? $user_info['team_alias'] : ''
            ],
            'site' => [
                'name' => get_bloginfo('name'),
                'url' => home_url('/'),
                'host' => $site_host,
                'isLocal' => $site_is_local,
                'iconUrl' => get_site_icon_url(96)
            ],
            'knowledgeAlias' => get_option('chatmaxima_knowledge_source_alias', ''),
            'channelAlias' => get_option('chatmaxima_channel_alias', ''),
            'installedChannel' => get_option('chatmaxima_installed_channel', ''),
            'postTypes' => $this->get_post_type_counts(),
            'selectedPostTypes' => array_values((array) get_option('chatmaxima_sync_post_types', ['post', 'page'])),
            'autoSync' => (bool) get_option('chatmaxima_auto_sync', false),
            'lastFullSync' => get_option('chatmaxima_last_full_sync', []),
            'recentSync' => $this->get_recent_sync(),
            'dashboardUrl' => 'https://chatmaxima.com/dashboard/',
            'isSsl' => is_ssl(), // passwords and provider keys go browser -> this site; warn when that hop is plain http
            'strings' => $this->get_strings()
        ]);
    }

    /**
     * A host ChatMaxima cannot reach from the internet: localhost, a bare name, a private or loopback address, or a
     * development TLD. Pages on such a site are accepted by the sync but can never be read, so the plugin says so.
     */
    private function is_local_host($host)
    {
        $host = strtolower(trim((string) $host, '[]'));
        if ($host === '' || $host === 'localhost' || strpos($host, '.') === false)
        {
            return true;
        }
        if (preg_match('/\.(local|localhost|test|internal|lan|home|localdomain)$/', $host))
        {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
        {
            return true;
        }
        return false;
    }

    /**
     * Public post types with their published counts, for the "What to sync" chips
     */
    private function get_post_type_counts()
    {
        $types = [];
        foreach (get_post_types(['public' => true], 'objects') as $post_type)
        {
            if ($post_type->name === 'attachment')
            {
                continue;
            }
            $counts = wp_count_posts($post_type->name);
            $types[] = [
                'name' => $post_type->name,
                'label' => $post_type->labels->name,
                'count' => isset($counts->publish) ? (int) $counts->publish : 0
            ];
        }
        return $types;
    }

    /**
     * The last items the plugin synced, from the per-post sync record
     */
    private function get_recent_sync()
    {
        $query = new WP_Query([
            'post_type' => 'any',
            'post_status' => 'any',
            'posts_per_page' => 5,
            'meta_key' => '_chatmaxima_sync_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 5 rows on an admin page
            'orderby' => 'meta_value',
            'order' => 'DESC',
            'no_found_rows' => true
        ]);
        $rows = [];
        foreach ($query->posts as $post)
        {
            $post_type_object = get_post_type_object($post->post_type);
            $type_label = $post->post_type;
            if ($post_type_object)
            {
                $type_label = $post_type_object->labels->singular_name;
            }
            $rows[] = [
                'date' => get_post_meta($post->ID, '_chatmaxima_sync_date', true),
                'title' => html_entity_decode(get_the_title($post->ID), ENT_QUOTES, 'UTF-8'),
                'type' => $type_label,
                'status' => get_post_meta($post->ID, '_chatmaxima_sync_status', true)
            ];
        }
        return $rows;
    }

    /**
     * The page: the wizard until setup is done, then Home. Both are filled in by assets/setup.js.
     */
    public function render_page()
    {
        if (!current_user_can('manage_options'))
        {
            return;
        }
        ?>
        <div class="wrap cmw-wrap">
            <h1 class="screen-reader-text"><?php esc_html_e('ChatMaxima AI Chatbot', 'chatmaxima-ai-chatbot'); ?></h1>
            <div class="cmw-header">
                <img class="cmw-logo" src="<?php echo esc_url(CHATMAXIMA_PLUGIN_URL . 'assets/chatmaxima-mark.png'); ?>" alt="" width="36" height="36">
                <div class="cmw-header-text">
                    <div class="cmw-title"><?php esc_html_e('ChatMaxima AI Chatbot', 'chatmaxima-ai-chatbot'); ?></div>
                    <div class="cmw-subtitle" id="cmw-subtitle"><?php esc_html_e('Add an AI chatbot that answers visitors from your own posts and pages.', 'chatmaxima-ai-chatbot'); ?></div>
                </div>
                <div class="cmw-header-actions" id="cmw-header-actions"></div>
            </div>
            <div id="cmw-notice" class="cmw-notice" role="status" aria-live="polite" hidden></div>
            <div id="cmw-app" class="cmw-app">
                <div class="cmw-loading"><span class="spinner is-active"></span></div>
            </div>
        </div>
        <?php
    }

    /**
     * Help page
     */
    public function render_help()
    {
        if (!current_user_can('manage_options'))
        {
            return;
        }
        ?>
        <div class="wrap cmw-wrap">
            <div class="cmw-header">
                <img class="cmw-logo" src="<?php echo esc_url(CHATMAXIMA_PLUGIN_URL . 'assets/chatmaxima-mark.png'); ?>" alt="" width="36" height="36">
                <div class="cmw-header-text">
                    <div class="cmw-title"><?php esc_html_e('Help', 'chatmaxima-ai-chatbot'); ?></div>
                    <div class="cmw-subtitle"><?php esc_html_e('Answers to common questions about the ChatMaxima plugin.', 'chatmaxima-ai-chatbot'); ?></div>
                </div>
            </div>
            <div class="cmw-panel cmw-help">
                <h2><?php esc_html_e('What does the plugin send to ChatMaxima?', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php esc_html_e('The addresses of your published posts and pages, so ChatMaxima can read them into your knowledge source. Drafts, private and password-protected content are never sent.', 'chatmaxima-ai-chatbot'); ?></p>
                <h2><?php esc_html_e('Do I need an AI API key?', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php esc_html_e('No. MaxIA V4 is included with your ChatMaxima account. You only need a key if you want a model from OpenAI, Anthropic, Google or Groq, and that company then bills you for each reply.', 'chatmaxima-ai-chatbot'); ?></p>
                <h2><?php esc_html_e('Where are chats from my site?', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php esc_html_e('In the ChatMaxima inbox, under the web channel the plugin set up. A person on your team can take over any chat from there.', 'chatmaxima-ai-chatbot'); ?></p>
                <h2><?php esc_html_e('How do I remove the chatbot from my site?', 'chatmaxima-ai-chatbot'); ?></h2>
                <p><?php esc_html_e('Turn off "Show on your site" on the ChatMaxima Home page, or deactivate the plugin.', 'chatmaxima-ai-chatbot'); ?></p>
                <p>
                    <a class="button" href="<?php echo esc_url('https://docs.chatmaxima.com/'); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('ChatMaxima documentation', 'chatmaxima-ai-chatbot'); ?></a>
                    <a class="button" href="<?php echo esc_url('https://chatmaxima.com/contact-us/'); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Contact support', 'chatmaxima-ai-chatbot'); ?></a>
                </p>
            </div>
        </div>
        <?php
    }

    // ---- AJAX ----

    /**
     * Nonce, capability and (optionally) connection checks shared by every handler
     */
    private function guard($require_auth = true)
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'chatmaxima_admin_nonce'))
        {
            wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'chatmaxima-ai-chatbot')]);
        }
        if (!current_user_can('manage_options'))
        {
            wp_send_json_error(['message' => __('Permission denied', 'chatmaxima-ai-chatbot')]);
        }
        if ($require_auth && !$this->api_client->is_authenticated())
        {
            wp_send_json_error(['message' => __('Not connected to ChatMaxima. Please sign in again.', 'chatmaxima-ai-chatbot'), 'code' => 'not_authenticated']);
        }
    }

    private function send_wp_error($error)
    {
        wp_send_json_error(['message' => $error->get_error_message(), 'code' => $error->get_error_code()]);
    }

    private function post_text($key, $default = '')
    {
        if (!isset($_POST[$key])) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
        {
            return $default;
        }
        return sanitize_text_field(wp_unslash($_POST[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    private function post_textarea($key, $default = '')
    {
        if (!isset($_POST[$key])) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
        {
            return $default;
        }
        return sanitize_textarea_field(wp_unslash($_POST[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    /**
     * Activate a new account with the code ChatMaxima emailed, which also connects the plugin
     */
    public function ajax_verify_signup()
    {
        $this->guard(false);
        $email = sanitize_email($this->post_text('email'));
        $code = preg_replace('/[^0-9]/', '', $this->post_text('code'));
        if ($email === '' || strlen($code) !== 6)
        {
            wp_send_json_error(['message' => __('Enter the 6-digit code from the email.', 'chatmaxima-ai-chatbot')]);
        }
        $result = $this->api_client->verify_signup($email, $code);
        if (is_wp_error($result))
        {
            $this->send_wp_error($result);
        }
        wp_send_json_success(['state' => self::save_state(['step' => 'knowledge'])]);
    }

    public function ajax_resend_code()
    {
        $this->guard(false);
        $email = sanitize_email($this->post_text('email'));
        if ($email === '')
        {
            wp_send_json_error(['message' => __('Please enter a valid email address', 'chatmaxima-ai-chatbot')]);
        }
        $result = $this->api_client->resend_signup_code($email);
        if (is_wp_error($result))
        {
            $this->send_wp_error($result);
        }
        wp_send_json_success([]);
    }

    /**
     * How many synced pages ChatMaxima has read into the knowledge source, and why the others were not
     */
    public function ajax_training_status()
    {
        $this->guard();
        $alias = get_option('chatmaxima_knowledge_source_alias', '');
        if ($alias === '')
        {
            wp_send_json_success(['total' => 0, 'indexed' => 0, 'reason' => '']);
        }
        $result = $this->api_client->get_training_content($alias);
        if (is_wp_error($result))
        {
            $this->send_wp_error($result);
        }
        $items = [];
        if (isset($result['training_content']) && is_array($result['training_content']))
        {
            $items = $result['training_content'];
        }
        $total = 0;
        $indexed = 0;
        $reason = '';
        foreach ($items as $item)
        {
            if (!is_array($item))
            {
                continue;
            }
            $total++;
            if (isset($item['index_status']) && $item['index_status'] === 'Y')
            {
                $indexed++;
            }
            else if ($reason === '' && !empty($item['index_status_reason']))
            {
                $reason = sanitize_text_field((string) $item['index_status_reason']);
            }
        }
        wp_send_json_success(['total' => $total, 'indexed' => $indexed, 'reason' => $reason]);
    }

    /**
     * Save which step the user is on
     */
    public function ajax_state()
    {
        $this->guard(false);
        $step = sanitize_key($this->post_text('step'));
        if (!in_array($step, ['connect', 'workspace', 'knowledge', 'chatbot', 'install'], true))
        {
            wp_send_json_error(['message' => __('Unknown step', 'chatmaxima-ai-chatbot')]);
        }
        $changes = ['step' => $step];
        if ($this->post_text('workspace_confirmed') === '1')
        {
            $changes['workspace_confirmed'] = true;
        }
        wp_send_json_success(['state' => self::save_state($changes)]);
    }

    public function ajax_teams()
    {
        $this->guard();
        $result = $this->api_client->list_teams_with_details();
        if (is_wp_error($result))
        {
            $this->send_wp_error($result);
        }
        $user_info = get_option('chatmaxima_user_info', []);
        $current = '';
        if (isset($user_info['team_alias']))
        {
            $current = $user_info['team_alias'];
        }
        $teams = [];
        if (isset($result['teams']) && is_array($result['teams']))
        {
            $teams = $result['teams'];
        }
        // The sign-in response carries the workspace alias but not its name; fill it in from the list so Install
        // and Home can show "Workspace: Acme Bakery" instead of a blank
        if (is_array($user_info) && empty($user_info['team_name']) && $current !== '')
        {
            foreach ($teams as $team)
            {
                if (is_array($team) && isset($team['team_alias'], $team['team_name']) && $team['team_alias'] === $current)
                {
                    $user_info['team_name'] = sanitize_text_field((string) $team['team_name']);
                    update_option('chatmaxima_user_info', $user_info, false);
                    break;
                }
            }
        }
        wp_send_json_success(['teams' => $teams, 'current' => $current]);
    }

    /**
     * Pick the workspace. A different one starts the knowledge, chatbot and channel choices again.
     */
    public function ajax_select_team()
    {
        $this->guard();
        $team_alias = $this->post_text('team_alias');
        $user_info = get_option('chatmaxima_user_info', []);
        $current = '';
        if (isset($user_info['team_alias']))
        {
            $current = $user_info['team_alias'];
        }

        if ($team_alias !== '' && $team_alias !== $current)
        {
            $result = $this->api_client->switch_team($team_alias);
            if (is_wp_error($result))
            {
                $this->send_wp_error($result);
            }
            delete_option('chatmaxima_knowledge_source_alias');
            delete_option('chatmaxima_channel_alias');
            self::save_state(['bot_alias' => '', 'bot_name' => '', 'model_label' => '']);
        }

        $user_info = get_option('chatmaxima_user_info', []);
        $state = self::save_state(['workspace_confirmed' => true, 'step' => 'knowledge', 'team_alias' => isset($user_info['team_alias']) ? $user_info['team_alias'] : '']);
        wp_send_json_success([
            'state' => $state,
            'teamName' => isset($user_info['team_name']) ? $user_info['team_name'] : ''
        ]);
    }

    /**
     * Create the chatbot on the selected knowledge source, or update it
     */
    public function ajax_save_bot()
    {
        $this->guard();
        $state = self::get_state();
        $knowledge_alias = get_option('chatmaxima_knowledge_source_alias', '');
        if ($knowledge_alias === '' && $state['bot_alias'] === '')
        {
            wp_send_json_error(['message' => __('Choose a knowledge source first.', 'chatmaxima-ai-chatbot')]);
        }

        $data = [];
        foreach (['name', 'purpose', 'tone', 'language'] as $key)
        {
            if (isset($_POST[$key])) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
            {
                $data[$key] = $this->post_text($key);
            }
        }
        foreach (['business_description', 'welcome_message', 'instructions'] as $key)
        {
            if (isset($_POST[$key])) // phpcs:ignore WordPress.Security.NonceVerification.Missing
            {
                $data[$key] = $this->post_textarea($key);
            }
        }
        if (isset($_POST['avatar_url'])) // phpcs:ignore WordPress.Security.NonceVerification.Missing
        {
            $data['avatar_url'] = esc_url_raw(wp_unslash($_POST['avatar_url'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }
        if (isset($_POST['provider'])) // phpcs:ignore WordPress.Security.NonceVerification.Missing
        {
            $provider = $this->post_text('provider');
            if ($provider !== 'chatmaxima' && !in_array($provider, self::PROVIDERS, true))
            {
                wp_send_json_error(['message' => __('Unknown AI provider.', 'chatmaxima-ai-chatbot')]);
            }
            $data['model'] = [
                'provider' => $provider,
                'model' => $this->post_text('model'),
                'credential_id' => $this->post_text('credential_id')
            ];
        }
        if ($this->post_text('regenerate_prompt') === '1')
        {
            $data['regenerate_prompt'] = true;
        }

        if ($state['bot_alias'] === '')
        {
            $data['knowledge_source'] = $knowledge_alias;
            $result = $this->api_client->create_chatbot($data);
        }
        else
        {
            if ($knowledge_alias !== '')
            {
                $data['knowledge_source'] = $knowledge_alias; // follows a knowledge source changed on Home
            }
            $result = $this->api_client->update_chatbot($state['bot_alias'], $data);
            if (is_wp_error($result) && $result->get_error_code() === 'CHATBOT_NOT_FOUND') // deleted in ChatMaxima: build a new one
            {
                $data['knowledge_source'] = $knowledge_alias;
                $result = $this->api_client->create_chatbot($data);
            }
        }

        if (is_wp_error($result))
        {
            $this->send_wp_error($result);
        }
        if (empty($result['bot_alias']) || !is_string($result['bot_alias']) || !isset($result['name']))
        {
            wp_send_json_error(['message' => __('ChatMaxima returned an incomplete chatbot. Please try again.', 'chatmaxima-ai-chatbot')]);
        }

        $state = self::save_state([
            'bot_alias' => sanitize_text_field($result['bot_alias']),
            'bot_name' => sanitize_text_field((string) $result['name']),
            'model_label' => $this->model_label(isset($result['model']) ? $result['model'] : null)
        ]);
        wp_send_json_success(['bot' => $result, 'state' => $state]);
    }

    public function ajax_get_bot()
    {
        $this->guard();
        $state = self::get_state();
        if ($state['bot_alias'] === '')
        {
            wp_send_json_success(['bot' => null]);
        }
        $result = $this->api_client->get_chatbot($state['bot_alias']);
        if (is_wp_error($result))
        {
            if ($result->get_error_code() === 'CHATBOT_NOT_FOUND')
            {
                self::save_state(['bot_alias' => '']);
                wp_send_json_success(['bot' => null, 'missing' => true]);
            }
            $this->send_wp_error($result);
        }
        wp_send_json_success(['bot' => $result]);
    }

    public function ajax_preview()
    {
        $this->guard();
        $state = self::get_state();
        if ($state['bot_alias'] === '')
        {
            wp_send_json_error(['message' => __('Save the chatbot first.', 'chatmaxima-ai-chatbot')]);
        }
        $question = $this->post_text('question');
        $result = $this->api_client->preview_chatbot($state['bot_alias'], $question);
        if (is_wp_error($result))
        {
            $this->send_wp_error($result);
        }
        wp_send_json_success($result);
    }

    /**
     * Upload an avatar image (from the file picker, or the site icon) to ChatMaxima
     */
    public function ajax_avatar()
    {
        $this->guard();
        $binary = '';

        if ($this->post_text('source') === 'site_icon')
        {
            $icon_id = (int) get_option('site_icon');
            $path = '';
            if ($icon_id > 0)
            {
                $path = get_attached_file($icon_id);
            }
            if ($path === '' || !$path || !file_exists($path))
            {
                wp_send_json_error(['message' => __('This site has no site icon. Add one under Appearance, Customize, Site Identity.', 'chatmaxima-ai-chatbot')]);
            }
            if (filesize($path) > 2097152)
            {
                wp_send_json_error(['message' => __('The site icon is larger than 2 MB. Upload a smaller image instead.', 'chatmaxima-ai-chatbot')]);
            }
            $binary = file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local media file
        }
        else
        {
            // Nonce and capability were verified in guard() above; the file is read by path, never by a client-supplied name
            // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
            $tmp_name = '';
            $size = 0;
            if (isset($_FILES['avatar']['tmp_name']) && is_string($_FILES['avatar']['tmp_name']))
            {
                $tmp_name = $_FILES['avatar']['tmp_name'];
            }
            if (isset($_FILES['avatar']['size']))
            {
                $size = (int) $_FILES['avatar']['size'];
            }
            // phpcs:enable
            if ($tmp_name === '' || !is_uploaded_file($tmp_name))
            {
                wp_send_json_error(['message' => __('Choose an image to upload.', 'chatmaxima-ai-chatbot')]);
            }
            if ($size > 2097152)
            {
                wp_send_json_error(['message' => __('The image is larger than 2 MB.', 'chatmaxima-ai-chatbot')]);
            }
            $binary = file_get_contents($tmp_name); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the uploaded temp file
        }

        $info = @getimagesizefromstring($binary); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false on a non-image is the check
        if ($info === false || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true))
        {
            wp_send_json_error(['message' => __('Use a PNG, JPEG, WebP or GIF image.', 'chatmaxima-ai-chatbot')]);
        }

        $result = $this->api_client->upload_avatar($binary);
        if (is_wp_error($result))
        {
            $this->send_wp_error($result);
        }
        wp_send_json_success($result);
    }

    public function ajax_keys()
    {
        $this->guard();
        $result = $this->api_client->list_ai_keys();
        if (is_wp_error($result))
        {
            $this->send_wp_error($result);
        }
        wp_send_json_success($result);
    }

    public function ajax_key_test()
    {
        $this->guard();
        $provider = $this->post_text('provider');
        $credential_id = $this->post_text('credential_id');
        $api_key = '';
        if (isset($_POST['api_key'])) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
        {
            $api_key = trim(wp_unslash($_POST['api_key'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a key is sent as typed; it is never stored in WordPress
        }
        if ($credential_id === '' && (!in_array($provider, self::PROVIDERS, true) || $api_key === ''))
        {
            wp_send_json_error(['message' => __('Paste the API key first.', 'chatmaxima-ai-chatbot')]);
        }
        $result = $this->api_client->test_ai_key($provider, $api_key, $credential_id);
        if (is_wp_error($result))
        {
            $this->send_wp_error($result);
        }
        wp_send_json_success($result);
    }

    public function ajax_key_add()
    {
        $this->guard();
        $provider = $this->post_text('provider');
        $api_key = '';
        if (isset($_POST['api_key'])) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
        {
            $api_key = trim(wp_unslash($_POST['api_key'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a key is sent as typed; it is never stored in WordPress
        }
        if (!in_array($provider, self::PROVIDERS, true) || $api_key === '')
        {
            wp_send_json_error(['message' => __('Paste the API key first.', 'chatmaxima-ai-chatbot')]);
        }
        /* translators: %s: site title */
        $name = sprintf(__('%s (WordPress)', 'chatmaxima-ai-chatbot'), get_bloginfo('name'));
        $result = $this->api_client->add_ai_key($provider, $api_key, mb_substr($name, 0, 40) . ' ' . gmdate('Y-m-d H:i'));
        if (is_wp_error($result))
        {
            $this->send_wp_error($result);
        }
        wp_send_json_success($result);
    }

    public function ajax_key_delete()
    {
        $this->guard();
        $result = $this->api_client->delete_ai_key($this->post_text('credential_id'));
        if (is_wp_error($result))
        {
            $this->send_wp_error($result);
        }
        wp_send_json_success($result);
    }

    public function ajax_models()
    {
        $this->guard();
        $provider = $this->post_text('provider', 'chatmaxima');
        if ($provider !== 'chatmaxima' && !in_array($provider, self::PROVIDERS, true))
        {
            wp_send_json_error(['message' => __('Unknown AI provider.', 'chatmaxima-ai-chatbot')]);
        }
        $result = $this->api_client->list_ai_models($provider);
        if (is_wp_error($result))
        {
            $this->send_wp_error($result);
        }
        wp_send_json_success($result);
    }

    /**
     * Put the chatbot on this site's web channel (an existing one the user picked, or one for this site's address)
     */
    public function ajax_install_channel()
    {
        $this->guard();
        $state = self::get_state();
        if ($state['bot_alias'] === '')
        {
            wp_send_json_error(['message' => __('Create the chatbot first.', 'chatmaxima-ai-chatbot')]);
        }

        /* translators: %s: site title */
        $site_name = sprintf(__('%s website', 'chatmaxima-ai-chatbot'), get_bloginfo('name'));
        $data = [
            'bot_alias' => $state['bot_alias'],
            'site_name' => $site_name,
            'site_url' => home_url('/')
        ];
        $account_id = $this->post_text('account_id');
        if ($account_id === '')
        {
            $account_id = get_option('chatmaxima_channel_alias', '');
        }
        if ($account_id !== '')
        {
            $data['account_id'] = $account_id;
        }
        if (isset($_POST['avatar_url'])) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
        {
            $data['avatar_url'] = esc_url_raw(wp_unslash($_POST['avatar_url'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        $result = $this->api_client->attach_web_channel($data);
        if (is_wp_error($result) && $result->get_error_code() === 'CHANNEL_NOT_FOUND' && isset($data['account_id']))
        {
            unset($data['account_id']); // the saved channel was removed in ChatMaxima: use this site's address
            delete_option('chatmaxima_channel_alias');
            $result = $this->api_client->attach_web_channel($data);
        }
        if (is_wp_error($result))
        {
            $this->send_wp_error($result);
        }
        if (empty($result['account_id']) || !is_string($result['account_id']))
        {
            wp_send_json_error(['message' => __('ChatMaxima returned an incomplete channel. Please try again.', 'chatmaxima-ai-chatbot')]);
        }
        $result['account_id'] = sanitize_text_field($result['account_id']);

        update_option('chatmaxima_channel_alias', $result['account_id']);
        $installed = get_option('chatmaxima_installed_channel', '');
        if ($installed !== '' && $installed !== $result['account_id'])
        {
            update_option('chatmaxima_installed_channel', $result['account_id']); // live widget follows the new channel
        }
        self::save_state(['step' => 'install']);
        wp_send_json_success(['channel' => $result]);
    }

    /**
     * Setup done. If the prompt was written before the content finished indexing, write it again now.
     */
    public function ajax_finish()
    {
        $this->guard();
        $state = self::get_state();
        $prompt_status = $this->post_text('prompt_status');
        if ($state['bot_alias'] !== '' && $prompt_status === 'default')
        {
            $this->api_client->update_chatbot($state['bot_alias'], ['regenerate_prompt' => true]); // best effort: Home offers it again
        }
        $user_info = get_option('chatmaxima_user_info', []);
        $state = self::save_state(['completed' => true, 'completed_at' => current_time('mysql'), 'step' => 'install', 'team_alias' => isset($user_info['team_alias']) ? $user_info['team_alias'] : '']);
        wp_send_json_success(['state' => $state, 'homeUrl' => admin_url('admin.php?page=' . self::PAGE_SLUG)]);
    }

    /**
     * Reset the plugin and run setup again. ChatMaxima data (account, knowledge source, chatbot) is kept.
     */
    public function ajax_reset()
    {
        $this->guard(false);
        $this->api_client->clear_tokens();
        foreach (['chatmaxima_user_info', 'chatmaxima_last_account', 'chatmaxima_knowledge_source_alias', 'chatmaxima_selected_team', 'chatmaxima_channel_alias', 'chatmaxima_installed_channel', 'chatmaxima_last_full_sync', self::STATE_OPTION] as $option)
        {
            delete_option($option);
        }
        wp_send_json_success(['homeUrl' => admin_url('admin.php?page=' . self::PAGE_SLUG)]);
    }

    private function model_label($model)
    {
        if (!is_array($model) || empty($model['provider']) || $model['provider'] === 'chatmaxima')
        {
            if (is_array($model) && isset($model['model']) && $model['model'] === 'maxia-v4-pro')
            {
                return 'MaxIA V4 Pro';
            }
            return 'MaxIA V4';
        }
        return $model['model'];
    }

    private function get_strings()
    {
        return [
            'stepOf' => /* translators: 1: step number, 2: number of steps */ __('Step %1$s of %2$s', 'chatmaxima-ai-chatbot'),
            'setupSteps' => /* translators: %s: number of steps */ __('Setup · %s steps', 'chatmaxima-ai-chatbot'),
            'progressSaved' => __('Your progress is saved, so you can leave and come back.', 'chatmaxima-ai-chatbot'),
            'aboutFive' => __('About 5 minutes. Your progress is saved, so you can leave and come back.', 'chatmaxima-ai-chatbot'),
            'stepConnect' => __('Connect', 'chatmaxima-ai-chatbot'),
            'stepConnectSub' => __('Sign in or create an account', 'chatmaxima-ai-chatbot'),
            'stepWorkspace' => __('Workspace', 'chatmaxima-ai-chatbot'),
            'stepWorkspaceSub' => __('Pick where the bot lives', 'chatmaxima-ai-chatbot'),
            'stepKnowledge' => __('Knowledge', 'chatmaxima-ai-chatbot'),
            'stepKnowledgeSub' => __('Sync posts and pages', 'chatmaxima-ai-chatbot'),
            'stepChatbot' => __('Chatbot', 'chatmaxima-ai-chatbot'),
            'stepChatbotSub' => __('Persona and AI model', 'chatmaxima-ai-chatbot'),
            'stepInstall' => __('Install', 'chatmaxima-ai-chatbot'),
            'stepInstallSub' => __('Preview and go live', 'chatmaxima-ai-chatbot'),
            'connectTitle' => __('Connect to ChatMaxima', 'chatmaxima-ai-chatbot'),
            'connectIntro' => __('Your chatbot runs on ChatMaxima. Sign in to your account, or create a free one here without leaving WordPress.', 'chatmaxima-ai-chatbot'),
            'haveAccount' => __('I have an account', 'chatmaxima-ai-chatbot'),
            'newAccount' => __('I\'m new to ChatMaxima', 'chatmaxima-ai-chatbot'),
            'email' => __('Email', 'chatmaxima-ai-chatbot'),
            'password' => __('Password', 'chatmaxima-ai-chatbot'),
            'yourName' => __('Your name', 'chatmaxima-ai-chatbot'),
            'show' => __('Show', 'chatmaxima-ai-chatbot'),
            'hide' => __('Hide', 'chatmaxima-ai-chatbot'),
            'forgotPassword' => __('Forgot password?', 'chatmaxima-ai-chatbot'),
            'signInContinue' => __('Sign in and continue', 'chatmaxima-ai-chatbot'),
            'signingIn' => __('Signing in...', 'chatmaxima-ai-chatbot'),
            'createContinue' => __('Create account and continue', 'chatmaxima-ai-chatbot'),
            'creating' => __('Creating your account...', 'chatmaxima-ai-chatbot'),
            'newHere' => __('New here?', 'chatmaxima-ai-chatbot'),
            'createFree' => __('Create a free ChatMaxima account', 'chatmaxima-ai-chatbot'),
            'whatConnecting' => __('What connecting does', 'chatmaxima-ai-chatbot'),
            'connectPoint1' => __('Lets the plugin copy your posts and pages into ChatMaxima so the bot can answer from them.', 'chatmaxima-ai-chatbot'),
            'connectPoint2' => __('Nothing shows on your site until you switch the widget on in the last step.', 'chatmaxima-ai-chatbot'),
            'connectPoint3' => __('You can disconnect at any time from this page.', 'chatmaxima-ai-chatbot'),
            'yourNewAccount' => __('Your new account', 'chatmaxima-ai-chatbot'),
            'newPoint1' => __('Free plan with 1,000 messages a month and the MaxIA V4 AI model. No API key needed.', 'chatmaxima-ai-chatbot'),
            'newPoint2' => __('The same login opens the ChatMaxima dashboard, where you can later add WhatsApp and Instagram.', 'chatmaxima-ai-chatbot'),
            /* translators: %s: site title */
            'newPoint3' => __('We\'ll create a workspace named after your site: %s.', 'chatmaxima-ai-chatbot'),
            'rule8' => __('8 or more characters', 'chatmaxima-ai-chatbot'),
            'ruleMix' => __('Letters and numbers', 'chatmaxima-ai-chatbot'),
            /* translators: 1: opening link tag for Terms, 2: closing link tag, 3: opening link tag for Privacy Policy, 4: closing link tag */
            'agreeTerms' => __('I agree to the ChatMaxima %1$sTerms%2$s and %3$sPrivacy Policy%4$s.', 'chatmaxima-ai-chatbot'),
            'agreeRequired' => __('Please agree to the Terms and Privacy Policy.', 'chatmaxima-ai-chatbot'),
            'emailTaken' => __('This email already has a ChatMaxima account.', 'chatmaxima-ai-chatbot'),
            'signInInstead' => __('Sign in instead', 'chatmaxima-ai-chatbot'),
            'fillAll' => __('Please fill in every field.', 'chatmaxima-ai-chatbot'),
            /* translators: %s: site host name */
            'localSite' => __('This site (%s) is not reachable from the internet, so ChatMaxima cannot read its pages and the chatbot will have nothing to answer from. Finish setup here to try it out, then run it on your public site.', 'chatmaxima-ai-chatbot'),
            /* translators: 1: pages ChatMaxima indexed, 2: pages synced */
            'readByChatMaxima' => __('ChatMaxima has read %1$s of %2$s synced pages.', 'chatmaxima-ai-chatbot'),
            'readNone' => __('ChatMaxima could not read any of the synced pages yet.', 'chatmaxima-ai-chatbot'),
            'readPending' => __('Pages are read a few minutes after they are synced.', 'chatmaxima-ai-chatbot'),
            'readStatus' => __('Read by ChatMaxima', 'chatmaxima-ai-chatbot'),
            'refresh' => __('Refresh', 'chatmaxima-ai-chatbot'),
            /* translators: %s: email address */
            'signedInAs' => __('Signed in as %s.', 'chatmaxima-ai-chatbot'),
            'signOut' => __('Sign out', 'chatmaxima-ai-chatbot'),
            'signOutConfirm' => __('Sign out of ChatMaxima? Setup starts again from the Connect step next time. Anything already created in ChatMaxima is kept.', 'chatmaxima-ai-chatbot'),
            'noSsl' => __('This admin page is not using HTTPS, so what you type here travels unencrypted to your own site. Switch WordPress to HTTPS before entering a password or an API key.', 'chatmaxima-ai-chatbot'),
            'verifyTitle' => __('Check your email', 'chatmaxima-ai-chatbot'),
            /* translators: %s: email address */
            'verifyIntro' => __('We sent a 6-digit code to %s. Enter it below to activate your account. The code works for 3 hours.', 'chatmaxima-ai-chatbot'),
            'verifyPending' => __('This account is waiting for its email code. Enter the code we sent, or send a new one.', 'chatmaxima-ai-chatbot'),
            'code' => __('Verification code', 'chatmaxima-ai-chatbot'),
            'verifyContinue' => __('Activate and continue', 'chatmaxima-ai-chatbot'),
            'verifying' => __('Checking the code...', 'chatmaxima-ai-chatbot'),
            'noCode' => __('No email? Check spam, or', 'chatmaxima-ai-chatbot'),
            'resendCode' => __('send a new code', 'chatmaxima-ai-chatbot'),
            'codeSent' => __('A new code is on its way.', 'chatmaxima-ai-chatbot'),
            'wrongEmail' => __('Use a different email', 'chatmaxima-ai-chatbot'),
            'workspaceTitle' => __('Choose a workspace', 'chatmaxima-ai-chatbot'),
            /* translators: %s: number of workspaces */
            'workspaceIntro' => __('Your account has %s workspaces. Pick the one this site\'s chatbot belongs to. Its conversations and contacts will show up there in the ChatMaxima dashboard.', 'chatmaxima-ai-chatbot'),
            'matchesSite' => __('Matches this site', 'chatmaxima-ai-chatbot'),
            'current' => __('Current', 'chatmaxima-ai-chatbot'),
            /* translators: %s: number of chatbots */
            'nChatbots' => __('%s chatbots', 'chatmaxima-ai-chatbot'),
            'oneChatbot' => __('1 chatbot', 'chatmaxima-ai-chatbot'),
            'noChatbots' => __('No chatbots yet', 'chatmaxima-ai-chatbot'),
            /* translators: %s: number of channels */
            'nChannels' => __('%s channels', 'chatmaxima-ai-chatbot'),
            'oneChannel' => __('1 channel', 'chatmaxima-ai-chatbot'),
            'noChannels' => __('No channels', 'chatmaxima-ai-chatbot'),
            /* translators: %s: email address */
            'workspaceMissing' => __('Don\'t see the right workspace? Ask its owner to invite %s, then', 'chatmaxima-ai-chatbot'),
            'refreshList' => __('refresh this list', 'chatmaxima-ai-chatbot'),
            /* translators: %s: workspace name */
            'continueWith' => __('Continue with %s', 'chatmaxima-ai-chatbot'),
            'back' => __('Back', 'chatmaxima-ai-chatbot'),
            'knowledgeTitle' => __('Teach your chatbot about your site', 'chatmaxima-ai-chatbot'),
            'knowledgeIntro' => __('Your chatbot answers from a knowledge source: a library of your content kept in ChatMaxima. Choose which content to copy into it.', 'chatmaxima-ai-chatbot'),
            'knowledgeSource' => __('Knowledge source', 'chatmaxima-ai-chatbot'),
            'createNewKs' => __('Create a new knowledge source', 'chatmaxima-ai-chatbot'),
            'useExistingKs' => __('Use an existing one', 'chatmaxima-ai-chatbot'),
            'name' => __('Name', 'chatmaxima-ai-chatbot'),
            'nameFromSite' => __('Taken from your site title. You can rename it later.', 'chatmaxima-ai-chatbot'),
            /* translators: %s: number of documents */
            'nDocuments' => __('%s documents', 'chatmaxima-ai-chatbot'),
            'noExistingKs' => __('No knowledge sources in this workspace yet', 'chatmaxima-ai-chatbot'),
            'whatToSync' => __('What to sync', 'chatmaxima-ai-chatbot'),
            /* translators: %s: number of items */
            'itemsSelected' => __('%s items selected.', 'chatmaxima-ai-chatbot'),
            'onlyPublished' => __('Only published, public content is synced. Drafts, private and password-protected items are skipped.', 'chatmaxima-ai-chatbot'),
            'autoSync' => __('Auto Sync', 'chatmaxima-ai-chatbot'),
            'autoSyncHelp' => __('When you publish or update a post, it\'s added to the knowledge source automatically. Trashed posts are removed.', 'chatmaxima-ai-chatbot'),
            'sync' => __('Sync', 'chatmaxima-ai-chatbot'),
            'startSync' => __('Start sync', 'chatmaxima-ai-chatbot'),
            'pause' => __('Pause', 'chatmaxima-ai-chatbot'),
            'resume' => __('Resume', 'chatmaxima-ai-chatbot'),
            /* translators: 1: items synced, 2: items to sync */
            'syncingOf' => __('Syncing... %1$s of %2$s items', 'chatmaxima-ai-chatbot'),
            /* translators: 1: items synced, 2: items to sync */
            'pausedOf' => __('Paused at %1$s of %2$s items', 'chatmaxima-ai-chatbot'),
            /* translators: %s: number of items */
            'syncDone' => __('All %s items synced', 'chatmaxima-ai-chatbot'),
            'syncReady' => __('Ready to sync. Your content is copied in small batches.', 'chatmaxima-ai-chatbot'),
            'syncNothing' => __('Pick at least one content type with published items.', 'chatmaxima-ai-chatbot'),
            'syncKeepOpen' => __('Keep this tab open while it runs. If you leave, sync pauses and picks up where it stopped when you return.', 'chatmaxima-ai-chatbot'),
            'syncFailed' => __('A batch could not be synced:', 'chatmaxima-ai-chatbot'),
            'retry' => __('Retry', 'chatmaxima-ai-chatbot'),
            /* translators: 1: batch number, 2: number of batches */
            'batchOf' => __('Batch %1$s of %2$s', 'chatmaxima-ai-chatbot'),
            'continueUnlocks' => __('Continue unlocks when sync finishes', 'chatmaxima-ai-chatbot'),
            'continueChatbot' => __('Continue to Chatbot', 'chatmaxima-ai-chatbot'),
            'skipSync' => __('Skip, my knowledge source already has this content', 'chatmaxima-ai-chatbot'),
            'chatbotTitle' => __('Create your chatbot', 'chatmaxima-ai-chatbot'),
            'chatbotIntro' => __('Give it a name and a personality, then pick the AI model it uses.', 'chatmaxima-ai-chatbot'),
            'tabPersona' => __('1. Persona', 'chatmaxima-ai-chatbot'),
            'tabModel' => __('2. AI model', 'chatmaxima-ai-chatbot'),
            'botName' => __('Bot name', 'chatmaxima-ai-chatbot'),
            'avatar' => __('Avatar', 'chatmaxima-ai-chatbot'),
            'avatarInitial' => __('Initial', 'chatmaxima-ai-chatbot'),
            'avatarSiteIcon' => __('Site icon', 'chatmaxima-ai-chatbot'),
            'upload' => __('Upload', 'chatmaxima-ai-chatbot'),
            'uploading' => __('Uploading...', 'chatmaxima-ai-chatbot'),
            /* translators: %s: bot name */
            'whatShouldDo' => __('What should %s do?', 'chatmaxima-ai-chatbot'),
            'roleSupport' => __('Support assistant', 'chatmaxima-ai-chatbot'),
            'roleSupportSub' => __('Answers questions from your content', 'chatmaxima-ai-chatbot'),
            'roleSales' => __('Sales assistant', 'chatmaxima-ai-chatbot'),
            'roleSalesSub' => __('Recommends products, guides to checkout', 'chatmaxima-ai-chatbot'),
            'roleBooking' => __('Booking assistant', 'chatmaxima-ai-chatbot'),
            'roleBookingSub' => __('Helps visitors book or place orders', 'chatmaxima-ai-chatbot'),
            'roleOther' => __('Something else', 'chatmaxima-ai-chatbot'),
            'roleOtherSub' => __('Describe it in your own words', 'chatmaxima-ai-chatbot'),
            'roleOtherPlaceholder' => __('What should the chatbot help visitors with?', 'chatmaxima-ai-chatbot'),
            'tone' => __('Tone', 'chatmaxima-ai-chatbot'),
            'toneFriendly' => __('Friendly', 'chatmaxima-ai-chatbot'),
            'toneProfessional' => __('Professional', 'chatmaxima-ai-chatbot'),
            'toneConcise' => __('Concise', 'chatmaxima-ai-chatbot'),
            'welcomeMessage' => __('Welcome message', 'chatmaxima-ai-chatbot'),
            'welcomeHelp' => __('The first thing visitors see when they open the chat.', 'chatmaxima-ai-chatbot'),
            /* translators: 1: bot name, 2: site title */
            'welcomeDefault' => __('Hi, I\'m %1$s from %2$s. Ask me anything about us, and I can connect you to our team if you need a person.', 'chatmaxima-ai-chatbot'),
            'extraInstructions' => __('Extra instructions', 'chatmaxima-ai-chatbot'),
            'optional' => __('(optional)', 'chatmaxima-ai-chatbot'),
            'instructionsHelp' => __('Rules in plain words. You don\'t need any special format.', 'chatmaxima-ai-chatbot'),
            'nextModel' => __('Next: AI model', 'chatmaxima-ai-chatbot'),
            'saving' => __('Saving...', 'chatmaxima-ai-chatbot'),
            'writingPrompt' => __('Saving and writing the chatbot\'s instructions from your content...', 'chatmaxima-ai-chatbot'),
            /* translators: %s: bot name */
            'howWillSound' => __('How %s will sound', 'chatmaxima-ai-chatbot'),
            'tryAgain' => __('Try again', 'chatmaxima-ai-chatbot'),
            'askPreview' => __('Ask a question', 'chatmaxima-ai-chatbot'),
            'previewHelp' => __('Uses your synced content and the settings on the left. Save the persona to update the preview.', 'chatmaxima-ai-chatbot'),
            'previewSaveFirst' => __('Save the persona with "Next: AI model" or "Preview" to try the chatbot here.', 'chatmaxima-ai-chatbot'),
            'previewButton' => __('Preview', 'chatmaxima-ai-chatbot'),
            'previewThinking' => __('Writing a reply...', 'chatmaxima-ai-chatbot'),
            'previewNoAnswer' => __('No reply yet. If your content is still being indexed, try again in a minute.', 'chatmaxima-ai-chatbot'),
            'sampleQ1' => __('What do you offer?', 'chatmaxima-ai-chatbot'),
            'sampleQ2' => __('How can I contact you?', 'chatmaxima-ai-chatbot'),
            'sampleQ3' => __('What are your opening hours?', 'chatmaxima-ai-chatbot'),
            'maxiaTitle' => __('MaxIA V4', 'chatmaxima-ai-chatbot'),
            'recommended' => __('Recommended', 'chatmaxima-ai-chatbot'),
            'freeIncluded' => __('Free · included', 'chatmaxima-ai-chatbot'),
            'maxiaDesc' => __('Built for answering from your own content. Nothing to set up and no API key needed.', 'chatmaxima-ai-chatbot'),
            'orOwnKey' => __('Or use your own API key', 'chatmaxima-ai-chatbot'),
            'providerOpenai' => __('GPT models', 'chatmaxima-ai-chatbot'),
            'providerAnthropic' => __('Claude models', 'chatmaxima-ai-chatbot'),
            'providerGemini' => __('Gemini models', 'chatmaxima-ai-chatbot'),
            'providerGroq' => __('Open models, fast', 'chatmaxima-ai-chatbot'),
            /* translators: %s: provider name */
            'providerKey' => __('%s API key', 'chatmaxima-ai-chatbot'),
            /* translators: %s: provider name */
            'getKey' => __('Get a key from %s', 'chatmaxima-ai-chatbot'),
            'testKey' => __('Test key', 'chatmaxima-ai-chatbot'),
            'testing' => __('Testing...', 'chatmaxima-ai-chatbot'),
            /* translators: %s: provider name */
            'keyWorks' => __('Key works. Connected to %s.', 'chatmaxima-ai-chatbot'),
            /* translators: %s: provider name */
            'keyRejected' => __('%s did not accept this key. Check it and try again.', 'chatmaxima-ai-chatbot'),
            /* translators: %s: provider name */
            'keyUnreachable' => __('Could not reach %s to check the key. Try again in a moment.', 'chatmaxima-ai-chatbot'),
            'savedKey' => __('Saved key', 'chatmaxima-ai-chatbot'),
            'newKey' => __('Add a new key', 'chatmaxima-ai-chatbot'),
            'removeKey' => __('Remove key', 'chatmaxima-ai-chatbot'),
            'model' => __('Model', 'chatmaxima-ai-chatbot'),
            'onlyKeyModels' => __('Only models your key can use are listed.', 'chatmaxima-ai-chatbot'),
            'keyKeptTitle' => __('Where your key is kept:', 'chatmaxima-ai-chatbot'),
            /* translators: %s: workspace name */
            'keyKept' => __('in your ChatMaxima workspace, %s. It is never saved in WordPress, so other plugins and site users can\'t read it. You can remove it any time from this page or the ChatMaxima dashboard.', 'chatmaxima-ai-chatbot'),
            'needKeyTitle' => __('Do I need an API key?', 'chatmaxima-ai-chatbot'),
            'needKey1' => __('No. MaxIA V4 works out of the box and is included with your account.', 'chatmaxima-ai-chatbot'),
            'needKey2' => __('You only need a key if you prefer a model from OpenAI, Anthropic, Google or Groq. A key is like a password from that company that lets ChatMaxima use their model for you.', 'chatmaxima-ai-chatbot'),
            'needKey3' => __('With your own key, that company bills you directly for each reply your chatbot sends.', 'chatmaxima-ai-chatbot'),
            'backPersona' => __('Back to Persona', 'chatmaxima-ai-chatbot'),
            'continueInstall' => __('Continue to Install', 'chatmaxima-ai-chatbot'),
            'testKeyFirst' => __('Test the key first, then pick a model.', 'chatmaxima-ai-chatbot'),
            /* translators: %s: bot name */
            'installTitle' => __('Try %s, then put it on your site', 'chatmaxima-ai-chatbot'),
            'installIntro' => __('The chat bubble in the corner of this page is the real widget, shown only to you. Visitors won\'t see anything until you switch it on.', 'chatmaxima-ai-chatbot'),
            'showOnSite' => __('Show on your site', 'chatmaxima-ai-chatbot'),
            'live' => __('Live', 'chatmaxima-ai-chatbot'),
            'notLive' => __('Not live', 'chatmaxima-ai-chatbot'),
            'onlyAdmins' => __('Only admins see the preview.', 'chatmaxima-ai-chatbot'),
            'visitorsSee' => __('Visitors see the chat bubble.', 'chatmaxima-ai-chatbot'),
            /* translators: %s: site address */
            'switchingOn' => __('Switching on adds the chat bubble to every page of %s. You can switch it off any time.', 'chatmaxima-ai-chatbot'),
            'webChannel' => __('Web channel', 'chatmaxima-ai-chatbot'),
            /* translators: %s: channel name */
            'channelUsing' => __('Chats from your site arrive in the ChatMaxima inbox under the web channel "%s".', 'chatmaxima-ai-chatbot'),
            'settingUpChannel' => __('Setting up the web channel...', 'chatmaxima-ai-chatbot'),
            'useOtherChannel' => __('Use a different web channel', 'chatmaxima-ai-chatbot'),
            'channelLimit' => __('Your plan allows no more web channels. Pick an existing one for this site:', 'chatmaxima-ai-chatbot'),
            /* translators: %s: bot name */
            'answeredBy' => __('Currently answered by %s', 'chatmaxima-ai-chatbot'),
            'noBot' => __('No chatbot yet', 'chatmaxima-ai-chatbot'),
            'useThisChannel' => __('Use this channel', 'chatmaxima-ai-chatbot'),
            'aboutToFinish' => __('You\'re about to finish with', 'chatmaxima-ai-chatbot'),
            'account' => __('Account', 'chatmaxima-ai-chatbot'),
            'chatbot' => __('Chatbot', 'chatmaxima-ai-chatbot'),
            'aiModel' => __('AI model', 'chatmaxima-ai-chatbot'),
            'workspace' => __('Workspace', 'chatmaxima-ai-chatbot'),
            'knowledge' => __('Knowledge', 'chatmaxima-ai-chatbot'),
            'previewLoaded' => __('Preview is open: use the chat bubble at the bottom right of this page.', 'chatmaxima-ai-chatbot'),
            'changeLooks' => __('Change colours and position later in the ChatMaxima dashboard.', 'chatmaxima-ai-chatbot'),
            'goLiveLater' => __('You can go live now or later from the plugin home.', 'chatmaxima-ai-chatbot'),
            'finishSetup' => __('Finish setup', 'chatmaxima-ai-chatbot'),
            'finishing' => __('Finishing...', 'chatmaxima-ai-chatbot'),
            'saveChanges' => __('Save and return to Home', 'chatmaxima-ai-chatbot'),
            'backHome' => __('Back to Home', 'chatmaxima-ai-chatbot'),
            /* translators: 1: bot name, 2: site address */
            'homeWorking' => __('Everything is working. %1$s is answering visitors on %2$s.', 'chatmaxima-ai-chatbot'),
            /* translators: %s: bot name */
            'homeNotLive' => __('%s is ready. Switch on the website widget when you want visitors to see it.', 'chatmaxima-ai-chatbot'),
            'viewSite' => __('View your site', 'chatmaxima-ai-chatbot'),
            'openDashboard' => __('Open ChatMaxima dashboard', 'chatmaxima-ai-chatbot'),
            'connection' => __('Connection', 'chatmaxima-ai-chatbot'),
            'connected' => __('Connected', 'chatmaxima-ai-chatbot'),
            'connectedOn' => __('Connected', 'chatmaxima-ai-chatbot'),
            'differentAccount' => __('Use a different account', 'chatmaxima-ai-chatbot'),
            'disconnect' => __('Disconnect', 'chatmaxima-ai-chatbot'),
            'disconnectConfirm' => __('Disconnect from ChatMaxima? The widget stays as it is until you switch it off.', 'chatmaxima-ai-chatbot'),
            'confirm' => __('Yes, continue', 'chatmaxima-ai-chatbot'),
            'cancel' => __('Cancel', 'chatmaxima-ai-chatbot'),
            'knowledgeSync' => __('Knowledge and sync', 'chatmaxima-ai-chatbot'),
            'upToDate' => __('Up to date', 'chatmaxima-ai-chatbot'),
            'notSynced' => __('Not synced yet', 'chatmaxima-ai-chatbot'),
            'source' => __('Source', 'chatmaxima-ai-chatbot'),
            'syncing' => __('Syncing', 'chatmaxima-ai-chatbot'),
            'lastChange' => __('Last change', 'chatmaxima-ai-chatbot'),
            'on' => __('On', 'chatmaxima-ai-chatbot'),
            'off' => __('Off', 'chatmaxima-ai-chatbot'),
            'syncNow' => __('Sync now', 'chatmaxima-ai-chatbot'),
            'editContentTypes' => __('Edit content types', 'chatmaxima-ai-chatbot'),
            'chatbotModel' => __('Chatbot and AI model', 'chatmaxima-ai-chatbot'),
            'answering' => __('Answering', 'chatmaxima-ai-chatbot'),
            'welcome' => __('Welcome', 'chatmaxima-ai-chatbot'),
            'editPersona' => __('Edit persona', 'chatmaxima-ai-chatbot'),
            'changeModel' => __('Change model', 'chatmaxima-ai-chatbot'),
            'websiteWidget' => __('Website widget', 'chatmaxima-ai-chatbot'),
            'onForAll' => __('On for all visitors', 'chatmaxima-ai-chatbot'),
            'offForAll' => __('Off, only you see the preview', 'chatmaxima-ai-chatbot'),
            'looks' => __('Looks', 'chatmaxima-ai-chatbot'),
            'changeInChatMaxima' => __('Change in ChatMaxima', 'chatmaxima-ai-chatbot'),
            'previewWidget' => __('Preview widget', 'chatmaxima-ai-chatbot'),
            'changeChannel' => __('Change channel', 'chatmaxima-ai-chatbot'),
            'recentActivity' => __('Recent sync activity', 'chatmaxima-ai-chatbot'),
            'when' => __('When', 'chatmaxima-ai-chatbot'),
            'item' => __('Item', 'chatmaxima-ai-chatbot'),
            'type' => __('Type', 'chatmaxima-ai-chatbot'),
            'result' => __('Result', 'chatmaxima-ai-chatbot'),
            'synced' => __('Synced', 'chatmaxima-ai-chatbot'),
            'failed' => __('Failed', 'chatmaxima-ai-chatbot'),
            'noActivity' => __('Nothing synced yet.', 'chatmaxima-ai-chatbot'),
            'startFresh' => __('Need to start fresh?', 'chatmaxima-ai-chatbot'),
            'resetPlugin' => __('Reset plugin and run setup again', 'chatmaxima-ai-chatbot'),
            'resetConfirm' => __('Reset the plugin? This signs out, switches the widget off and starts setup again. Your ChatMaxima account, knowledge source and chatbot are kept.', 'chatmaxima-ai-chatbot'),
            'promptPending' => __('The chatbot\'s instructions follow your persona choices only. Once ChatMaxima has read your content, rewrite them from it.', 'chatmaxima-ai-chatbot'),
            /* translators: %s: reason ChatMaxima could not read the pages */
            'promptUnreadable' => __('The chatbot answers from its persona only, because ChatMaxima could not read any of your synced pages: %s Fix the site address, run Sync now, then rewrite the instructions from your content.', 'chatmaxima-ai-chatbot'),
            'rewritePrompt' => __('Rewrite from my content', 'chatmaxima-ai-chatbot'),
            'promptRewritten' => __('Instructions rewritten from your content.', 'chatmaxima-ai-chatbot'),
            'stillIndexing' => __('ChatMaxima has not read your content yet. Check "Read by ChatMaxima" on the Knowledge card and try again once pages have been read.', 'chatmaxima-ai-chatbot'),
            'botMissing' => __('The chatbot was removed in ChatMaxima. Run the Chatbot step to create a new one.', 'chatmaxima-ai-chatbot'),
            'createChatbot' => __('Create chatbot', 'chatmaxima-ai-chatbot'),
            'saved' => __('Saved', 'chatmaxima-ai-chatbot'),
            'error' => __('Something went wrong. Please try again.', 'chatmaxima-ai-chatbot'),
            'free' => __('free, included', 'chatmaxima-ai-chatbot'),
            'ownKey' => __('own key', 'chatmaxima-ai-chatbot')
        ];
    }
}
