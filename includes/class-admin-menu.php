<?php
/**
 * Admin Menu Class
 * Handles the WordPress admin menu structure for AKS Integration
 */

class AKS_Integration_Admin_Menu {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
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
            'aks-integration_page_aks-docuseal'
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
        if (class_exists('AKS_SendPulse_Admin')) {
            $sendpulse_admin = new AKS_SendPulse_Admin();
            $sendpulse_admin->settings_page();
        } else {
            echo '<div class="wrap"><h1>SendPulse Settings</h1><p>SendPulse integration is not available.</p></div>';
        }
    }
    
    /**
     * Render DocuSeal page
     */
    public function docuseal_page() {
        if (class_exists('AKS_DocuSeal_Admin')) {
            $docuseal_admin = new AKS_DocuSeal_Admin();
            $docuseal_admin->settings_page();
        } else {
            echo '<div class="wrap"><h1>DocuSeal Settings</h1><p>DocuSeal integration is not available.</p></div>';
        }
    }
}
