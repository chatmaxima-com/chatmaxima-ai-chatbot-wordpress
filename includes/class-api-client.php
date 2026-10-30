<?php
/**
 * ChatMaxima API Client
 *
 * Handles all API communication with ChatMaxima API v2
 *
 * @package ChatMaxima AI Chatbot
 */

// Prevent direct access
if (!defined('ABSPATH'))
{
    exit;
}

class ChatMaxima_API_Client
{
    private $api_base_url = 'https://chatmaxima.com/api/v2/';
    private $access_token = null;
    private $refresh_token = null;
    private $token_expiry = null;
    private static $is_refreshing = false; // Prevent concurrent token refresh

    public function __construct()
    {
        $this->load_tokens();
    }

    /**
     * Load stored tokens from WordPress options
     */
    private function load_tokens()
    {
        $this->access_token = get_option('chatmaxima_access_token', '');
        $this->refresh_token = get_option('chatmaxima_refresh_token', '');
        $this->token_expiry = get_option('chatmaxima_token_expiry', 0);
    }

    /**
     * Save tokens to WordPress options
     */
    private function save_tokens($access_token, $refresh_token, $expires_in)
    {
        $this->access_token = $access_token;
        $this->refresh_token = $refresh_token;
        $this->token_expiry = time() + $expires_in - 60; // Subtract 60 seconds buffer

        update_option('chatmaxima_access_token', $access_token, false); // never autoloaded: not in alloptions on every request
        update_option('chatmaxima_refresh_token', $refresh_token, false);
        update_option('chatmaxima_token_expiry', $this->token_expiry, false);
    }

    /**
     * Clear stored tokens
     */
    public function clear_tokens()
    {
        $this->access_token = null;
        $this->refresh_token = null;
        $this->token_expiry = null;

        delete_option('chatmaxima_access_token');
        delete_option('chatmaxima_refresh_token');
        delete_option('chatmaxima_token_expiry');
    }

    /**
     * Check if we have a valid access token
     */
    public function is_authenticated()
    {
        if (empty($this->access_token))
        {
            return false;
        }

        // Check if token is expired
        if ($this->token_expiry && time() >= $this->token_expiry)
        {
            // If another request is already refreshing, wait and reload tokens
            if (self::$is_refreshing)
            {
                // Wait briefly for the other refresh to complete
                usleep(500000); // 0.5 seconds
                $this->load_tokens(); // Reload tokens that may have been refreshed
                return !empty($this->access_token) && time() < $this->token_expiry;
            }

            // Try to refresh the token
            return $this->refresh_access_token();
        }

        return true;
    }

    /**
     * Login with email and password
     */
    public function login($email, $password)
    {
        $response = $this->make_request('auth/login/', 'POST', [
            'email' => $email,
            'password' => $password,
            'remember_me' => true
        ], false); // Don't require auth for login

        if (is_wp_error($response))
        {
            return $response;
        }

        if (is_array($response) && isset($response['status']) && $response['status'] === 'success' && isset($response['data']['access_token'], $response['data']['refresh_token'], $response['data']['expires_in']))
        {
            $data = $response['data'];
            $this->save_tokens(
                $data['access_token'],
                $data['refresh_token'],
                $data['expires_in']
            );

            // Store user info
            if (isset($data['user']))
            {
                update_option('chatmaxima_user_info', $data['user'], false);
            }

            return true;
        }

        $error_message = 'Login failed';
        $error_code = 'login_failed';
        if (isset($response['error']['message']))
        {
            $error_message = $response['error']['message'];
        }
        if (isset($response['error']['code']))
        {
            $error_code = $response['error']['code']; // ACCOUNT_INACTIVE: a plugin sign-up still waiting for its email code
        }
        return new WP_Error($error_code, $error_message);
    }

