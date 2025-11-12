<?php
/**
 * Plugin Name: AKS Integration
 * Plugin URI: https://allknoxswim.com
 * Description: Unified integration plugin for AKS - includes SendPulse CRM, Quo, and DocuSeal integrations
 * Version: 1.0.1
 * Author: Short Results
 * Author URI: https://shortresults.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: aks-integration
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('AKS_INTEGRATION_VERSION', '1.0.1');
define('AKS_INTEGRATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AKS_INTEGRATION_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AKS_INTEGRATION_PLUGIN_FILE', __FILE__);

// Main plugin class
class AKS_Integration {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hook into plugins_loaded to ensure all dependencies are ready
        add_action('plugins_loaded', array($this, 'init'), 10);
        
        // Register activation/deactivation hooks
        register_activation_hook(AKS_INTEGRATION_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(AKS_INTEGRATION_PLUGIN_FILE, array($this, 'deactivate'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Check dependencies
        if (!$this->check_dependencies()) {
            return;
        }
        
        // Load text domain
        load_plugin_textdomain('aks-integration', false, dirname(plugin_basename(AKS_INTEGRATION_PLUGIN_FILE)) . '/languages');
        
        // Register user meta
        $this->register_user_meta();
        
        // Load components
        $this->load_components();
        
        // Add user profile fields
        add_action('show_user_profile', array($this, 'show_user_profile_fields'));
        add_action('edit_user_profile', array($this, 'show_user_profile_fields'));
        add_action('personal_options_update', array($this, 'save_user_profile_fields'));
        add_action('edit_user_profile_update', array($this, 'save_user_profile_fields'));
    }
    
    /**
     * Check if required plugins are active
     */
    private function check_dependencies() {
        if (!class_exists('GFForms')) {
            add_action('admin_notices', array($this, 'gravity_forms_missing_notice'));
            return false;
        }
        return true;
    }
    
    /**
     * Admin notice for missing Gravity Forms
     */
    public function gravity_forms_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('AKS Integration requires Gravity Forms to be installed and activated.', 'aks-integration'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Load plugin components
     */
    private function load_components() {
        // Load admin menu
        if (is_admin()) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/class-admin-menu.php';
            new AKS_Integration_Admin_Menu();
        }
        
        // Load SendPulse integration
        if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-sendpulse-form-handler.php')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-sendpulse-api.php';
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-quo-api.php';
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-sendpulse-form-handler.php';
            
            if (is_admin()) {
                require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-sendpulse-admin.php';
            }
            
            new AKS_SendPulse_Form_Handler();
        }
        
        // Load DocuSeal integration
        if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/docuseal/class-docuseal-integration.php')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/docuseal/class-docuseal-integration.php';
            
            if (is_admin()) {
                require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/docuseal/class-docuseal-admin.php';
            }
            
            new AKS_DocuSeal_Integration();
        }
        
        // Load WooCommerce integration if WooCommerce is active
        if (class_exists('WooCommerce')) {
            if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-woocommerce-account-customization.php')) {
                require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-woocommerce-account-customization.php';
                new AKS_WooCommerce_Account_Customization();
            }
        }
    }
    
    /**
     * Register user meta fields
     */
    private function register_user_meta() {
        register_meta('user', 'sendpulse_contact_id', array(
            'type' => 'integer',
            'description' => 'SendPulse Contact ID',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        register_meta('user', 'sendpulse_user_id', array(
            'type' => 'integer',
            'description' => 'SendPulse User ID',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        register_meta('user', 'sendpulse_phone_id', array(
            'type' => 'integer',
            'description' => 'SendPulse Phone ID',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        register_meta('user', 'sendpulse_email_id', array(
            'type' => 'integer',
            'description' => 'SendPulse Email ID',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        register_meta('user', 'quo_contact_id', array(
            'type' => 'string',
            'description' => 'Quo Contact ID',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        register_meta('user', 'quo_phone_id', array(
            'type' => 'string',
            'description' => 'Quo Phone ID',
            'single' => true,
            'show_in_rest' => false,
        ));
    }
    
    /**
     * Display user profile fields
     */
    public function show_user_profile_fields($user) {
        ?>
        <h3>CRM Integration IDs</h3>
        <table class="form-table">
            <tr>
                <th><label for="sendpulse_contact_id">SendPulse Contact ID</label></th>
                <td>
                    <input type="text" name="sendpulse_contact_id" id="sendpulse_contact_id" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'sendpulse_contact_id', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="sendpulse_user_id">SendPulse User ID</label></th>
                <td>
                    <input type="text" name="sendpulse_user_id" id="sendpulse_user_id" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'sendpulse_user_id', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="sendpulse_phone_id">SendPulse Phone ID</label></th>
                <td>
                    <input type="text" name="sendpulse_phone_id" id="sendpulse_phone_id" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'sendpulse_phone_id', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="sendpulse_email_id">SendPulse Email ID</label></th>
                <td>
                    <input type="text" name="sendpulse_email_id" id="sendpulse_email_id" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'sendpulse_email_id', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="quo_contact_id">Quo Contact ID</label></th>
                <td>
                    <input type="text" name="quo_contact_id" id="quo_contact_id" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'quo_contact_id', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="quo_phone_id">Quo Phone ID</label></th>
                <td>
                    <input type="text" name="quo_phone_id" id="quo_phone_id" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'quo_phone_id', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Save user profile fields
     */
    public function save_user_profile_fields($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }
        
        $fields = array(
            'sendpulse_contact_id',
            'sendpulse_user_id',
            'sendpulse_phone_id',
            'sendpulse_email_id',
            'quo_contact_id',
            'quo_phone_id'
        );
        
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_user_meta($user_id, $field, sanitize_text_field($_POST[$field]));
            }
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $sendpulse_defaults = array(
            'api_id' => '',
            'api_secret' => '',
            'quo_api_key' => '',
            'form_id' => ''
        );
        
        if (!get_option('aks_sendpulse_settings')) {
            add_option('aks_sendpulse_settings', $sendpulse_defaults);
        }
        
        $docuseal_defaults = array(
            'api_token' => '',
            'form_mappings' => array(),
            'html_template' => ''
        );
        
        if (!get_option('aks_docuseal_settings')) {
            add_option('aks_docuseal_settings', $docuseal_defaults);
        }
        
        // Add WooCommerce endpoints if WooCommerce is active
        if (class_exists('WooCommerce')) {
            $endpoints = array(
                'students',
                'lessons',
                'documents',
                'videos',
                'purchases',
                'store-credit',
                'announcements',
                'delete-account'
            );
            
            foreach ($endpoints as $endpoint) {
                add_rewrite_endpoint($endpoint, EP_ROOT | EP_PAGES);
            }
            
            // Add capability
            $role = get_role('customer');
            if ($role && !$role->has_cap('sr_view_lesson_library')) {
                $role->add_cap('sr_view_lesson_library');
            }
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear transients
        delete_transient('aks_sendpulse_access_token');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
}

// Initialize the plugin
AKS_Integration::get_instance();
