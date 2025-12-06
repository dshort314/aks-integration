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
        'gift_cards'     => 'gift-cards',
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
        add_action('woocommerce_account_' . $this->endpoints['gift_cards'] . '_endpoint', array($this, 'render_gift_cards'));
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
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--lessons .aks-submenu,
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--store-account .aks-submenu,
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--edit-account .aks-submenu {
                display: none;
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
            /* Remove link styling from parent tabs */
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--lessons > a,
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--store-account > a,
            .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--edit-account > a {
                cursor: default;
                pointer-events: none;
            }
            .aks-submenu {
                list-style: none;
                margin: 0;
                padding: 0;
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
                pointer-events: auto;
            }
            .aks-submenu li a:hover {
                background: #f7f7f7;
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
                
                // Add Lessons submenu if conditions are met
                var lessonsLink = $(".woocommerce-MyAccount-navigation-link--lessons");
                if (lessonsLink.length && !lessonsLink.find(".aks-submenu").length) {
                    // Check if we should show submenu (not on waiver incomplete page)
                    var lessonsContent = $(".woocommerce-MyAccount-content");
                    var hasWaiverMessage = lessonsContent.find(".woocommerce-message--info:contains(\'waiver\')").length > 0;
                    
                    if (!hasWaiverMessage) {
                        var submenu = \'<ul class="aks-submenu">\' +
                            \'<li><a href="' . esc_js(wc_get_endpoint_url('lessons', 'evaluation-training', wc_get_page_permalink('myaccount'))) . '">Evaluation & Training Lessons</a></li>\' +
                            \'<li><a href="' . esc_js(wc_get_endpoint_url('lessons', 'purchase-bundle', wc_get_page_permalink('myaccount'))) . '">Purchase Bundle</a></li>\' +
                            \'<li><a href="' . esc_js(wc_get_endpoint_url('lessons', 'manage-lessons', wc_get_page_permalink('myaccount'))) . '">Manage Lessons</a></li>\' +
                            \'</ul>\';
                        lessonsLink.append(submenu);
                    }
                }
                
                // Add Store Account submenu
                var storeAccountLink = $(".woocommerce-MyAccount-navigation-link--store-account");
                if (storeAccountLink.length && !storeAccountLink.find(".aks-submenu").length) {
                    var storeSubmenu = \'<ul class="aks-submenu">\' +
                        \'<li><a href="' . esc_js(wc_get_endpoint_url('purchases', '', wc_get_page_permalink('myaccount'))) . '">Purchases</a></li>\' +
                        \'<li><a href="' . esc_js(wc_get_endpoint_url('payment-methods', '', wc_get_page_permalink('myaccount'))) . '">Payment Methods</a></li>\' +
                        \'<li><a href="' . esc_js(wc_get_endpoint_url('gift-cards', '', wc_get_page_permalink('myaccount'))) . '">Gift Cards</a></li>\' +
                        \'</ul>\';
                    storeAccountLink.append(storeSubmenu);
                    
                    // Hide the individual tabs from main menu
                    $(".woocommerce-MyAccount-navigation-link--purchases, .woocommerce-MyAccount-navigation-link--payment-methods, .woocommerce-MyAccount-navigation-link--gift-cards").hide();
                }
                
                // Add Profile submenu
                var profileLink = $(".woocommerce-MyAccount-navigation-link--edit-account");
                if (profileLink.length && !profileLink.find(".aks-submenu").length) {
                    var profileSubmenu = \'<ul class="aks-submenu">\' +
                        \'<li><a href="' . esc_js(wc_get_endpoint_url('edit-address', '', wc_get_page_permalink('myaccount'))) . '">Addresses</a></li>\' +
                        \'<li><a href="' . esc_js(wc_get_endpoint_url('edit-account', '', wc_get_page_permalink('myaccount'))) . '">Update Profile</a></li>\' +
                        \'<li><a href="' . esc_js(wc_get_endpoint_url('delete-account', '', wc_get_page_permalink('myaccount'))) . '">Delete Account</a></li>\' +
                        \'</ul>\';
                    profileLink.append(profileSubmenu);
                    
                    // Hide the individual tabs from main menu
                    $(".woocommerce-MyAccount-navigation-link--edit-address, .woocommerce-MyAccount-navigation-link--delete-account").hide();
                }
            });
        ');
    }
    
    /**
     * Menu reshaping
     */
    public function filter_account_menu($items) {
        $new = [];
        
        // REMOVE Dashboard completely
        unset($items['dashboard']);
        
        // Announcements at the very top (default)
        $new[$this->endpoints['announcements']] = __('Announcements', 'aks-integration');
        
        // Students (always show)
        $new[$this->endpoints['students']] = __('Students', 'aks-integration');
        
        // Lessons (no link, only sub-tabs - handled by CSS/JS)
        $new[$this->endpoints['lessons']] = __('Lessons', 'aks-integration');
        
        // Rest of items
        $new[$this->endpoints['documents']]      = __('Waiver & Documents', 'aks-integration');
        $new[$this->endpoints['videos']]         = __('Videos', 'aks-integration');
        
        // Store Account (new parent tab - no link, only sub-tabs)
        $new[$this->endpoints['store_account']] = __('Store Account', 'aks-integration');
        
        // Store Account sub-tabs (will be moved under Store Account via CSS/JS)
        $new[$this->endpoints['purchases']]      = __('Purchases', 'aks-integration');
        $new['payment-methods']                  = __('Payment Methods', 'woocommerce');
        $new[$this->endpoints['gift_cards']]     = __('Gift Cards', 'aks-integration');
        
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
    
    public function render_gift_cards() {
        $this->heading(__('Gift Cards', 'aks-integration'));
        
        $user_id = get_current_user_id();
        $balance = get_user_meta($user_id, self::META_STORE_CREDIT, true);
        if ($balance === '') {
            $balance = 0;
        }
        
        echo '<div class="aks-wac-panel">';
        echo '<p>' . esc_html__('Current Balance:', 'aks-integration') . ' <strong>' . wc_price($balance) . '</strong></p>';
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
     * Helpers
     */
    private function has_signed_waiver($user_id) {
        return get_user_meta($user_id, self::META_WAIVER_SIGNED, true) === 'yes';
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