    /**
     * Activate a plugin sign-up with the code ChatMaxima emailed, and connect it
     */
    public function verify_signup($email, $code)
    {
        $response = $this->make_request('auth/verify-signup/', 'POST', ['email' => $email, 'code' => $code], false);

        if (is_wp_error($response))
        {
            return $response;
        }

        if (is_array($response) && isset($response['status']) && $response['status'] === 'success' && isset($response['data']['access_token'], $response['data']['refresh_token'], $response['data']['expires_in']))
        {
            $data = $response['data'];
            $this->save_tokens($data['access_token'], $data['refresh_token'], $data['expires_in']);
            if (isset($data['user']))
            {
                update_option('chatmaxima_user_info', $data['user'], false);
            }
            return true;
        }

        $error_message = 'That code did not work';
        $error_code = 'verify_failed';
        if (isset($response['error']['message']))
        {
            $error_message = $response['error']['message'];
        }
        if (isset($response['error']['code']))
        {
            $error_code = $response['error']['code'];
        }
        return new WP_Error($error_code, $error_message);
    }

    /**
     * Email a new sign-up code
     */
    public function resend_signup_code($email)
    {
        $response = $this->make_request('auth/resend-signup-code/', 'POST', ['email' => $email], false);

        if (is_wp_error($response))
        {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === 'success')
        {
            return true;
        }

        $error_message = 'Could not send a new code';
        if (isset($response['error']['message']))
        {
            $error_message = $response['error']['message'];
        }
        return new WP_Error('resend_failed', $error_message);
    }

    /**
     * Create a ChatMaxima account and connect it (the API returns tokens like a login)
     */
    public function register($name, $email, $password, $workspace_name = '')
    {
        $response = $this->make_request('auth/register/', 'POST', [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'signup_source' => 'wordpress', // Plugin sign-ups start on the Free plan
            'workspace_name' => $workspace_name // Workspace named after the site
        ], false); // No token yet

        if (is_wp_error($response))
        {
            return $response;
        }

        if (is_array($response) && isset($response['status']) && $response['status'] === 'success' && !empty($response['data']['verification_required']))
        {
            return ['verification_required' => true, 'email' => $email]; // the account activates with the emailed code (verify_signup)
        }

        if (is_array($response) && isset($response['status']) && $response['status'] === 'success' && isset($response['data']['access_token'], $response['data']['refresh_token'], $response['data']['expires_in']))
        {
            $data = $response['data'];
            $this->save_tokens(
                $data['access_token'],
                $data['refresh_token'],
                $data['expires_in']
            );

            if (isset($data['user']))
            {
                update_option('chatmaxima_user_info', $data['user'], false);
            }

            return true;
        }

        $error_message = 'Could not create the account';
        $error_code = 'register_failed';
        if (isset($response['error']['message']))
        {
            $error_message = $response['error']['message'];
        }
        if (isset($response['error']['code']))
        {
            $error_code = $response['error']['code']; // EMAIL_EXISTS lets the form offer "Sign in instead"
        }
        return new WP_Error($error_code, $error_message);
    }

    /**
     * Refresh the access token using refresh token
     */
    private function refresh_access_token()
    {
        if (empty($this->refresh_token))
        {
            return false;
        }

        // Prevent concurrent token refresh
        if (self::$is_refreshing)
        {
            // Wait and reload tokens
            usleep(500000); // 0.5 seconds
            $this->load_tokens();
            return !empty($this->access_token) && time() < $this->token_expiry;
        }

        self::$is_refreshing = true;

        $response = $this->make_request('auth/refresh/', 'POST', [
            'refresh_token' => $this->refresh_token,
            'remember_me' => true
        ], false);

        if (is_wp_error($response))
        {
            self::$is_refreshing = false;
            $this->clear_tokens();
            return false;
        }

        if (isset($response['status']) && $response['status'] === 'success')
        {
            $data = $response['data'];
            $this->save_tokens(
                $data['access_token'],
                $data['refresh_token'],
                $data['expires_in']
            );
            self::$is_refreshing = false;
            return true;
        }

        self::$is_refreshing = false;
        $this->clear_tokens();
        return false;
    }

