# AKS Integration Plugin

A unified WordPress plugin for All Knox Swim that integrates multiple services and features:

- SendPulse CRM integration
- Quo (OpenPhone) integration  
- DocuSeal document signing integration
- WooCommerce My Account customizations

## Features

### SendPulse/Quo CRM Integration
- Automatically creates contacts in SendPulse and Quo when forms are submitted
- Syncs contact information between both systems
- Stores API response IDs back to Gravity Forms entries
- Updates existing contacts when new information is provided

### DocuSeal Integration
- Creates and sends documents for signing based on form submissions
- Supports custom HTML templates with placeholders
- Handles nested form entries for student information
- Automatically sends documents via email for signature

### WooCommerce Account Customization
- Restructures My Account tabs for swim school workflow
- Adds waiver gating functionality
- Manages user meta fields (waiver status, guardian info, etc.)
- Creates custom endpoints for Students, Lessons, Videos, etc.
- Makes Announcements the default landing page

## Installation

1. Upload the `aks-integration` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to "AKS Integration" in the admin menu to configure settings

## Configuration

### SendPulse Settings
1. Go to AKS Integration > SendPulse
2. Enter your SendPulse API ID and Secret
3. Enter your Quo API Key
4. Specify the Gravity Form ID to monitor

### DocuSeal Settings
1. Go to AKS Integration > DocuSeal
2. Enter your DocuSeal API Token
3. Configure form mappings for each Gravity Form
4. Customize the HTML template

## Requirements

- WordPress 5.0+
- Gravity Forms (required)
- WooCommerce (required for account customization features)
- PHP 7.0+

## Migrating from Individual Plugins

If you're migrating from the individual plugins:

1. **Backup your database and settings**
2. Note down all API credentials and settings from the old plugins
3. Deactivate the old plugins:
   - Gravity Forms SendPulse CRM Integration
   - Gravity Forms DocuSeal Integration
   - WooCommerce Account Customization
4. Delete the old plugins
5. Install and activate AKS Integration
6. Re-enter your settings in the new unified interface
7. Test thoroughly to ensure all integrations are working

## Support

For issues or questions, contact Short Results at https://shortresults.com

## Changelog

### 1.0.0
- Initial release combining all integrations into one plugin
- Unified admin interface
- Updated namespacing and code organization

## License

GPL v2 or later
