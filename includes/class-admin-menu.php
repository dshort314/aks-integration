<?php
/**
 * Admin Menu Class
 * Handles the WordPress admin menu structure for AKS Integration
 */

class AKS_Integration_Admin_Menu {
    
    private $sendpulse_admin;
    private $docuseal_admin;
    private $account_tabs_admin;
    private $payment_discount_handler;
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        
        // Initialize admin classes early so their settings get registered
        $this->init_admin_classes();
    }
    
    /**
     * Initialize admin classes
     */
    private function init_admin_classes() {
        // Load and initialize SendPulse admin
        if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-sendpulse-admin.php')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-sendpulse-admin.php';
            $this->sendpulse_admin = AKS_SendPulse_Admin::get_instance();
        }
        
        // Load and initialize DocuSeal admin
        if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/docuseal/class-docuseal-admin.php')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/docuseal/class-docuseal-admin.php';
            $this->docuseal_admin = AKS_DocuSeal_Admin::get_instance();
        }
        
        // Load and initialize Account Tabs admin
        if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-account-tabs-admin.php')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-account-tabs-admin.php';
            $this->account_tabs_admin = AKS_Account_Tabs_Admin::get_instance();
        }
        
        // Load and initialize Payment Discount Handler
        if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-payment-discount-handler.php')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-payment-discount-handler.php';
            $this->payment_discount_handler = AKS_Payment_Discount_Handler::get_instance();
        }
    }
    
    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            'AKS Integration',          // Page title
            'AKS Integration',          // Menu title
            'manage_options',           // Capability
            'aks-integration',          // Menu slug
            array($this, 'main_page'), // Callback function
            'dashicons-admin-generic',  // Icon
            80                          // Position
        );
        
        // SendPulse submenu
        add_submenu_page(
            'aks-integration',          // Parent slug
            'SendPulse Settings',       // Page title
            'SendPulse',                // Menu title
            'manage_options',           // Capability
            'aks-sendpulse',           // Menu slug
            array($this, 'sendpulse_page')
        );
        
        // DocuSeal submenu
        add_submenu_page(
            'aks-integration',          // Parent slug
            'DocuSeal Settings',        // Page title
            'DocuSeal',                 // Menu title
            'manage_options',           // Capability
            'aks-docuseal',            // Menu slug
            array($this, 'docuseal_page')
        );
        
        // Account Tabs submenu
        add_submenu_page(
            'aks-integration',                    // Parent slug
            'Account Tabs',                       // Page title
            'Account Tabs',                       // Menu title
            'manage_options',                     // Capability
            'aks-account-tabs',                  // Menu slug
            array($this, 'account_tabs_main_page')
        );
        
        // Evaluation & Training Lessons submenu
        add_submenu_page(
            'aks-integration',                           // Parent slug
            'Evaluation & Training Lessons',             // Page title
            '— Evaluation & Training',                   // Menu title
            'manage_options',                            // Capability
            'aks-account-tab-evaluation',               // Menu slug
            array($this, 'evaluation_training_page')
        );
        
        // Purchase Bundle submenu
        add_submenu_page(
            'aks-integration',                    // Parent slug
            'Purchase Bundle',                    // Page title
            '— Purchase Bundle',                  // Menu title
            'manage_options',                     // Capability
            'aks-account-tab-purchase',          // Menu slug
            array($this, 'purchase_bundle_page')
        );
        
        // Manage Lessons submenu
        add_submenu_page(
            'aks-integration',                    // Parent slug
            'Manage Lessons',                     // Page title
            '— Manage Lessons',                   // Menu title
            'manage_options',                     // Capability
            'aks-account-tab-manage',            // Menu slug
            array($this, 'manage_lessons_page')
        );
        
        // Videos submenu
        add_submenu_page(
            'aks-integration',                    // Parent slug
            'Videos Tab',                         // Page title
            '— Videos',                           // Menu title
            'manage_options',                     // Capability
            'aks-account-tab-videos',            // Menu slug
            array($this, 'videos_page')
        );
        
        // Payment Discount submenu
        add_submenu_page(
            'aks-integration',                    // Parent slug
            'Payment Discount',                   // Page title
            'Payment Discount',                   // Menu title
            'manage_options',                     // Capability
            'aks-payment-discount',              // Menu slug
            array($this, 'payment_discount_page')
        );
        
        // Remove duplicate main menu item
        remove_submenu_page('aks-integration', 'aks-integration');
    }
    
    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        // Check if we're on one of our admin pages
        $aks_pages = array(
            'toplevel_page_aks-integration',
            'aks-integration_page_aks-sendpulse',
            'aks-integration_page_aks-docuseal',
            'aks-integration_page_aks-account-tabs',
            'aks-integration_page_aks-account-tab-evaluation',
            'aks-integration_page_aks-account-tab-purchase',
            'aks-integration_page_aks-account-tab-manage',
            'aks-integration_page_aks-account-tab-videos',
            'aks-integration_page_aks-payment-discount',
        );
        
        if (!in_array($hook, $aks_pages)) {
            return;
        }
        
        wp_enqueue_style(
            'aks-integration-admin',
            AKS_INTEGRATION_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            AKS_INTEGRATION_VERSION
        );
    }
    
    /**
     * Render main page
     */
    public function main_page() {
        // Redirect to SendPulse page
        wp_redirect(admin_url('admin.php?page=aks-sendpulse'));
        exit;
    }
    
    /**
     * Render SendPulse page
     */
    public function sendpulse_page() {
        if ($this->sendpulse_admin && method_exists($this->sendpulse_admin, 'settings_page')) {
            $this->sendpulse_admin->settings_page();
        } else {
            echo '<div class="wrap"><h1>SendPulse Settings</h1><p>SendPulse integration is not available.</p></div>';
        }
    }
    
    /**
     * Render DocuSeal page
     */
    public function docuseal_page() {
        if ($this->docuseal_admin && method_exists($this->docuseal_admin, 'settings_page')) {
            $this->docuseal_admin->settings_page();
        } else {
            echo '<div class="wrap"><h1>DocuSeal Settings</h1><p>DocuSeal integration is not available.</p></div>';
        }
    }
    
    /**
     * Render Account Tabs main page
     */
    public function account_tabs_main_page() {
        ?>
        <div class="wrap">
            <h1>Account Tabs Content Management</h1>
            <p>Manage the content displayed in various WooCommerce My Account tabs and sub-tabs.</p>
            
            <h2>Available Content Editors:</h2>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><a href="<?php echo admin_url('admin.php?page=aks-account-tab-evaluation'); ?>">Evaluation & Training Lessons</a> - Lessons sub-tab</li>
                <li><a href="<?php echo admin_url('admin.php?page=aks-account-tab-purchase'); ?>">Purchase Bundle</a> - Lessons sub-tab</li>
                <li><a href="<?php echo admin_url('admin.php?page=aks-account-tab-manage'); ?>">Manage Lessons</a> - Lessons sub-tab</li>
                <li><a href="<?php echo admin_url('admin.php?page=aks-account-tab-videos'); ?>">Videos Tab</a> - Main tab content</li>
            </ul>
        </div>
        <?php
    }
    
    /**
     * Render Evaluation & Training Lessons page
     */
    public function evaluation_training_page() {
        if ($this->account_tabs_admin && method_exists($this->account_tabs_admin, 'render_evaluation_training_page')) {
            $this->account_tabs_admin->render_evaluation_training_page();
        }
    }
    
    /**
     * Render Purchase Bundle page
     */
    public function purchase_bundle_page() {
        if ($this->account_tabs_admin && method_exists($this->account_tabs_admin, 'render_purchase_bundle_page')) {
            $this->account_tabs_admin->render_purchase_bundle_page();
        }
    }
    
    /**
     * Render Manage Lessons page
     */
    public function manage_lessons_page() {
        if ($this->account_tabs_admin && method_exists($this->account_tabs_admin, 'render_manage_lessons_page')) {
            $this->account_tabs_admin->render_manage_lessons_page();
        }
    }
    
    /**
     * Render Videos page
     */
    public function videos_page() {
        if ($this->account_tabs_admin && method_exists($this->account_tabs_admin, 'render_videos_page')) {
            $this->account_tabs_admin->render_videos_page();
        }
    }
    
    /**
     * Render Payment Discount page
     */
    public function payment_discount_page() {
        if ($this->payment_discount_handler && method_exists($this->payment_discount_handler, 'render_settings_page')) {
            $this->payment_discount_handler->render_settings_page();
        } else {
            echo '<div class="wrap"><h1>Payment Discount Settings</h1><p>Payment discount handler is not available.</p></div>';
        }
    }
}