    /**
     * Get current user info
     */
    public function get_current_user()
    {
        $response = $this->make_request('auth/me/', 'GET');

        if (is_wp_error($response))
        {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === 'success')
        {
            return $response['data'];
        }

        return new WP_Error('user_fetch_failed', 'Failed to fetch user info');
    }

    /**
     * List all knowledge sources
     */
    public function list_knowledge_sources($status = 'Y', $crawl_type = null)
    {
        $body = ['status' => $status];
        if ($crawl_type)
        {
            $body['crawl_type'] = $crawl_type;
        }

        $response = $this->make_request('knowledge-sources/', 'POST', $body);

        if (is_wp_error($response))
        {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === 'success')
        {
            return $response['data'];
        }

        return new WP_Error('fetch_failed', 'Failed to fetch knowledge sources');
    }

    /**
     * Get single knowledge source by alias
     */
    public function get_knowledge_source($alias)
    {
        $response = $this->make_request('knowledge-sources/' . rawurlencode($alias) . '/', 'GET');

        if (is_wp_error($response))
        {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === 'success')
        {
            return $response['data'];
        }

        return new WP_Error('fetch_failed', 'Failed to fetch knowledge source');
    }

    /**
     * Create a new knowledge source
     */
    public function create_knowledge_source($name, $llm_type = 'openai', $crawl_type = 'web', $integration_id = null)
    {
        $body = [
            'name' => $name,
            'llm_type' => $llm_type,
            'crawl_type' => $crawl_type
        ];

        if ($integration_id)
        {
            $body['integration_id'] = $integration_id;
        }

        $response = $this->make_request('knowledge-sources/', 'POST', $body);

        if (is_wp_error($response))
        {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === 'success')
        {
            return $response['data'];
        }

        $error_message = isset($response['error']['message']) ? $response['error']['message'] : 'Failed to create knowledge source';
        return new WP_Error('create_failed', $error_message);
    }

    /**
     * Add training URLs to knowledge source
     */
    public function add_training_urls($alias, $urls)
    {
        if (!is_array($urls))
        {
            $urls = [$urls];
        }

        $response = $this->make_request('knowledge-sources/' . rawurlencode($alias) . '/training/', 'POST', [
            'urls' => $urls
        ]);

        if (is_wp_error($response))
        {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === 'success')
        {
            return $response['data'];
        }

        $error_message = 'Failed to add training URLs';
        $error_code = 'add_training_failed';
        $error_data = null;
        if (isset($response['error']['message']))
        {
            $error_message = $response['error']['message'];
        }
        if (isset($response['error']['code']))
        {
            $error_code = $response['error']['code']; // PLAN_PAGE_LIMIT: the Free plan's page cap is reached
        }
        if (isset($response['error']['details']))
        {
            $error_data = $response['error']['details']; // limit, used, upgrade_url
        }
        return new WP_Error($error_code, $error_message, $error_data);
    }

    /**
     * Get training content for knowledge source
     */
    public function get_training_content($alias)
    {
        $response = $this->make_request('knowledge-sources/' . rawurlencode($alias) . '/training/', 'GET');

        if (is_wp_error($response))
        {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === 'success')
        {
            return $response['data'];
        }

        return new WP_Error('fetch_failed', 'Failed to fetch training content');
    }

    /**
     * Delete training content from knowledge source
     */
    public function delete_training_content($alias, $training_id)
    {
        $response = $this->make_request('knowledge-sources/' . rawurlencode($alias) . '/training/' . rawurlencode((string) $training_id) . '/', 'DELETE');

        if (is_wp_error($response))
        {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === 'success')
        {
            return true;
        }

        $error_message = isset($response['error']['message']) ? $response['error']['message'] : 'Failed to delete training content';
        return new WP_Error('delete_failed', $error_message);
    }

