# ChatMaxima AI Chatbot - WordPress Plugin

![WordPress Plugin Version](https://img.shields.io/badge/WordPress-5.0%2B-blue)
![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-purple)
![License](https://img.shields.io/badge/License-GPL%20v2%2B-green)

AI-powered chatbot integration for WordPress with customizable themes, social media support, and knowledge source management.

## Features

- **AI-Powered Conversations** - Advanced chatbot technology for natural interactions
- **Customizable Theme Colors** - Match your brand with custom color schemes
- **Social Media Integration** - Connect Facebook, Instagram, WhatsApp, Telegram, and more
- **Knowledge Source Management** - Create and manage knowledge sources via API
- **Content Sync** - Sync WordPress posts and pages to ChatMaxima knowledge sources
- **Bulk Sync** - Sync all published content with progress tracking
- **Auto-Sync** - Automatically sync new posts when published
- **Easy Configuration** - Simple admin interface for quick setup
- **Responsive Design** - Works perfectly on desktop and mobile devices

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- A ChatMaxima account

## Installation

### From WordPress Admin (Recommended)
1. Download the latest release zip file
2. Go to Plugins > Add New > Upload Plugin
3. Choose the zip file and click "Install Now"
4. Activate the plugin

### Manual Installation
1. Download and extract the plugin files
2. Upload the `chatmaxima-ai-chatbot` folder to `/wp-content/plugins/`
3. Activate the plugin through the WordPress admin

## Configuration

### API Connection
1. Go to **Settings > ChatMaxima Chatbot**
2. Enter your ChatMaxima email and password
3. Click **Connect** to authenticate

### Knowledge Source
1. After connecting, select an existing knowledge source or create a new one
2. Choose the LLM type (OpenAI, Claude, or Gemini)

### Content Sync
1. Select which post types to sync (Posts, Pages, etc.)
2. Enable **Auto Sync** to automatically sync new posts when published
3. Click **Sync All Content** to bulk sync existing content

### Chatbot Widget
1. Enter your ChatMaxima Token ID (required for the widget to appear)
2. Select your preferred theme color
3. Add your social media handles and contact information
4. Save settings

## Plugin Structure

```
chatmaxima-ai-chatbot/
├── chatmaxima-ai-chatbot.php    # Main plugin file
├── readme.txt                    # WordPress.org readme
├── uninstall.php                 # Cleanup on uninstall
├── includes/
│   ├── class-api-client.php      # ChatMaxima API v2 client
│   ├── class-admin-settings.php  # Admin settings page
│   └── class-content-sync.php    # Content sync functionality
├── assets/
│   ├── admin.js                  # Admin JavaScript
│   ├── admin.css                 # Admin styles
│   └── README.md                 # Asset guidelines
└── README.md                     # This file
```

## API Endpoints Used

The plugin uses ChatMaxima API v2:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v2/auth/login/` | POST | User authentication |
| `/api/v2/auth/refresh/` | POST | Token refresh |
| `/api/v2/auth/me/` | GET | Get current user |
| `/api/v2/knowledge-sources/` | POST | List/Create knowledge sources |
| `/api/v2/knowledge-sources/{alias}/` | GET | Get single knowledge source |
| `/api/v2/knowledge-sources/{alias}/training/` | POST | Add training URLs |

## Hooks and Filters

### Actions
- `publish_post` - Triggered when a post is published (auto-sync)
- `publish_page` - Triggered when a page is published (auto-sync)
- `wp_insert_post` - Triggered for custom post types (auto-sync)

### Bulk Actions
- **Sync to ChatMaxima** - Available in Posts and Pages list

### Meta Box
- **ChatMaxima Sync** - Shows sync status on post edit screen
- Option to exclude individual posts from sync

## Development

### Building the Plugin
```bash
# Create distribution zip
zip -r chatmaxima-ai-chatbot.zip chatmaxima-ai-chatbot -x "*.git*"
```

### Testing
1. Install the plugin on a WordPress development site
2. Configure API credentials
3. Test sync functionality with sample posts

## Changelog

### 2.0.0
- Added API v2 authentication (JWT)
- Added knowledge source management
- Added content sync feature
- Added bulk sync with progress tracking
- Added auto-sync on publish
- Added post meta box for sync control
- Added bulk action for syncing multiple posts
- Improved admin UI with card-based layout

### 1.0.0
- Initial release
- AI chatbot widget integration
- Theme color customization
- Social media platform support
- Token-based authentication

## Privacy

This plugin connects to ChatMaxima services to provide chatbot and knowledge source functionality. Please review ChatMaxima's privacy policy for information about data handling.

## Support

- Documentation: [ChatMaxima Docs](https://chatmaxima.com/docs)
- Support Email: support@chatmaxima.com
- Issues: [GitHub Issues](https://github.com/chatmaxima/chatmaxima-wordpress-plugin/issues)

## License

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
```

## About ChatMaxima

ChatMaxima is a leading AI-powered customer engagement platform. Learn more at [chatmaxima.com](https://chatmaxima.com).

---

Made with care by the ChatMaxima Team
