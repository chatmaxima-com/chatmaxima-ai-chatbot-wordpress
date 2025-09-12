# ChatMaxima AI Chatbot - WordPress Plugin

![WordPress Plugin Version](https://img.shields.io/badge/WordPress-5.0%2B-blue)
![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-purple)
![License](https://img.shields.io/badge/License-GPL%20v2%2B-green)

AI-powered chatbot integration for WordPress with customizable themes and social media support.

## 🚀 Features

- **AI-Powered Conversations** - Advanced chatbot technology for natural interactions
- **Customizable Theme Colors** - Match your brand with custom color schemes
- **Social Media Integration** - Connect Facebook, Instagram, WhatsApp, Telegram, and more
- **Easy Configuration** - Simple admin interface for quick setup
- **Responsive Design** - Works perfectly on desktop and mobile devices
- **Contact Integration** - Include email, phone, and SMS contact options

## 📋 Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- A ChatMaxima account and Token ID

## 🔧 Installation

### From WordPress Admin (Recommended)
1. Download the latest release zip file
2. Go to Plugins → Add New → Upload Plugin
3. Choose the zip file and click "Install Now"
4. Activate the plugin

### Manual Installation
1. Download and extract the plugin files
2. Upload the `chatmaxima-ai-chatbot` folder to `/wp-content/plugins/`
3. Activate the plugin through the WordPress admin

## ⚙️ Configuration

1. Go to **Settings → ChatMaxima Chatbot**
2. Enter your ChatMaxima Token ID (required)
3. Select your preferred theme color
4. Add your social media handles and contact information
5. Save settings

The chatbot will automatically appear on your website footer once configured.

## 🔗 Social Media Platforms Supported

- Facebook
- Instagram
- Telegram
- WhatsApp
- Email
- Phone
- SMS

## 📸 Screenshots

*Screenshots will be available after WordPress.org submission*

## 🛠️ Development

### Plugin Structure
```
chatmaxima-ai-chatbot/
├── chatmaxima-ai-chatbot.php  # Main plugin file
├── readme.txt                 # WordPress.org readme
├── uninstall.php             # Cleanup on uninstall
├── assets/
│   ├── admin.js              # Admin color picker
│   └── README.md             # Asset guidelines
└── README.md                 # This file
```

### Hooks and Filters

The plugin uses standard WordPress hooks:
- `admin_menu` - Adds settings page
- `admin_init` - Registers settings
- `wp_footer` - Outputs chatbot script
- `admin_enqueue_scripts` - Loads admin assets

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📝 Changelog

### 1.0.0
- Initial release
- AI chatbot integration
- Theme color customization
- Social media platform support
- Admin settings interface
- Token-based authentication

## 🔒 Privacy

This plugin connects to ChatMaxima services to provide chatbot functionality. Please review ChatMaxima's privacy policy for information about data handling.

## 📞 Support

- Documentation: [ChatMaxima Docs](https://chatmaxima.com/docs)
- Support Email: support@chatmaxima.com
- Issues: [GitHub Issues](https://github.com/chatmaxima/chatmaxima-wordpress-plugin/issues)

## 📄 License

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
```

## 🏢 About ChatMaxima

ChatMaxima is a leading AI-powered customer engagement platform. Learn more at [chatmaxima.com](https://chatmaxima.com).

---

Made with ❤️ by the ChatMaxima Team