    /**
     * Make HTTP request to ChatMaxima API
     */
    private function make_request($endpoint, $method = 'GET', $body = null, $require_auth = true, $timeout = 30)
    {
        $url = $this->api_base_url . $endpoint;

        $args = [
            'method' => $method,
            'timeout' => $timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ];

        // Add authorization header if required
        if ($require_auth)
        {
            if (!$this->is_authenticated())
            {
                return new WP_Error('not_authenticated', 'Not authenticated. Please login first.');
            }
            $args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
        }

        // Add body for POST/PUT/DELETE requests
        if ($body && in_array($method, ['POST', 'PUT', 'DELETE']))
        {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response))
        {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Handle authentication errors
        if ($status_code === 401 && $require_auth)
        {
            // Try to refresh token
            if ($this->refresh_access_token())
            {
                // Retry the request with new token
                $args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
                $response = wp_remote_request($url, $args);

                if (is_wp_error($response))
                {
                    return $response;
                }

                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
            }
            else
            {
                return new WP_Error('auth_expired', 'Authentication expired. Please login again.');
            }
        }

        return $data;
    }

    /**
     * Test API connection
     */
    public function test_connection()
    {
        if (!$this->is_authenticated())
        {
            return new WP_Error('not_authenticated', 'Not authenticated');
        }

        $user = $this->get_current_user();

        if (is_wp_error($user))
        {
            return $user;
        }

        return [
            'success' => true,
            'user' => $user
        ];
    }

    /**
     * List all teams for the current user
     */
    public function list_teams()
    {
        $response = $this->make_request('teams/', 'POST', ['_' => 1]); // Send minimal body for POST

        if (is_wp_error($response))
        {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === 'success')
        {
            return $response['data'];
        }

        // Return more detailed error if available
        $error_message = 'Failed to fetch workspaces';
        if (isset($response['error']['message']))
        {
            $error_message = $response['error']['message'];
        }
        elseif (isset($response['message']))
        {
            $error_message = $response['message'];
        }

        return new WP_Error('fetch_failed', $error_message);
    }

    /**
     * Switch to a different team
     * Returns new JWT tokens for the selected team
     */
    public function switch_team($team_alias)
    {
        $response = $this->make_request('teams/switch/', 'POST', [
            'team_alias' => $team_alias
        ]);

        if (is_wp_error($response))
        {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === 'success')
        {
            $data = $response['data'];

            // Save new tokens for the switched team
            $this->save_tokens(
                $data['access_token'],
                $data['refresh_token'],
                $data['expires_in']
            );

            // Update stored user info with new team data
            if (isset($data['user']))
            {
                $user_info = $data['user'];
                if (isset($data['team']))
                {
                    $user_info['team_id'] = $data['team']['team_id'];
                    $user_info['team_alias'] = $data['team']['team_alias'];
                    $user_info['team_name'] = $data['team']['team_name'];
                }
                update_option('chatmaxima_user_info', $user_info, false);
            }

            // Store selected team alias
            update_option('chatmaxima_selected_team', $team_alias);

            // Clear knowledge sources cache since team changed
            delete_transient('chatmaxima_knowledge_sources');

            return $data;
        }

        $error_message = isset($response['error']['message']) ? $response['error']['message'] : 'Failed to switch workspace';
        return new WP_Error('switch_failed', $error_message);
    }

    /**
     * Get currently selected team alias
     */
    public function get_selected_team()
    {
        return get_option('chatmaxima_selected_team', '');
    }

    /**
     * List all channels/accounts
     * @param string $platform Optional platform filter (web, whatsapp, instagram, facebook, telegram, ticket)
     * @param string $status Optional status filter (Y = active, N = inactive)
     */
    public function list_channels($platform = null, $status = 'Y')
    {
        $body = [];

        if ($platform)
        {
            $body['platform'] = $platform;
        }

        if ($status)
        {
            $body['status'] = $status;
        }

        $response = $this->make_request('channels/', 'POST', $body);

        if (is_wp_error($response))
        {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === 'success')
        {
            return $response['data'];
        }

        $error_message = isset($response['error']['message']) ? $response['error']['message'] : 'Failed to fetch channels';
        return new WP_Error('fetch_failed', $error_message);
    }

