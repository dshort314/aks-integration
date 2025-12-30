<?php
/**
 * WooCommerce Account Customization
 * Customizes WooCommerce My Account with consolidated tabs, waiver gating, user profile meta, and Add Student modal.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AKS_WooCommerce_Account_Customization {
    
    // Slugs for custom endpoints
    private $endpoints = [
        'students'       => 'students',
        'lessons'        => 'lessons',
        'documents'      => 'documents',
        'videos'         => 'videos',
        'store_account'  => 'store-account',
        'purchases'      => 'purchases',
        'announcements'  => 'announcements',
        'delete_account' => 'delete-account',
    ];

    // User meta keys
    const META_WAIVER_SIGNED       = 'sr_waiver_signed';
    const META_GUARDIAN_EMAIL      = 'sr_guardian_email';
    const META_LIBRARY_ACCESS      = 'sr_lesson_library_access';
    const META_STORE_CREDIT        = 'sr_store_credit_balance';
    const META_IS_PARENT_GUARDIAN  = 'sr_is_parent_guardian';

    // Custom capability
    const CAP_LIBRARY_ACCESS = 'sr_view_lesson_library';

    public function __construct() {
        // Only run if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Endpoints and rewrites
        add_action('init', array($this, 'register_endpoints'));
        
        // Make Announcements the default landing tab
        add_action('template_redirect', array($this, 'redirect_my_account_to_announcements'));
        
        // Woo menu + rendering
        add_filter('woocommerce_account_menu_items', array($this, 'filter_account_menu'), 99);
        $this->attach_endpoint_renderers();
        
        // Frontend document actions
        add_action('init', array($this, 'handle_guardian_invite'));

        // Enqueue modal scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_modal_assets'));

        /**
         * PROFILE TAB CUSTOMIZATION
         * - Show editable phone number before password fields
         * - Hide email field visually
         */
        add_action(
            'woocommerce_edit_account_form',
            array($this, 'render_profile_phone_field')
        );

        add_action(
            'wp_head',
            array($this, 'hide_profile_email_field')
        );

        add_action(
            'woocommerce_save_account_details',
            array($this, 'save_profile_phone_field'),
            10,
            1
        );
        
        // Update Quo and SendPulse when phone changes
        add_action(
            'woocommerce_save_account_details',
            array($this, 'update_crm_phone_number'),
            20,
            1
        );
        
        // Kill the display name validation error
        add_action('woocommerce_save_account_details_errors', array($this, 'remove_display_name_error'), 10, 2);
    }
    
    /**
     * Remove the display name email validation error completely
     */
    public function remove_display_name_error($errors, $user) {
        // Get all error codes
        $error_codes = $errors->get_error_codes();
        
        // Remove any error containing 'display_name'
        foreach ($error_codes as $code) {
            if (strpos($code, 'display_name') !== false) {
                $errors->remove($code);
            }
        }
        
        // Also check error messages and remove the specific privacy concern error
        foreach ($error_codes as $code) {
            $messages = $errors->get_error_messages($code);
            foreach ($messages as $message) {
                if (strpos($message, 'Display name cannot be changed') !== false) {
                    $errors->remove($code);
                    break;
                }
            }
        }
    }
    
    /**
     * Register endpoints
     */
    public function register_endpoints() {
        foreach ($this->endpoints as $slug) {
            add_rewrite_endpoint($slug, EP_ROOT | EP_PAGES);
        }
    }
    
    /**
     * Attach endpoint renderers
     */
    private function attach_endpoint_renderers() {
        add_action('woocommerce_account_' . $this->endpoints['students'] . '_endpoint', array($this, 'render_students'));
        add_action('woocommerce_account_' . $this->endpoints['lessons'] . '_endpoint', array($this, 'render_lessons'));
        add_action('woocommerce_account_' . $this->endpoints['documents'] . '_endpoint', array($this, 'render_documents'));
        add_action('woocommerce_account_' . $this->endpoints['videos'] . '_endpoint', array($this, 'render_videos'));
        add_action('woocommerce_account_' . $this->endpoints['store_account'] . '_endpoint', array($this, 'render_store_account'));
        add_action('woocommerce_account_' . $this->endpoints['purchases'] . '_endpoint', array($this, 'render_purchases'));
        add_action('woocommerce_account_' . $this->endpoints['announcements'] . '_endpoint', array($this, 'render_announcements'));
        add_action('woocommerce_account_' . $this->endpoints['delete_account'] . '_endpoint', array($this, 'render_delete_account'));
    }
    
    /**
     * Enqueue modal scripts and styles
     */
    public function enqueue_modal_assets() {
        if (!is_account_page()) {
            return;
        }

        // Enqueue Gravity Forms modal styles if available
        if (class_exists('GFForms')) {
            wp_enqueue_style('gform_basic');
            wp_enqueue_style('gform_theme');
        }

        // Add custom modal styles
        wp_add_inline_style('woocommerce-layout', '
            .aks-modal-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
                z-index: 9998;
            }
            .aks-modal-overlay.active {
                display: block;
            }
            .aks-modal {
                display: none;
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: #fff;
                padding: 30px;
                border-radius: 8px;
                max-width: 800px;
                width: 90%;
                max-height: 90vh;
                overflow-y: auto;
                z-index: 9999;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            }
            .aks-modal.active {
                display: block;
            }
            .aks-modal-close {
                position: absolute;
                top: 15px;
                right: 15px;
                font-size: 28px;
                font-weight: bold;
                color: #999;
                cursor: pointer;
                background: none;
                border: none;
                padding: 0;
                line-height: 1;
            }
            .aks-modal-close:hover {
                color: #333;
            }
            .aks-add-student-btn {
                margin-top: 20px;
                display: inline-block;
                padding: 12px 24px;
                background: #2271b1;
                color: #fff;
                text-decoration: none;
                border-radius: 4px;
                font-weight: 600;
                border: none;
                cursor: pointer;
                font-size: 16px;
            }
            .aks-add-student-btn:hover {
                background: #135e96;
                color: #fff;
            }
            
            /* Parent tab sub-menu styles (Lessons, Store Account, Profile) */
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--lessons,
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--store-account,
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--edit-account {
                position: relative;
            }
            
            /* Parent tab links - make clickable on mobile */
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--store-account > a,
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--edit-account > a {
                cursor: pointer;
                position: relative;
                padding-right: 30px;
            }
            
            /* Lessons parent link - not clickable, just for hover */
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--lessons > a {
                cursor: default;
                position: relative;
                padding-right: 30px;
            }
            
            /* Add chevron indicator */
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--lessons > a:after,
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--store-account > a:after,
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--edit-account > a:after {
                content: "▼";
                position: absolute;
                right: 10px;
                top: 50%;
                transform: translateY(-50%);
                font-size: 10px;
                transition: transform 0.2s;
            }
            
            /* Rotate chevron when open */
            .woocommerce-MyAccount-navigation ul li.aks-submenu-open > a:after {
                transform: translateY(-50%) rotate(180deg);
            }
            
            /* Submenu base styles */
            .aks-submenu {
                display: none;
                list-style: none;
                margin: 0;
                padding: 0;
                background: #f7f7f7;
            }
            
            /* Desktop: submenu appears to the right on hover */
            @media (min-width: 769px) {
                .aks-submenu {
                    position: absolute;
                    left: 100%;
                    top: 0;
                    background: #fff;
                    border: 1px solid #ddd;
                    min-width: 200px;
                    box-shadow: 2px 2px 8px rgba(0,0,0,0.1);
                    z-index: 1000;
                }
                
                .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--lessons:hover .aks-submenu,
                .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--store-account:hover .aks-submenu,
                .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--edit-account:hover .aks-submenu {
                    display: block;
                }
            }
            
            /* Mobile: submenu appears below when toggled */
            @media (max-width: 768px) {
                .aks-submenu {
                    position: relative;
                    width: 100%;
                    background: #f7f7f7;
                    border-top: 1px solid #ddd;
                    padding-left: 15px;
                }
                
                .woocommerce-MyAccount-navigation ul li.aks-submenu-open .aks-submenu {
                    display: block;
                }
            }
            
            .aks-submenu li {
                margin: 0;
                padding: 0;
                border-bottom: 1px solid #eee;
            }
            .aks-submenu li:last-child {
                border-bottom: none;
            }
            .aks-submenu li a {
                display: block;
                padding: 10px 15px;
                text-decoration: none;
                color: #333;
                transition: background 0.2s;
            }
            .aks-submenu li a:hover {
                background: #e9e9e9;
            }
        ');

        // Add modal JavaScript
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                // Open modal
                $(document).on("click", ".aks-add-student-btn", function(e) {
                    e.preventDefault();
                    $(".aks-modal-overlay").addClass("active");
                    $(".aks-modal").addClass("active");
                    $("body").css("overflow", "hidden");
                });

                // Close modal
                $(document).on("click", ".aks-modal-close, .aks-modal-overlay", function(e) {
                    e.preventDefault();
                    $(".aks-modal-overlay").removeClass("active");
                    $(".aks-modal").removeClass("active");
                    $("body").css("overflow", "");
                });

                // Prevent modal content clicks from closing
                $(document).on("click", ".aks-modal", function(e) {
                    e.stopPropagation();
                });

                // Redirect to students tab after successful form submission
                $(document).on("gform_confirmation_loaded", function(event, formId) {
                    if (formId == 1) { // Form 1 is the student form
                        window.location.href = "' . esc_js(wc_get_endpoint_url('students', '', wc_get_page_permalink('myaccount'))) . '";
                    }
                });
                
                // Mobile/Desktop toggle functionality for parent tabs
                function setupSubmenuToggle(parentSelector, submenuItems, preventParentClick) {
                    var parentLink = $(parentSelector);
                    
                    if (parentLink.length && !parentLink.find(".aks-submenu").length) {
                        var submenu = $("<ul class=\"aks-submenu\"></ul>");
                        
                        submenuItems.forEach(function(item) {
                            submenu.append("<li><a href=\"" + item.url + "\">" + item.label + "</a></li>");
                        });
                        
                        parentLink.append(submenu);
                        
                        // Click handler for parent link
                        parentLink.find("> a").on("click", function(e) {
                            // If preventParentClick is true, always prevent default (desktop and mobile)
                            if (preventParentClick === true) {
                                e.preventDefault();
                                if (window.innerWidth <= 768) {
                                    parentLink.toggleClass("aks-submenu-open");
                                    // Close other open submenus
                                    $(".woocommerce-MyAccount-navigation-link").not(parentLink).removeClass("aks-submenu-open");
                                }
                            } else {
                                // Only prevent default and toggle on mobile/tablet
                                if (window.innerWidth <= 768) {
                                    e.preventDefault();
                                    parentLink.toggleClass("aks-submenu-open");
                                    // Close other open submenus
                                    $(".woocommerce-MyAccount-navigation-link").not(parentLink).removeClass("aks-submenu-open");
                                }
                            }
                        });
                    }
                }
                
                // Add Lessons submenu (always show if tab exists, parent not clickable)
                var lessonsLink = $(".woocommerce-MyAccount-navigation-link--lessons");
                if (lessonsLink.length) {
                    setupSubmenuToggle(".woocommerce-MyAccount-navigation-link--lessons", [
                        { url: "' . esc_js(wc_get_endpoint_url('lessons', 'evaluation-training', wc_get_page_permalink('myaccount'))) . '", label: "Evaluation & Training Lessons" },
                        { url: "' . esc_js(wc_get_endpoint_url('lessons', 'purchase-bundle', wc_get_page_permalink('myaccount'))) . '", label: "Purchase Bundle" },
                        { url: "' . esc_js(wc_get_endpoint_url('lessons', 'manage-lessons', wc_get_page_permalink('myaccount'))) . '", label: "Manage Lessons" }
                    ], true); // true = prevent parent click on all devices
                }
                
                // Add Store Account submenu
                setupSubmenuToggle(".woocommerce-MyAccount-navigation-link--store-account", [
                    { url: "' . esc_js(wc_get_endpoint_url('purchases', '', wc_get_page_permalink('myaccount'))) . '", label: "Purchases" },
                    { url: "' . esc_js(wc_get_endpoint_url('payment-methods', '', wc_get_page_permalink('myaccount'))) . '", label: "Payment Methods" }
                ]);
                
                // Hide the individual tabs from main menu
                $(".woocommerce-MyAccount-navigation-link--purchases, .woocommerce-MyAccount-navigation-link--payment-methods").hide();
                
                // Add Profile submenu
                setupSubmenuToggle(".woocommerce-MyAccount-navigation-link--edit-account", [
                    { url: "' . esc_js(wc_get_endpoint_url('edit-address', '', wc_get_page_permalink('myaccount'))) . '", label: "Addresses" },
                    { url: "' . esc_js(wc_get_endpoint_url('edit-account', '', wc_get_page_permalink('myaccount'))) . '", label: "Update Profile" },
                    { url: "' . esc_js(wc_get_endpoint_url('delete-account', '', wc_get_page_permalink('myaccount'))) . '", label: "Delete Account" }
                ]);
                
                // Hide the individual tabs from main menu
                $(".woocommerce-MyAccount-navigation-link--edit-address, .woocommerce-MyAccount-navigation-link--delete-account").hide();
                
                // Close submenus when clicking outside
                $(document).on("click", function(e) {
                    if (!$(e.target).closest(".woocommerce-MyAccount-navigation-link").length) {
                        $(".woocommerce-MyAccount-navigation-link").removeClass("aks-submenu-open");
                    }
                });
            });
        ');
    }
    
    /**
     * Menu reshaping
     */
    public function filter_account_menu($items) {
        $new = [];
        $user_id = get_current_user_id();
        
        // REMOVE Dashboard completely
        unset($items['dashboard']);
        
        // Announcements at the very top (default)
        $new[$this->endpoints['announcements']] = __('Announcements', 'aks-integration');
        
        // Students (always show)
        $new[$this->endpoints['students']] = __('Students', 'aks-integration');
        
        // Lessons (only show if user has at least one student)
        if ($this->has_students($user_id)) {
            $new[$this->endpoints['lessons']] = __('Lessons', 'aks-integration');
        }
        
        // Rest of items
        $new[$this->endpoints['documents']]      = __('Waiver & Documents', 'aks-integration');
        $new[$this->endpoints['videos']]         = __('Videos', 'aks-integration');
        
        // Store Account (new parent tab - no link, only sub-tabs)
        $new[$this->endpoints['store_account']] = __('Store Account', 'aks-integration');
        
        // Store Account sub-tabs (will be moved under Store Account via CSS/JS)
        $new[$this->endpoints['purchases']]      = __('Purchases', 'aks-integration');
        $new['payment-methods']                  = __('Payment Methods', 'woocommerce');
        
        // Profile (parent tab - no link, only sub-tabs - handled by CSS/JS)
        $new['edit-account']                     = __('Profile', 'aks-integration');
        
        // Profile sub-tabs (will be moved under Profile via CSS/JS)
        // Note: edit-address, edit-account, and delete-account are added here
        // but JavaScript will hide them from main menu and show in submenu
        $new['edit-address']                     = __('Addresses', 'woocommerce');
        $new[$this->endpoints['delete_account']] = __('Delete Account', 'woocommerce');
        
        $new['customer-logout']                  = __('Logout', 'woocommerce');
        
        // Remove originals we've consolidated
        unset($items['orders'], $items['downloads']);
        
        return $new;
    }
    
    /**
     * Default landing: Announcements
     */
    public function redirect_my_account_to_announcements() {
        if (!is_user_logged_in() || !is_account_page()) {
            return;
        }
        
        global $wp;
        $qv = isset($wp->query_vars) ? $wp->query_vars : [];
        
        $has_endpoint = false;
        foreach (array_values($this->endpoints) as $slug) {
            if (isset($qv[$slug])) {
                $has_endpoint = true;
                break;
            }
        }
        
        // Also consider core endpoints
        $core = ['orders', 'downloads', 'edit-address', 'payment-methods', 'edit-account', 'customer-logout'];
        foreach ($core as $slug) {
            if (isset($qv[$slug])) {
                $has_endpoint = true;
                break;
            }
        }
        
        // If no endpoint specified, send to Announcements
        if (!$has_endpoint) {
            wp_safe_redirect(wc_get_endpoint_url($this->endpoints['announcements'], '', wc_get_page_permalink('myaccount')));
            exit;
        }
    }
    
    /**
     * Endpoint renderers
     */
    private function heading($title, $subtitle = '') {
        echo '<div class="aks-wac-wrap">';
        echo '<h2 class="aks-wac-h2" style="margin-bottom:6px;">' . esc_html($title) . '</h2>';
        if ($subtitle) {
            echo '<p class="aks-wac-sub" style="margin:0 0 16px;color:#666;">' . wp_kses_post($subtitle) . '</p>';
        }
        echo '</div>';
    }
    
    public function render_students() {
        $user_id = get_current_user_id();

        // Registration Form 2 gating
        $this->maybe_redirect_if_registration_incomplete($user_id);

        $this->heading(__('Students', 'aks-integration'));
        echo '<p>Manage your students and their information here.</p>';
        
        // Add GravityView with output buffering to fix duplicate /test/
        if (shortcode_exists('gravityview')) {
            ob_start();
            echo do_shortcode('[gravityview id="19891"]');
            $output = ob_get_clean();
            echo str_replace('/test/test/', '/test/', $output);
        }
        
        // Add "Add Student" button
        echo '<button class="aks-add-student-btn">' . esc_html__('Add Student', 'aks-integration') . '</button>';
        
        // Add modal overlay and container
        echo '<div class="aks-modal-overlay"></div>';
        echo '<div class="aks-modal">';
        echo '<button class="aks-modal-close">&times;</button>';
        echo '<h3>' . esc_html__('Add New Student', 'aks-integration') . '</h3>';
        
        // Display Form 1 (student form) in modal
        if (shortcode_exists('gravityform')) {
            echo do_shortcode('[gravityform id="1" title="false" description="false" ajax="true"]');
        }
        
        echo '</div>';
        
        // Add LatePoint shortcode if available
        if (shortcode_exists('latepoint')) {
            echo do_shortcode('[latepoint_customer_dashboard]');
        }
    }
    
    public function render_lessons() {
        $user_id = get_current_user_id();

        // Registration Form 2 gating
        $this->maybe_redirect_if_registration_incomplete($user_id);
        
        // Check if user has any students - redirect to Students tab if not
        if (!$this->has_students($user_id)) {
            wp_safe_redirect(wc_get_endpoint_url($this->endpoints['students'], '', wc_get_page_permalink('myaccount')));
            exit;
        }

        $this->heading(
            __('Lessons', 'aks-integration'),
            __('Manage your swim lessons and bookings.', 'aks-integration')
        );
        
        $waiver_signed = $this->has_signed_waiver($user_id);
        
        if (!$waiver_signed) {
            echo '<div class="woocommerce-message woocommerce-message--info">';
            echo '<p>' . esc_html__('You must complete the waiver before booking lessons.', 'aks-integration') . '</p>';
            echo '<p><a href="' . esc_url(wc_get_endpoint_url($this->endpoints['documents'], '', wc_get_page_permalink('myaccount'))) . '" class="button">' . esc_html__('Go to Waiver & Documents', 'aks-integration') . '</a></p>';
            echo '</div>';
            return;
        }
        
        // Check for sub-tab in URL
        global $wp;
        $sub_tab = isset($wp->query_vars['lessons']) ? $wp->query_vars['lessons'] : '';
        
        // Handle sub-tabs
        if ($sub_tab === 'evaluation-training') {
            $this->render_evaluation_training_content();
        } elseif ($sub_tab === 'purchase-bundle') {
            $this->render_purchase_bundle_content();
        } elseif ($sub_tab === 'manage-lessons') {
            $this->render_manage_lessons_content();
        } else {
            // Default lessons content - show booking interface
            if (shortcode_exists('latepoint_book_form')) {
                echo do_shortcode('[latepoint_book_form]');
            }
        }
    }
    
    /**
     * Render Evaluation & Training Lessons content
     */
    private function render_evaluation_training_content() {
        $content = get_option('aks_account_tab_evaluation_training', '');
        
        if (!empty($content)) {
            echo apply_filters('the_content', $content);
        } else {
            echo '<p>Evaluation & Training Lessons content has not been configured yet.</p>';
        }
    }
    
    /**
     * Render Purchase Bundle content
     */
    private function render_purchase_bundle_content() {
        $content = get_option('aks_account_tab_purchase_bundle', '');
        
        if (!empty($content)) {
            echo apply_filters('the_content', $content);
        } else {
            echo '<p>Purchase Bundle content has not been configured yet.</p>';
        }
    }
    
    /**
     * Render Manage Lessons content
     */
    private function render_manage_lessons_content() {
        $content = get_option('aks_account_tab_manage_lessons', '');
        
        if (!empty($content)) {
            echo apply_filters('the_content', $content);
        } else {
            echo '<p>Manage Lessons content has not been configured yet.</p>';
        }
    }
    
    public function render_documents() {
        $user_id = get_current_user_id();

        // Registration Form 2 gating
        $this->maybe_redirect_if_registration_incomplete($user_id);

        $registration_complete = get_user_meta($user_id, 'sr_registration_form_complete', true);
        $waiver_signed = $this->has_signed_waiver($user_id);
        $is_parent = get_user_meta($user_id, self::META_IS_PARENT_GUARDIAN, true);
        if ($is_parent === '') {
            $is_parent = 'yes';
        }
        $guardian_email = get_user_meta($user_id, self::META_GUARDIAN_EMAIL, true);
        $docuseal_url = get_user_meta($user_id, 'docuseal_url', true);
        
        $this->heading(
            __('Waiver & Documents', 'aks-integration')
        );
        
        // Check if registration is complete and waiver is not signed
        if (strtolower($registration_complete) === 'yes' && !$waiver_signed) {
            // Registration complete but waiver pending
            
            if (strtolower($is_parent) === 'yes') {
                // User IS the parent/guardian - show Sign Waiver button
                echo '<p>' . esc_html__('Waiver Pending', 'aks-integration') . '</p>';
                
                if (!empty($docuseal_url)) {
                    echo '<p><a href="' . esc_url($docuseal_url) . '" target="_blank" class="button">' . esc_html__('Sign Waiver', 'aks-integration') . '</a></p>';
                }
            } else {
                // User IS NOT the parent/guardian - just show waiver pending
                echo '<p>' . esc_html__('Waiver Pending', 'aks-integration') . '</p>';
            }
            
        } elseif ($waiver_signed) {
            // Waiver completed
            echo '<p>' . esc_html__('✓ Waiver Completed', 'aks-integration') . '</p>';
            
            // Only show view link if user IS the parent/guardian
            if (strtolower($is_parent) === 'yes' && !empty($docuseal_url)) {
                echo '<p><a href="' . esc_url($docuseal_url) . '" target="_blank" class="button">' . esc_html__('View Completed Waiver', 'aks-integration') . '</a></p>';
            }
            
        } else {
            // Default state
            echo '<p>' . esc_html__('Waiver Status: ', 'aks-integration') . ($waiver_signed ? esc_html__('Signed', 'aks-integration') : esc_html__('Not Signed', 'aks-integration')) . '</p>';
        }
    }
    
    public function render_videos() {
        $this->heading(__('Videos', 'aks-integration'));
        
        // Get custom content from admin panel
        $custom_content = get_option('aks_account_tab_videos', '');
        
        if (!empty($custom_content)) {
            // Use custom content from admin panel
            echo apply_filters('the_content', $custom_content);
        } else {
            echo '<p>' . esc_html__('Video content has not been configured yet.', 'aks-integration') . '</p>';
        }
    }
    
    public function render_purchases() {
        $this->heading(__('Purchases', 'aks-integration'));
        
        // Show orders
        echo '<h3>' . esc_html__('Orders', 'aks-integration') . '</h3>';
        woocommerce_account_orders(1);
        
        // Show downloads if any
        $downloads = wc_get_customer_available_downloads(get_current_user_id());
        if ($downloads) {
            echo '<h3 style="margin-top:30px;">' . esc_html__('Downloads', 'aks-integration') . '</h3>';
            woocommerce_account_downloads();
        }
    }
    
    public function render_store_account() {
        $this->heading(__('Store Account', 'aks-integration'));
        
        echo '<div class="aks-wac-panel">';
        echo '<p>' . esc_html__('Please select an option from the submenu above.', 'aks-integration') . '</p>';
        echo '</div>';
    }
    
    public function render_announcements() {
        $this->heading(__('Announcements', 'aks-integration'));
        
        $paged = max(1, absint(get_query_var('paged') ?: (isset($_GET['paged']) ? $_GET['paged'] : 1)));
        
        $q = new WP_Query([
            'posts_per_page' => 10,
            'paged'          => $paged,
            'category_name'  => 'announcements',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => false,
        ]);
        
        if ($q->have_posts()) {
            echo '<div class="aks-announce">';
            while ($q->have_posts()) {
                $q->the_post();
                echo '<article class="aks-announce-item">';
                echo '<h3 class="aks-announce-title"><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h3>';
                echo '<div class="aks-announce-meta" style="color:#666;font-size:13px;margin-bottom:6px;">' . esc_html(get_the_date()) . '</div>';
                
                // Use full content so links + shortcodes render correctly
                $content = apply_filters('the_content', get_the_content());
                echo '<div class="aks-announce-content">' . $content . '</div>';
                
                echo '</article>';
            }
            echo '</div>';
            
            // Pagination
            $base_url = wc_get_endpoint_url($this->endpoints['announcements'], '', wc_get_page_permalink('myaccount'));
            $links = paginate_links([
                'base'      => add_query_arg('paged', '%#%', $base_url),
                'format'    => false,
                'current'   => $paged,
                'total'     => max(1, (int) $q->max_num_pages),
                'type'      => 'list',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ]);
            
            if ($links) {
                echo '<div class="aks-pager">' . $links . '</div>';
            }
            
            wp_reset_postdata();
        } else {
            echo '<p>' . esc_html__('No announcements yet.', 'aks-integration') . '</p>';
        }
        
        // Custom styles
        echo '<style>
            .aks-announce-item{padding:14px 0;border-bottom:1px solid #eee}
            .aks-announce-item:last-child{border-bottom:0}
            .aks-announce-title{margin:0 0 4px;font-size:18px}
            .aks-pager ul{display:flex;gap:6px;list-style:none;padding:0}
            .aks-pager li a,.aks-pager li span{display:inline-block;padding:6px 10px;border:1px solid #ddd;border-radius:4px;text-decoration:none}
            .aks-pager li .current{background:#f7f7f7}
        </style>';
    }

    public function render_delete_account() {
        $this->heading(
            __('Delete Account', 'aks-integration'),
            __('Permanently delete your account and all associated data.', 'aks-integration')
        );
        
        $user_id = get_current_user_id();
        
        // Handle deletion request
        if (isset($_POST['aks_delete_account']) && isset($_POST['aks_delete_nonce'])) {
            if (wp_verify_nonce($_POST['aks_delete_nonce'], 'aks_delete_account_' . $user_id)) {
                if (isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === 'DELETE') {
                    require_once(ABSPATH . 'wp-admin/includes/user.php');
                    
                    // Delete the user
                    if (wp_delete_user($user_id)) {
                        wp_logout();
                        wp_safe_redirect(home_url());
                        exit;
                    } else {
                        wc_add_notice(__('Error deleting account. Please contact support.', 'aks-integration'), 'error');
                    }
                }
            }
        }
        
        echo '<div class="woocommerce-message woocommerce-message--error">';
        echo '<p><strong>' . esc_html__('Warning: This action cannot be undone!', 'aks-integration') . '</strong></p>';
        echo '<p>' . esc_html__('Deleting your account will permanently remove:', 'aks-integration') . '</p>';
        echo '<ul style="margin-left:20px;">';
        echo '<li>' . esc_html__('Your profile information', 'aks-integration') . '</li>';
        echo '<li>' . esc_html__('Order history', 'aks-integration') . '</li>';
        echo '<li>' . esc_html__('Saved addresses and payment methods', 'aks-integration') . '</li>';
        echo '<li>' . esc_html__('All other account data', 'aks-integration') . '</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '<form method="post" style="margin-top:20px;">';
        wp_nonce_field('aks_delete_account_' . $user_id, 'aks_delete_nonce');
        
        echo '<p>';
        echo '<label for="confirm_delete" style="display:block;margin-bottom:8px;">';
        echo esc_html__('Type DELETE to confirm:', 'aks-integration');
        echo '</label>';
        echo '<input type="text" name="confirm_delete" id="confirm_delete" class="woocommerce-Input input-text" required />';
        echo '</p>';
        
        echo '<p>';
        echo '<button type="submit" name="aks_delete_account" class="button" style="background:#dc3232;border-color:#dc3232;color:#fff;">';
        echo esc_html__('Delete My Account', 'aks-integration');
        echo '</button>';
        echo '</p>';
        
        echo '</form>';
    }

    /**
     * PROFILE TAB: add editable phone number field
     * Only render on My Account > Profile (edit-account endpoint), before password fields.
     */
    public function render_profile_phone_field() {
        if (!is_account_page() || !function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('edit-account')) {
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        // Get phone and format as (999) 999-9999 if it's 10 digits
        $phone_raw = get_user_meta($user_id, 'billing_phone', true);
        $digits    = preg_replace('/\D+/', '', (string) $phone_raw);

        if (strlen($digits) === 10) {
            $phone_display = sprintf(
                '(%s) %s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 4)
            );
        } else {
            $phone_display = $phone_raw;
        }
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row-wide" id="billing_phone_field">
            <label for="billing_phone">
                <?php esc_html_e('Phone number', 'aks-integration'); ?>
            </label>
            <input
                type="tel"
                class="woocommerce-Input input-text"
                name="billing_phone"
                id="billing_phone"
                value="<?php echo esc_attr($phone_display); ?>"
                placeholder="(555) 123-4567"
                maxlength="14"
                pattern="\(\d{3}\) \d{3}-\d{4}"
            />
        </p>
        <script>
        (function() {
            var phoneField = document.getElementById('billing_phone_field');
            var passwordFieldset = document.querySelector('.woocommerce-EditAccountForm fieldset');
            if (phoneField && passwordFieldset) {
                passwordFieldset.parentNode.insertBefore(phoneField, passwordFieldset);
            }
        })();
        </script>
        <?php
    }

    /**
     * PROFILE TAB: Make name, display name, and email fields readonly
     */
    public function hide_profile_email_field() {
        if (!is_account_page() || !function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('edit-account')) {
            return;
        }

        // Make fields readonly and style them to look disabled
        ?>
        <style>
            #account_email,
            #account_first_name,
            #account_last_name,
            #account_display_name {
                background-color: #f5f5f5 !important;
                cursor: not-allowed !important;
                opacity: 0.7;
            }
        </style>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Make fields readonly
            $("#account_email, #account_first_name, #account_last_name, #account_display_name").prop("readonly", true);
        });
        </script>
        <?php
    }

    /**
     * PROFILE TAB: save the phone number when the Profile form is submitted.
     * Normalizes input and stores as (999) 999-9999 when 10 digits.
     */
    public function save_profile_phone_field($user_id) {
        if (!$user_id) {
            return;
        }

        if (isset($_POST['billing_phone'])) {
            $raw    = wp_unslash($_POST['billing_phone']);
            $digits = preg_replace('/\D+/', '', (string) $raw);

            if (strlen($digits) === 10) {
                $phone = sprintf(
                    '(%s) %s-%s',
                    substr($digits, 0, 3),
                    substr($digits, 3, 3),
                    substr($digits, 6, 4)
                );
            } else {
                // Fallback sanitize without forcing format
                $phone = wc_clean($raw);
            }

            update_user_meta($user_id, 'billing_phone', $phone);
        }
    }
    
    /**
     * Update Quo and SendPulse when phone number changes
     */
    public function update_crm_phone_number($user_id) {
        
        if (!$user_id) {
            return;
        }
        
        if (!isset($_POST['billing_phone'])) {
            return;
        }
        
        $new_phone_raw = wp_unslash($_POST['billing_phone']);
        $new_digits = preg_replace('/\D+/', '', (string) $new_phone_raw);
        
        
        if (strlen($new_digits) !== 10) {
            return;
        }
        
        // Format phone
        $formatted_phone = sprintf(
            '(%s) %s-%s',
            substr($new_digits, 0, 3),
            substr($new_digits, 3, 3),
            substr($new_digits, 6, 4)
        );
        
        // Get user details
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }
        
        // Get stored CRM IDs
        $sendpulse_contact_id = get_user_meta($user_id, 'sendpulse_contact_id', true);
        $sendpulse_phone_id = get_user_meta($user_id, 'sendpulse_phone_id', true);
        $quo_contact_id = get_user_meta($user_id, 'quo_contact_id', true);
        $quo_phone_id = get_user_meta($user_id, 'quo_phone_id', true);
        
        
        // Update SendPulse
        if (!empty($sendpulse_contact_id) && !empty($sendpulse_phone_id)) {
            $this->update_sendpulse_phone($sendpulse_contact_id, $sendpulse_phone_id, $formatted_phone);
        } else {
        }
        
        // Update Quo
        if (!empty($quo_contact_id) && !empty($quo_phone_id)) {
            $this->update_quo_phone($quo_contact_id, $quo_phone_id, $formatted_phone, $user->first_name, $user->last_name);
        } else {
        }
        
    }
    
    /**
     * Update phone number in SendPulse using PUT /contacts/{contactId}/phones/{phoneId}
     */
    private function update_sendpulse_phone($contact_id, $phone_id, $phone) {
        
        // Get settings
        $settings = get_option('aks_sendpulse_settings');
        if (empty($settings['api_id']) || empty($settings['api_secret'])) {
            return;
        }
        
        // Load SendPulse API to get access token
        if (!class_exists('AKS_SendPulse_API')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-sendpulse-api.php';
        }
        
        $api = new AKS_SendPulse_API($settings['api_id'], $settings['api_secret']);
        
        // Use reflection to call private get_access_token method
        $reflection = new ReflectionClass($api);
        $method = $reflection->getMethod('get_access_token');
        $method->setAccessible(true);
        $access_token = $method->invoke($api);
        
        if (!$access_token) {
            return;
        }
        
        // Format phone for SendPulse (1 + 10 digits)
        $phone_digits = preg_replace('/\D+/', '', $phone);
        if (strlen($phone_digits) === 10) {
            $phone_sendpulse = '1' . $phone_digits;
        } else {
            $phone_sendpulse = $phone_digits;
        }
        
        
        $url = 'https://api.sendpulse.com/crm/v1/contacts/' . $contact_id . '/phones/' . $phone_id;
        
        $body = array(
            'phone' => $phone_sendpulse
        );
        
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token
            ),
        ));
        
        $response_body = curl_exec($curl);
        $response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);
        
        if ($curl_error) {
            return;
        }
        
        
        if ($response_code >= 200 && $response_code < 300) {
        } else {
        }
    }
    
    /**
     * Update phone number in Quo using PATCH /contacts/{id}
     */
    private function update_quo_phone($contact_id, $phone_id, $phone, $first_name, $last_name) {
        
        // Get settings
        $settings = get_option('aks_sendpulse_settings');
        if (empty($settings['quo_api_key'])) {
            return;
        }
        
        // Format phone to E.164 (+1XXXXXXXXXX)
        $phone_digits = preg_replace('/\D+/', '', $phone);
        if (strlen($phone_digits) === 10) {
            $phone_e164 = '+1' . $phone_digits;
        } elseif (strlen($phone_digits) === 11 && substr($phone_digits, 0, 1) === '1') {
            $phone_e164 = '+' . $phone_digits;
        } else {
            $phone_e164 = '+' . $phone_digits;
        }
        
        
        $url = 'https://api.openphone.com/v1/contacts/' . $contact_id;
        
        $body = array(
            'defaultFields' => array(
                'firstName' => $first_name,
                'lastName' => $last_name,
                'phoneNumbers' => array(
                    array(
                        'id' => $phone_id,
                        'name' => 'mobile',
                        'value' => $phone_e164
                    )
                )
            )
        );
        
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: ' . $settings['quo_api_key']
            ),
        ));
        
        $response_body = curl_exec($curl);
        $response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);
        
        if ($curl_error) {
            return;
        }
        
        
        if ($response_code >= 200 && $response_code < 300) {
        } else {
        }
    }
    
    /**
     * Helpers
     */
    private function has_signed_waiver($user_id) {
        return get_user_meta($user_id, self::META_WAIVER_SIGNED, true) === 'yes';
    }
    
    /**
     * Check if user has at least one student registered
     */
    private function has_students($user_id) {
        if (!class_exists('GFAPI')) {
            return false;
        }
        
        // Form 1 is the student registration form
        $form_id = 1;
        
        // Search for entries where created_by matches user_id
        $search_criteria = array(
            'status' => 'active',
            'field_filters' => array(
                array(
                    'key' => 'created_by',
                    'value' => $user_id
                )
            )
        );
        
        $entries = GFAPI::get_entries($form_id, $search_criteria);
        
        return is_array($entries) && count($entries) > 0;
    }

    /**
     * Check if Registration Form 2 is complete; if not, redirect to /complete-registration/
     */
    private function maybe_redirect_if_registration_incomplete($user_id) {
        // Read Registration Form 2 flag from user meta.
        // Expected values: "Yes" / "No" (case-insensitive).
        $status     = get_user_meta($user_id, 'sr_registration_form_complete', true);
        $normalized = is_string($status) ? strtolower($status) : '';

        // Only allow through when explicitly "yes".
        if ($normalized === 'yes') {
            return;
        }

        // Build redirect payload from user profile.
        $user = get_userdata($user_id);
        if (!$user) {
            return; // Fail-safe: do nothing if something is odd with the user record.
        }

        $fname = $user->first_name;
        $lname = $user->last_name;
        $email = $user->user_email;

        // Build redirect URL. Let add_query_arg handle encoding.
        $redirect_url = add_query_arg(
            array(
                'fname'           => $fname,
                'lname'           => $lname,
                'applicant_email' => $email,
                'user_id'         => $user_id,
            ),
            site_url('/complete-registration/')
        );

        // Hard redirect from endpoint renderer.
        wp_safe_redirect($redirect_url);
        exit;
    }
    
    /**
     * Frontend "Send Guardian Invite" handler
     */
    public function handle_guardian_invite() {
        if (!is_user_logged_in()) {
            return;
        }
        
        if (!isset($_POST['aks_send_guardian_invite'])) {
            return;
        }
        
        if (!isset($_POST['aks_guardian_invite_nonce']) || !wp_verify_nonce($_POST['aks_guardian_invite_nonce'], 'aks_guardian_invite')) {
            return;
        }
        
        $user_id = get_current_user_id();
        
        $is_parent = !empty($_POST['sr_is_parent_guardian']) ? 'yes' : 'no';
        update_user_meta($user_id, self::META_IS_PARENT_GUARDIAN, $is_parent);
        
        $email = '';
        if (!empty($_POST['sr_guardian_email'])) {
            $email = sanitize_email($_POST['sr_guardian_email']);
            if (is_email($email)) {
                update_user_meta($user_id, self::META_GUARDIAN_EMAIL, $email);
            }
        }
        
        wc_add_notice(__('Guardian information saved successfully.', 'aks-integration'), 'success');
        wp_safe_redirect(wc_get_endpoint_url($this->endpoints['documents'], '', wc_get_page_permalink('myaccount')));
        exit;
    }
}