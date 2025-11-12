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
    const META_GUARDIAN_EMAIL     = 'sr_guardian_email';
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
        
        // Admin user profile meta fields
        add_action('show_user_profile', array($this, 'admin_user_fields'));
        add_action('edit_user_profile', array($this, 'admin_user_fields'));
        add_action('personal_options_update', array($this, 'save_admin_user_fields'));
        add_action('edit_user_profile_update', array($this, 'save_admin_user_fields'));
        
        // Frontend document actions
        add_action('init', array($this, 'handle_guardian_invite'));
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
        $new['edit-address']                      = __('Addresses', 'woocommerce');
        $new['payment-methods']                   = __('Payment Methods', 'woocommerce');
        $new['edit-account']                      = __('Profile', 'aks-integration');
        $new[$this->endpoints['store_credit']]   = __('Store Credit', 'aks-integration');
        $new['customer-logout']                   = __('Logout', 'woocommerce');
        $new[$this->endpoints['delete_account']] = __('Delete Account', 'aks-integration');
        
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
        // Hard hide safeguard: if waiver not signed, do not reveal this template
        if (!$this->has_signed_waiver(get_current_user_id())) {
            wp_safe_redirect(wc_get_endpoint_url($this->endpoints['lessons'], '', wc_get_page_permalink('myaccount')));
            exit;
        }
        
        $this->heading(__('Students', 'aks-integration'));
        echo '<p>Manage your students and their information here.</p>';
        
        // Add LatePoint shortcode if available
        if (shortcode_exists('latepoint')) {
            echo do_shortcode('[latepoint_customer_dashboard]');
        }
    }
    
    public function render_lessons() {
        $this->heading(__('Lessons', 'aks-integration'), __('Manage your swim lessons and bookings.', 'aks-integration'));
        
        $user_id = get_current_user_id();
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
        $waiver_signed = $this->has_signed_waiver($user_id);
        $is_parent = get_user_meta($user_id, self::META_IS_PARENT_GUARDIAN, true);
        if ($is_parent === '') {
            $is_parent = 'yes';
        }
        $guardian_email = get_user_meta($user_id, self::META_GUARDIAN_EMAIL, true);
        $need_waiver = isset($_GET['need_waiver']);
        
        $this->heading(
            __('Waiver & Documents', 'aks-integration'),
            $need_waiver ? __('Please complete the waiver before booking or managing lessons.', 'aks-integration') : ''
        );
        
        echo '<div class="aks-wac-grid">';
        
        // Waiver Status Card
        echo '<div class="aks-wac-card">';
        echo '<h3>' . esc_html__('Waiver Status', 'aks-integration') . '</h3>';
        echo '<p>' . ($waiver_signed ? esc_html__('✓ Signed', 'aks-integration') : esc_html__('Not Signed', 'aks-integration')) . '</p>';
        if (!$waiver_signed) {
            echo '<p><a class="button" href="#">' . esc_html__('Open Waiver', 'aks-integration') . '</a></p>';
        }
        echo '</div>';
        
        // Guardian Invite Card
        echo '<div class="aks-wac-card">';
        echo '<h3>' . esc_html__('Not the Parent/Guardian?', 'aks-integration') . '</h3>';
        echo '<p>' . esc_html__('If you are not the parent/guardian, invite the correct guardian to sign.', 'aks-integration') . '</p>';
        
        if ($guardian_email) {
            echo '<p><strong>' . esc_html__('Guardian Email:', 'aks-integration') . '</strong> ' . esc_html($guardian_email) . '</p>';
        }
        
        echo '<form method="post">';
        wp_nonce_field('aks_guardian_invite', 'aks_guardian_invite_nonce');
        
        echo '<div class="aks-guardian-fields">';
        echo '<p>';
        echo '<label>';
        echo '<input type="checkbox" name="sr_is_parent_guardian" value="yes" ' . checked($is_parent, 'yes', false) . ' /> ';
        echo esc_html__('I am the parent/guardian', 'aks-integration');
        echo '</label>';
        echo '</p>';
        
        echo '<p>';
        echo '<label for="sr_guardian_email">' . esc_html__('Guardian Email', 'aks-integration') . '</label>';
        echo '<input type="email" name="sr_guardian_email" id="sr_guardian_email" value="' . esc_attr($guardian_email) . '" class="input-text" />';
        echo '</p>';
        
        echo '<p>';
        echo '<button type="submit" name="aks_send_guardian_invite" class="button">' . esc_html__('Send Guardian Invite', 'aks-integration') . '</button>';
        echo '</p>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
        
        // Documents Card  
        echo '<div class="aks-wac-card">';
        echo '<h3>' . esc_html__('Documents', 'aks-integration') . '</h3>';
        echo '<ul>';
        echo '<li><a href="#">' . esc_html__('View Signed Waiver', 'aks-integration') . '</a></li>';
        echo '<li><a href="#">' . esc_html__('Swimming Policy', 'aks-integration') . '</a></li>';
        echo '</ul>';
        echo '</div>';
        
        echo '</div>'; // End grid
        
        // Add some basic styles for the grid
        echo '<style>
            .aks-wac-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-top:20px}
            .aks-wac-card{background:#f8f9fa;border:1px solid #e0e0e0;padding:20px;border-radius:6px}
            .aks-wac-card h3{margin-top:0}
            .aks-guardian-fields p{margin-bottom:10px}
            .aks-guardian-fields label{display:block;margin-bottom:5px}
            .aks-guardian-fields .input-text{width:100%;max-width:300px}
        </style>';
    }
    
    public function render_videos() {
        $this->heading(__('Videos', 'aks-integration'));
        
        $user_id = get_current_user_id();
        $has_access = get_user_meta($user_id, self::META_LIBRARY_ACCESS, true) === 'yes';
        
        if (!$has_access) {
            echo '<div class="woocommerce-message woocommerce-message--info">';
            echo '<p>' . esc_html__('You do not currently have access to the video library.', 'aks-integration') . '</p>';
            echo '</div>';
            return;
        }
        
        echo '<p>' . esc_html__('Access your swim lesson videos here.', 'aks-integration') . '</p>';
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
        $this->heading(__('Delete Account', 'aks-integration'), __('This will permanently remove your account once there are no active bookings.', 'aks-integration'));
        echo '<div class="aks-wac-panel"><p><em>' . esc_html__('To request deletion, please contact support.', 'aks-integration') . '</em></p></div>';
    }
    
    /**
     * Helpers
     */
    private function has_signed_waiver($user_id) {
        return get_user_meta($user_id, self::META_WAIVER_SIGNED, true) === 'yes';
    }
    
    /**
     * Admin: User Profile meta UI
     */
    public function admin_user_fields($user) {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }
        
        $waiver = get_user_meta($user->ID, self::META_WAIVER_SIGNED, true);
        $is_parent = get_user_meta($user->ID, self::META_IS_PARENT_GUARDIAN, true);
        if ($is_parent === '') {
            $is_parent = 'yes';
        }
        $emails = get_user_meta($user->ID, self::META_GUARDIAN_EMAIL, true);
        $library = get_user_meta($user->ID, self::META_LIBRARY_ACCESS, true);
        $credit = get_user_meta($user->ID, self::META_STORE_CREDIT, true);
        
        ?>
        <h2><?php esc_html_e('All Knox Swim – Account Meta', 'aks-integration'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sr_waiver_signed"><?php esc_html_e('Waiver Signed', 'aks-integration'); ?></label></th>
                <td>
                    <select name="sr_waiver_signed" id="sr_waiver_signed">
                        <option value="no"  <?php selected($waiver, 'no'); ?>><?php esc_html_e('No', 'aks-integration'); ?></option>
                        <option value="yes" <?php selected($waiver, 'yes'); ?>><?php esc_html_e('Yes', 'aks-integration'); ?></option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th><label for="sr_is_parent_guardian"><?php esc_html_e('Is Parent/Guardian', 'aks-integration'); ?></label></th>
                <td>
                    <select name="sr_is_parent_guardian" id="sr_is_parent_guardian">
                        <option value="no"  <?php selected($is_parent, 'no'); ?>><?php esc_html_e('No', 'aks-integration'); ?></option>
                        <option value="yes" <?php selected($is_parent, 'yes'); ?>><?php esc_html_e('Yes', 'aks-integration'); ?></option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th><label for="sr_guardian_email"><?php esc_html_e('Guardian Email', 'aks-integration'); ?></label></th>
                <td><input type="text" name="sr_guardian_email" id="sr_guardian_email" value="<?php echo esc_attr($emails); ?>" class="regular-text"></td>
            </tr>
            
            <tr>
                <th><label for="sr_lesson_library_access"><?php esc_html_e('Lesson Library Access', 'aks-integration'); ?></label></th>
                <td>
                    <select name="sr_lesson_library_access" id="sr_lesson_library_access">
                        <option value="no"  <?php selected($library, 'no'); ?>><?php esc_html_e('No', 'aks-integration'); ?></option>
                        <option value="yes" <?php selected($library, 'yes'); ?>><?php esc_html_e('Yes', 'aks-integration'); ?></option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th><label for="sr_store_credit_balance"><?php esc_html_e('Store Credit Balance', 'aks-integration'); ?></label></th>
                <td><input type="number" step="0.01" min="0" name="sr_store_credit_balance" id="sr_store_credit_balance" value="<?php echo esc_attr($credit !== '' ? $credit : '0.00'); ?>" class="regular-text"></td>
            </tr>
        </table>
        <?php
    }
    
    public function save_admin_user_fields($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }
        
        update_user_meta($user_id, self::META_WAIVER_SIGNED, isset($_POST['sr_waiver_signed']) ? ($_POST['sr_waiver_signed'] === 'yes' ? 'yes' : 'no') : 'no');
        update_user_meta($user_id, self::META_IS_PARENT_GUARDIAN, isset($_POST['sr_is_parent_guardian']) ? ($_POST['sr_is_parent_guardian'] === 'yes' ? 'yes' : 'no') : 'yes');
        update_user_meta($user_id, self::META_GUARDIAN_EMAIL, isset($_POST['sr_guardian_email']) ? sanitize_text_field($_POST['sr_guardian_email']) : '');
        update_user_meta($user_id, self::META_LIBRARY_ACCESS, isset($_POST['sr_lesson_library_access']) ? ($_POST['sr_lesson_library_access'] === 'yes' ? 'yes' : 'no') : 'no');
        
        if (isset($_POST['sr_store_credit_balance'])) {
            $val = floatval($_POST['sr_store_credit_balance']);
            update_user_meta($user_id, self::META_STORE_CREDIT, $val);
        }
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
