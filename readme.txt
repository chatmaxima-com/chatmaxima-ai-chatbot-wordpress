=== ChatMaxima AI Chatbot ===
Contributors: chatmaxima
Tags: chatbot, ai, customer support, live chat, social media, knowledge base
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered chatbot integration for WordPress with knowledge source management and content sync.

== Description ==

ChatMaxima AI Chatbot is a powerful WordPress plugin that integrates an intelligent chatbot into your website and allows you to sync your WordPress content to ChatMaxima knowledge sources for AI training.

= Key Features =

* **AI-Powered Conversations** - Advanced chatbot technology for natural interactions
* **Knowledge Source Management** - Create and manage knowledge sources via API
* **Content Sync** - Sync WordPress posts and pages to ChatMaxima
* **Bulk Sync** - Sync all published content with progress tracking
* **Auto-Sync** - Automatically sync new posts when published
* **Customizable Theme Colors** - Match your brand with custom color schemes
* **Social Media Integration** - Connect Facebook, Instagram, WhatsApp, Telegram, and more
* **Easy Configuration** - Simple admin interface for quick setup
* **Responsive Design** - Works perfectly on desktop and mobile devices

= How It Works =

1. Install and activate the plugin
2. Go to Settings > ChatMaxima Chatbot
3. Connect with your ChatMaxima account credentials
4. Select or create a knowledge source
5. Choose which post types to sync
6. Click "Sync All Content" to train your AI
7. Enter your Token ID for the chatbot widget
8. Configure theme color and social media

= Knowledge Source Features =

* Create new knowledge sources directly from WordPress
* Choose your preferred LLM (OpenAI, Claude, or Gemini)
* Sync individual posts or bulk sync all content
* Auto-sync new posts when published
* Track sync status on each post

= Social Media Platforms Supported =

* Facebook
* Instagram
* Telegram
* WhatsApp
* Email
* Phone
* SMS

= Requirements =

* A ChatMaxima account
* WordPress 5.0 or higher
* PHP 7.4 or higher

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin dashboard
2. Go to Plugins > Add New
3. Search for "ChatMaxima AI Chatbot"
4. Click "Install Now" and then "Activate"

= Manual Installation =

1. Download the plugin zip file
2. Go to Plugins > Add New > Upload Plugin
3. Choose the zip file and click "Install Now"
4. Activate the plugin

= Configuration =

1. Go to Settings > ChatMaxima Chatbot
2. Connect with your ChatMaxima email and password
3. Select or create a knowledge source
4. Choose post types to sync and click "Sync All Content"
5. Enter your Token ID for the chatbot widget
6. Select theme color and add social media handles
7. Save settings

== Frequently Asked Questions ==

= Do I need a ChatMaxima account? =

Yes, you need a ChatMaxima account to use the API features and knowledge source management. The chatbot widget requires a Token ID from your ChatMaxima dashboard.

= What is a knowledge source? =

A knowledge source is where your content is stored and indexed for AI training. When you sync your WordPress posts, they are added to the knowledge source so the AI chatbot can answer questions about your content.

= How does content sync work? =

The plugin sends your published post URLs to ChatMaxima's API. The content is then crawled and indexed into your selected knowledge source for AI training.

= Can I customize the chatbot appearance? =

Yes, you can customize the theme color through the plugin settings to match your brand.

= Which social media platforms are supported? =

The plugin supports Facebook, Instagram, Telegram, WhatsApp, Email, Phone, and SMS integration.

= Will this slow down my website? =

No, the plugin uses lightweight JavaScript that loads asynchronously. Content syncing happens via AJAX in the admin area and won't affect frontend performance.

= Can I exclude certain posts from syncing? =

Yes, each post has a "ChatMaxima Sync" meta box where you can check "Exclude from sync" to prevent that post from being synced.

= Does auto-sync work with custom post types? =

Yes, you can select which post types to sync in the settings. Auto-sync will work for all selected post types.

== Screenshots ==

1. Plugin settings page with API connection
2. Knowledge source management
3. Content sync with progress tracking
4. Post meta box showing sync status
5. Bulk action for syncing multiple posts
6. Chatbot widget settings

== Changelog ==

= 2.0.0 =
* Added API v2 authentication with JWT
* Added knowledge source management
* Added content sync feature
* Added bulk sync with progress tracking
* Added auto-sync on publish
* Added post meta box for sync control
* Added bulk action for syncing multiple posts
* Added ChatMaxima column in posts list
* Improved admin UI with card-based layout

= 1.0.0 =
* Initial release
* AI chatbot widget integration
* Theme color customization
* Social media platform support
* Token-based authentication

== Upgrade Notice ==

= 2.0.0 =
Major update with knowledge source management and content sync features. After upgrading, connect your ChatMaxima account to access new features.

= 1.0.0 =
Initial release of ChatMaxima AI Chatbot plugin.

== Support ==

For support and documentation, visit [ChatMaxima Documentation](https://chatmaxima.com/docs) or contact support@chatmaxima.com.

== Privacy Policy ==

This plugin connects to ChatMaxima services to provide chatbot and knowledge source functionality. When you sync content:
- Post URLs are sent to ChatMaxima for crawling
- Content is indexed for AI training
- No personal user data is collected from your visitors

Please review ChatMaxima's privacy policy for complete information about data handling.