    /**
     * Call an API v2 endpoint and return its data, or a WP_Error carrying the API's error code
     * (for example CHANNEL_LIMIT_REACHED) so the setup wizard can react to it
     */
    private function api_call($endpoint, $method = 'GET', $body = null, $timeout = 30)
    {
        $response = $this->make_request($endpoint, $method, $body, true, $timeout);

        if (is_wp_error($response))
        {
            return $response;
        }

        if (is_array($response) && isset($response['status']) && $response['status'] === 'success')
        {
            if (isset($response['data']) && is_array($response['data']))
            {
                return $response['data'];
            }
            return [];
        }

        $code = 'api_error';
        $message = __('ChatMaxima did not accept the request. Please try again.', 'chatmaxima-ai-chatbot');
        if (isset($response['error']['code']))
        {
            $code = $response['error']['code'];
        }
        if (isset($response['error']['message']))
        {
            $message = $response['error']['message'];
        }
        return new WP_Error($code, $message);
    }

    /**
     * Workspaces with the user's role and chatbot and channel counts
     */
    public function list_teams_with_details()
    {
        return $this->api_call('teams/', 'POST', ['details' => true]);
    }

    /**
     * Create a persona chatbot on a knowledge source (the system prompt is written by an AI call, so allow time)
     */
    public function create_chatbot($data)
    {
        return $this->api_call('chatbots/', 'POST', $data, 90);
    }

    public function get_chatbot($bot_alias)
    {
        return $this->api_call('chatbots/' . rawurlencode($bot_alias) . '/', 'GET');
    }

    public function update_chatbot($bot_alias, $data)
    {
        return $this->api_call('chatbots/' . rawurlencode($bot_alias) . '/', 'POST', $data, 90);
    }

    /**
     * A sample reply from the saved chatbot (a real AI reply, so allow time)
     */
    public function preview_chatbot($bot_alias, $question)
    {
        return $this->api_call('chatbots/' . rawurlencode($bot_alias) . '/preview/', 'POST', ['question' => $question], 75);
    }

    /**
     * Upload an avatar image (raw bytes), returns ['avatar_url' => ...]
     */
    public function upload_avatar($binary)
    {
        return $this->api_call('chatbots/avatar/', 'POST', ['image' => base64_encode($binary)], 45); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API takes the image as base64 JSON
    }

    public function list_ai_keys()
    {
        return $this->api_call('ai-keys/', 'GET');
    }

    /**
     * Add a provider key to the workspace. The key goes to ChatMaxima only; it is never stored in WordPress.
     */
    public function add_ai_key($provider, $api_key, $name = '')
    {
        return $this->api_call('ai-keys/', 'POST', ['provider' => $provider, 'api_key' => $api_key, 'name' => $name]);
    }

    public function test_ai_key($provider, $api_key, $credential_id = '')
    {
        $body = ['provider' => $provider, 'api_key' => $api_key];
        if ($credential_id !== '')
        {
            $body = ['credential_id' => $credential_id];
        }
        return $this->api_call('ai-keys/test/', 'POST', $body);
    }

    public function delete_ai_key($credential_id)
    {
        return $this->api_call('ai-keys/' . rawurlencode($credential_id) . '/', 'DELETE', ['_' => 1]);
    }

    public function list_ai_models($provider)
    {
        return $this->api_call('ai-models/?provider=' . rawurlencode($provider), 'GET');
    }

    /**
     * Put the chatbot on this site: reuse or create the web channel, attach the bot, set its name and avatar
     */
    public function attach_web_channel($data)
    {
        return $this->api_call('channels/web/', 'POST', $data);
    }

    /**
     * Get single channel by alias
     */
    public function get_channel($alias)
    {
        $response = $this->make_request('channels/' . rawurlencode($alias) . '/', 'GET');

        if (is_wp_error($response))
        {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === 'success')
        {
            return $response['data'];
        }

        return new WP_Error('fetch_failed', 'Failed to fetch channel');
    }
}
