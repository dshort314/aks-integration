<?php
/**
 * Plugin Name: AKS Integration
 * Plugin URI: https://allknoxswim.com
 * Description: Unified integration plugin for AKS - includes SendPulse CRM, Quo, and DocuSeal integrations
 * Version: 1.0.2
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
define('AKS_INTEGRATION_VERSION', '1.0.2');
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
        // Load text domain
        load_plugin_textdomain('aks-integration', false, dirname(plugin_basename(AKS_INTEGRATION_PLUGIN_FILE)) . '/languages');
        
        // Always load the API Logger
        if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/class-api-logger.php')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/class-api-logger.php';
            AKS_API_Logger::get_instance();
        }
        
        // Always load the DocuSeal webhook handler (doesn't require Gravity Forms)
        if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/docuseal/class-docuseal-webhook-handler.php')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/docuseal/class-docuseal-webhook-handler.php';
            new AKS_DocuSeal_Webhook_Handler();
        }
        
        // Check dependencies for remaining components
        if (!$this->check_dependencies()) {
            return;
        }
        
        // Register ALL user meta in one place
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
        // Note: API Logger and DocuSeal Webhook Handler are loaded in init() before dependency check
        
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
        
        // Load Student Note Sync (syncs student data to SendPulse notes)
        if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-student-note-sync.php')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-student-note-sync.php';
            AKS_Student_Note_Sync::get_instance();
        }
        
        // Load DocuSeal integration (webhook handler already loaded in init())
        if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/docuseal/class-docuseal-integration.php')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/docuseal/class-docuseal-integration.php';
            
            if (is_admin()) {
                require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/docuseal/class-docuseal-admin.php';
            }
            
            new AKS_DocuSeal_Integration();
        }
        
		/**
		 * Load User Registration logic (updates entry with user ID + modifies confirmation redirect)
		 */
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-aks-user-registration.php';


        // Load CRM Sync Handler (syncs profile changes to SendPulse/Quo with retry queue)
        if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/class-crm-sync-handler.php')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/class-crm-sync-handler.php';
            AKS_CRM_Sync_Handler::get_instance();
        }
        
        // Load WooCommerce integration if WooCommerce is active
        if (class_exists('WooCommerce')) {
            if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-woocommerce-account-customization.php')) {
                require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-woocommerce-account-customization.php';
                new AKS_WooCommerce_Account_Customization();
            }
            
            // Load Payment Discount Handler
            if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-payment-discount-handler.php')) {
                require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-payment-discount-handler.php';
                AKS_Payment_Discount_Handler::get_instance();
            }
            
            // Load Donated Lessons Handler
            if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-donated-lessons-handler.php')) {
                require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-donated-lessons-handler.php';
                AKS_Donated_Lessons_Handler::get_instance();
            }
        }
    }
    
    /**
     * Register ALL user meta fields in one place
     */
    private function register_user_meta() {
        // SendPulse/Quo CRM meta fields
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
        
        register_meta('user', 'sendpulse_comment_id', array(
            'type' => 'integer',
            'description' => 'SendPulse Comment/Note ID',
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
        
        // WooCommerce/Account meta fields
        register_meta('user', 'sr_registration_form_complete', array(
            'type' => 'string',
            'description' => 'Registration Form 2 Complete',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        register_meta('user', 'sr_waiver_signed', array(
            'type' => 'string',
            'description' => 'Waiver Signed Status',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        register_meta('user', 'sr_guardian_email', array(
            'type' => 'string',
            'description' => 'Guardian Email',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        register_meta('user', 'sr_lesson_library_access', array(
            'type' => 'string',
            'description' => 'Lesson Library Access',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        register_meta('user', 'sr_store_credit_balance', array(
            'type' => 'string',
            'description' => 'Store Credit Balance',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        register_meta('user', 'sr_is_parent_guardian', array(
            'type' => 'string',
            'description' => 'Is Parent/Guardian',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        // DocuSeal meta field
        register_meta('user', 'docuseal_url', array(
            'type' => 'string',
            'description' => 'DocuSeal Document URL',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        // Entry ID tracking meta fields
        register_meta('user', 'aks_form_1_entry_id', array(
            'type' => 'integer',
            'description' => 'Form 1 (GF ID 2) Entry ID',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        register_meta('user', 'aks_form_2_entry_id', array(
            'type' => 'integer',
            'description' => 'Form 2 (GF ID 3) Entry ID',
            'single' => true,
            'show_in_rest' => false,
        ));
    }
    
    /**
     * Display user profile fields
     */
    public function show_user_profile_fields($user) {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }
        
        ?>
        <h2><?php esc_html_e('All Knox Swim – CRM Integration', 'aks-integration'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sendpulse_contact_id"><?php esc_html_e('SendPulse Contact ID', 'aks-integration'); ?></label></th>
                <td>
                    <input type="text" name="sendpulse_contact_id" id="sendpulse_contact_id" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'sendpulse_contact_id', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="sendpulse_user_id"><?php esc_html_e('SendPulse User ID', 'aks-integration'); ?></label></th>
                <td>
                    <input type="text" name="sendpulse_user_id" id="sendpulse_user_id" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'sendpulse_user_id', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="sendpulse_phone_id"><?php esc_html_e('SendPulse Phone ID', 'aks-integration'); ?></label></th>
                <td>
                    <input type="text" name="sendpulse_phone_id" id="sendpulse_phone_id" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'sendpulse_phone_id', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="sendpulse_email_id"><?php esc_html_e('SendPulse Email ID', 'aks-integration'); ?></label></th>
                <td>
                    <input type="text" name="sendpulse_email_id" id="sendpulse_email_id" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'sendpulse_email_id', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="sendpulse_comment_id"><?php esc_html_e('SendPulse Comment ID', 'aks-integration'); ?></label></th>
                <td>
                    <input type="text" name="sendpulse_comment_id" id="sendpulse_comment_id" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'sendpulse_comment_id', true)); ?>" 
                           class="regular-text" />
                    <p class="description"><?php esc_html_e('ID of the student information note in SendPulse', 'aks-integration'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="quo_contact_id"><?php esc_html_e('Quo Contact ID', 'aks-integration'); ?></label></th>
                <td>
                    <input type="text" name="quo_contact_id" id="quo_contact_id" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'quo_contact_id', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="quo_phone_id"><?php esc_html_e('Quo Phone ID', 'aks-integration'); ?></label></th>
                <td>
                    <input type="text" name="quo_phone_id" id="quo_phone_id" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'quo_phone_id', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e('All Knox Swim – Account Meta', 'aks-integration'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sr_registration_form_complete"><?php esc_html_e('Registration Form 2 Complete', 'aks-integration'); ?></label></th>
                <td>
                    <?php $form_complete = get_user_meta($user->ID, 'sr_registration_form_complete', true); ?>
                    <?php if ($form_complete === '') $form_complete = 'no'; ?>
                    <select name="sr_registration_form_complete" id="sr_registration_form_complete">
                        <option value="no"  <?php selected($form_complete, 'no'); ?>><?php esc_html_e('No', 'aks-integration'); ?></option>
                        <option value="yes" <?php selected($form_complete, 'yes'); ?>><?php esc_html_e('Yes', 'aks-integration'); ?></option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th><label for="sr_waiver_signed"><?php esc_html_e('Waiver Signed', 'aks-integration'); ?></label></th>
                <td>
                    <select name="sr_waiver_signed" id="sr_waiver_signed">
                        <option value="no"  <?php selected(get_user_meta($user->ID, 'sr_waiver_signed', true), 'no'); ?>><?php esc_html_e('No', 'aks-integration'); ?></option>
                        <option value="yes" <?php selected(get_user_meta($user->ID, 'sr_waiver_signed', true), 'yes'); ?>><?php esc_html_e('Yes', 'aks-integration'); ?></option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th><label for="sr_is_parent_guardian"><?php esc_html_e('Is Parent/Guardian', 'aks-integration'); ?></label></th>
                <td>
                    <?php $is_parent = get_user_meta($user->ID, 'sr_is_parent_guardian', true); ?>
                    <?php if ($is_parent === '') $is_parent = 'yes'; ?>
                    <select name="sr_is_parent_guardian" id="sr_is_parent_guardian">
                        <option value="no"  <?php selected($is_parent, 'no'); ?>><?php esc_html_e('No', 'aks-integration'); ?></option>
                        <option value="yes" <?php selected($is_parent, 'yes'); ?>><?php esc_html_e('Yes', 'aks-integration'); ?></option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th><label for="sr_guardian_email"><?php esc_html_e('Guardian Email', 'aks-integration'); ?></label></th>
                <td><input type="text" name="sr_guardian_email" id="sr_guardian_email" value="<?php echo esc_attr(get_user_meta($user->ID, 'sr_guardian_email', true)); ?>" class="regular-text"></td>
            </tr>
            
            <tr>
                <th><label for="sr_lesson_library_access"><?php esc_html_e('Lesson Library Access', 'aks-integration'); ?></label></th>
                <td>
                    <select name="sr_lesson_library_access" id="sr_lesson_library_access">
                        <option value="no"  <?php selected(get_user_meta($user->ID, 'sr_lesson_library_access', true), 'no'); ?>><?php esc_html_e('No', 'aks-integration'); ?></option>
                        <option value="yes" <?php selected(get_user_meta($user->ID, 'sr_lesson_library_access', true), 'yes'); ?>><?php esc_html_e('Yes', 'aks-integration'); ?></option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th><label for="sr_store_credit_balance"><?php esc_html_e('Store Credit Balance', 'aks-integration'); ?></label></th>
                <td>
                    <?php $credit = get_user_meta($user->ID, 'sr_store_credit_balance', true); ?>
                    <input type="number" step="0.01" min="0" name="sr_store_credit_balance" id="sr_store_credit_balance" value="<?php echo esc_attr($credit !== '' ? $credit : '0.00'); ?>" class="regular-text">
                </td>
            </tr>
            
            <tr>
                <th><label for="docuseal_url"><?php esc_html_e('DocuSeal Document URL', 'aks-integration'); ?></label></th>
                <td>
                    <input type="url" name="docuseal_url" id="docuseal_url" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'docuseal_url', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e('All Knox Swim – Entry Tracking', 'aks-integration'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="aks_form_1_entry_id"><?php esc_html_e('Form 1 Entry ID', 'aks-integration'); ?></label></th>
                <td>
                    <?php $entry_id = get_user_meta($user->ID, 'aks_form_1_entry_id', true); ?>
                    <input type="number" name="aks_form_1_entry_id" id="aks_form_1_entry_id" 
                           value="<?php echo esc_attr($entry_id); ?>" 
                           class="regular-text" />
                    <?php if ($entry_id): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=gf_entries&view=entry&id=2&lid=' . $entry_id)); ?>" target="_blank" class="button">View Entry</a>
                    <?php endif; ?>
                    <p class="description"><?php esc_html_e('Gravity Forms ID 2 - Initial Registration', 'aks-integration'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th><label for="aks_form_2_entry_id"><?php esc_html_e('Form 2 Entry ID', 'aks-integration'); ?></label></th>
                <td>
                    <?php $entry_id = get_user_meta($user->ID, 'aks_form_2_entry_id', true); ?>
                    <input type="number" name="aks_form_2_entry_id" id="aks_form_2_entry_id" 
                           value="<?php echo esc_attr($entry_id); ?>" 
                           class="regular-text" />
                    <?php if ($entry_id): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=gf_entries&view=entry&id=3&lid=' . $entry_id)); ?>" target="_blank" class="button">View Entry</a>
                    <?php endif; ?>
                    <p class="description"><?php esc_html_e('Gravity Forms ID 3 - Complete Registration', 'aks-integration'); ?></p>
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
            return;
        }
        
        $fields = array(
            'sendpulse_contact_id',
            'sendpulse_user_id',
            'sendpulse_phone_id',
            'sendpulse_email_id',
            'sendpulse_comment_id',
            'quo_contact_id',
            'quo_phone_id',
            'docuseal_url'
        );
        
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_user_meta($user_id, $field, sanitize_text_field($_POST[$field]));
            }
        }
        
        // Handle yes/no fields
        update_user_meta($user_id, 'sr_registration_form_complete', isset($_POST['sr_registration_form_complete']) ? ($_POST['sr_registration_form_complete'] === 'yes' ? 'yes' : 'no') : 'no');
        update_user_meta($user_id, 'sr_waiver_signed', isset($_POST['sr_waiver_signed']) ? ($_POST['sr_waiver_signed'] === 'yes' ? 'yes' : 'no') : 'no');
        update_user_meta($user_id, 'sr_is_parent_guardian', isset($_POST['sr_is_parent_guardian']) ? ($_POST['sr_is_parent_guardian'] === 'yes' ? 'yes' : 'no') : 'yes');
        update_user_meta($user_id, 'sr_guardian_email', isset($_POST['sr_guardian_email']) ? sanitize_text_field($_POST['sr_guardian_email']) : '');
        update_user_meta($user_id, 'sr_lesson_library_access', isset($_POST['sr_lesson_library_access']) ? ($_POST['sr_lesson_library_access'] === 'yes' ? 'yes' : 'no') : 'no');
        
        if (isset($_POST['sr_store_credit_balance'])) {
            $val = floatval($_POST['sr_store_credit_balance']);
            update_user_meta($user_id, 'sr_store_credit_balance', $val);
        }
        
        // Entry tracking fields
        if (isset($_POST['aks_form_1_entry_id'])) {
            $entry_id = absint($_POST['aks_form_1_entry_id']);
            update_user_meta($user_id, 'aks_form_1_entry_id', $entry_id);
        }
        
        if (isset($_POST['aks_form_2_entry_id'])) {
            $entry_id = absint($_POST['aks_form_2_entry_id']);
            update_user_meta($user_id, 'aks_form_2_entry_id', $entry_id);
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
            'api_token' => ''
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
                'announcements'
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