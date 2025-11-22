<?php
/**
 * WooCommerce Account Customization
 * Customizes WooCommerce My Account with consolidated tabs, waiver gating, and user profile meta.
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
        'purchases'      => 'purchases',
        'store_credit'   => 'store-credit',
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
        add_action('woocommerce_account_' . $this->endpoints['purchases'] . '_endpoint', array($this, 'render_purchases'));
        add_action('woocommerce_account_' . $this->endpoints['store_credit'] . '_endpoint', array($this, 'render_store_credit'));
        add_action('woocommerce_account_' . $this->endpoints['announcements'] . '_endpoint', array($this, 'render_announcements'));
        add_action('woocommerce_account_' . $this->endpoints['delete_account'] . '_endpoint', array($this, 'render_delete_account'));
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
        
        // Students (HIDE when waiver not signed)
        if ($this->has_signed_waiver(get_current_user_id())) {
            $new[$this->endpoints['students']] = __('Students', 'aks-integration');
        }
        
        // Lessons (always show; content gated inside)
        $new[$this->endpoints['lessons']] = __('Lessons', 'aks-integration');
        
        // Rest of items
        $new[$this->endpoints['documents']]      = __('Waiver & Documents', 'aks-integration');
        $new[$this->endpoints['videos']]         = __('Videos', 'aks-integration');
        $new[$this->endpoints['purchases']]      = __('Purchases', 'aks-integration');
        $new['edit-address']                     = __('Addresses', 'woocommerce');
        $new['payment-methods']                  = __('Payment Methods', 'woocommerce');
        $new['edit-account']                     = __('Profile', 'aks-integration');
        $new[$this->endpoints['store_credit']]   = __('Store Credit', 'aks-integration');
        $new[$this->endpoints['delete_account']] = __('Delete Account', 'woocommerce');
        $new['customer-logout']                  = __('Logout', 'woocommerce');
        
        // Remove originals we've consolidated and Delete Account
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
        // Hard hide safeguard: if waiver not signed, do not reveal this template
        if (!$this->has_signed_waiver(get_current_user_id())) {
            wp_safe_redirect(wc_get_endpoint_url($this->endpoints['lessons'], '', wc_get_page_permalink('myaccount')));
            exit;
        }
        
        $this->heading(__('Students', 'aks-integration'));
        echo '<p>Manage your students and their information here.</p>';
        
        // Add GravityView with output buffering to fix duplicate /test/
        if (shortcode_exists('gravityview')) {
            ob_start();
            echo do_shortcode('[gravityview id="19891"]');
            $output = ob_get_clean();
            echo str_replace('/test/test/', '/test/', $output);
        }
        
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
        
        // Show booking interface
        if (shortcode_exists('latepoint_book_form')) {
            echo do_shortcode('[latepoint_book_form]');
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
        
        // Get the /video-library/ page content
        $video_library_page = get_page_by_path('video-library');
        
        if ($video_library_page) {
            // Apply content filters to process shortcodes and formatting
            $content = apply_filters('the_content', $video_library_page->post_content);
            echo $content;
        } else {
            echo '<p>' . esc_html__('Access your swim lesson videos here.', 'aks-integration') . '</p>';
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
    
    public function render_store_credit() {
        $this->heading(__('Store Credit', 'aks-integration'));
        
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
     * PROFILE TAB: hide the email address field visually on Profile tab only.
     */
    public function hide_profile_email_field() {
        if (!is_account_page() || !function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('edit-account')) {
            return;
        }

        // Hide email, first name, last name, and display name fields
        echo '<style>
            .woocommerce-EditAccountForm p:has(#account_email),
            .woocommerce-EditAccountForm p:has(#account_first_name),
            .woocommerce-EditAccountForm p:has(#account_last_name),
            .woocommerce-EditAccountForm p:has(#account_display_name) {
                display: none !important;
            }
        </style>';
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