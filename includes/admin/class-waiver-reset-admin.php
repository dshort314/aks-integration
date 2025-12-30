<?php
/**
 * Waiver Reset Admin
 * Provides manual reset functionality for DocuSeal waivers with batch processing
 */

if (!defined('ABSPATH')) {
    exit;
}

class AKS_Waiver_Reset_Admin {
    
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Add bulk action to users list
        add_filter('bulk_actions-users', array($this, 'add_bulk_action'));
        add_filter('handle_bulk_actions-users', array($this, 'handle_bulk_action'), 10, 3);
        
        // Add admin notices
        add_action('admin_notices', array($this, 'show_admin_notices'));
        
        // Add column to users list
        add_filter('manage_users_columns', array($this, 'add_waiver_column'));
        add_filter('manage_users_custom_column', array($this, 'show_waiver_column'), 10, 3);
        
        // Make waiver column sortable
        add_filter('manage_users_sortable_columns', array($this, 'make_waiver_column_sortable'));
        
        // Add waiver filter dropdown
        add_action('restrict_manage_users', array($this, 'add_waiver_filter_dropdown'));
        
        // Handle sorting and filtering
        add_action('pre_get_users', array($this, 'handle_waiver_sorting_and_filtering'));
        
        // Add row action to users list
        add_filter('user_row_actions', array($this, 'add_row_action'), 10, 2);
        
        // Handle individual reset via URL
        add_action('admin_init', array($this, 'handle_individual_reset'));
        
        // AJAX handlers for batch processing
        add_action('wp_ajax_aks_get_waiver_users', array($this, 'ajax_get_users'));
        add_action('wp_ajax_aks_process_waiver_reset', array($this, 'ajax_process_user'));
    }
    
    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_submenu_page(
            'users.php',
            'Waiver Reset',
            'Waiver Reset',
            'manage_options',
            'aks-waiver-reset',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Add bulk action to users list
     */
    public function add_bulk_action($actions) {
        $actions['reset_waiver'] = 'Reset Waiver';
        $actions['reset_waiver_and_regenerate'] = 'Reset Waiver & Send New';
        return $actions;
    }
    
    /**
     * Handle bulk action
     */
    public function handle_bulk_action($redirect_to, $action, $user_ids) {
        if ($action !== 'reset_waiver' && $action !== 'reset_waiver_and_regenerate') {
            return $redirect_to;
        }
        
        $regenerate = ($action === 'reset_waiver_and_regenerate');
        $count = 0;
        
        foreach ($user_ids as $user_id) {
            if ($this->reset_user_waiver($user_id, $regenerate)) {
                $count++;
            }
        }
        
        $redirect_to = add_query_arg(array(
            'waiver_reset_count' => $count,
            'waiver_regenerated' => $regenerate ? '1' : '0'
        ), $redirect_to);
        
        return $redirect_to;
    }
    
    /**
     * Show admin notices after bulk action
     */
    public function show_admin_notices() {
        if (!empty($_GET['waiver_reset_count'])) {
            $count = intval($_GET['waiver_reset_count']);
            $regenerated = !empty($_GET['waiver_regenerated']);
            
            $message = sprintf(
                _n(
                    '%d waiver has been reset.',
                    '%d waivers have been reset.',
                    $count,
                    'aks-integration'
                ),
                $count
            );
            
            if ($regenerated) {
                $message .= ' ' . __('New signing documents have been sent.', 'aks-integration');
            }
            
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
        
        if (!empty($_GET['waiver_reset_success'])) {
            $user_id = intval($_GET['waiver_reset_success']);
            $user = get_userdata($user_id);
            $regenerated = !empty($_GET['regenerated']);
            
            $message = sprintf(
                __('Waiver reset for %s.', 'aks-integration'),
                $user ? $user->display_name : "User #{$user_id}"
            );
            
            if ($regenerated) {
                $message .= ' ' . __('New signing document has been sent.', 'aks-integration');
            }
            
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }
    
    /**
     * Add waiver status column to users list
     */
    public function add_waiver_column($columns) {
        $columns['waiver_status'] = 'Waiver';
        return $columns;
    }
    
    /**
     * Display waiver status in column
     */
    public function show_waiver_column($value, $column_name, $user_id) {
        if ($column_name !== 'waiver_status') {
            return $value;
        }
        
        $waiver_signed = get_user_meta($user_id, 'sr_waiver_signed', true);
        $docuseal_url = get_user_meta($user_id, 'docuseal_url', true);
        
        if ($waiver_signed === 'yes') {
            return '<span style="color: #46b450;">✓ Signed</span>';
        } elseif (!empty($docuseal_url)) {
            return '<span style="color: #ffb900;">⏳ Pending</span>';
        } else {
            return '<span style="color: #dc3232;">✗ Not Sent</span>';
        }
    }
    
    /**
     * Make waiver column sortable
     */
    public function make_waiver_column_sortable($columns) {
        $columns['waiver_status'] = 'waiver_status';
        return $columns;
    }
    
    /**
     * Add waiver filter dropdown above users table
     */
    public function add_waiver_filter_dropdown($which) {
        if ($which !== 'top') {
            return;
        }
        
        $current = isset($_GET['waiver_filter']) ? sanitize_text_field($_GET['waiver_filter']) : '';
        ?>
        <select name="waiver_filter" style="float:none; margin-left: 6px;">
            <option value=""><?php esc_html_e('All Waiver Statuses', 'aks-integration'); ?></option>
            <option value="signed" <?php selected($current, 'signed'); ?>><?php esc_html_e('✓ Signed', 'aks-integration'); ?></option>
            <option value="pending" <?php selected($current, 'pending'); ?>><?php esc_html_e('⏳ Pending', 'aks-integration'); ?></option>
            <option value="not_sent" <?php selected($current, 'not_sent'); ?>><?php esc_html_e('✗ Not Sent', 'aks-integration'); ?></option>
        </select>
        <?php
        submit_button( __( 'Filter', 'aks-integration' ), '', 'filter_action', false );
    }
    
    /**
     * Handle sorting and filtering for waiver column
     */
    public function handle_waiver_sorting_and_filtering($query) {
        if (!is_admin()) {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'users') {
            return;
        }
        
        // Handle sorting
        if (isset($query->query_vars['orderby']) && $query->query_vars['orderby'] === 'waiver_status') {
            $query->set('meta_key', 'sr_waiver_signed');
            $query->set('orderby', 'meta_value');
        }
        
        // Handle filtering
        if (!empty($_GET['waiver_filter'])) {
            $filter = sanitize_text_field($_GET['waiver_filter']);
            
            $meta_query = $query->get('meta_query');
            if (!is_array($meta_query)) {
                $meta_query = array();
            }
            
            switch ($filter) {
                case 'signed':
                    // Waiver is signed
                    $meta_query[] = array(
                        'key' => 'sr_waiver_signed',
                        'value' => 'yes',
                        'compare' => '='
                    );
                    break;
                    
                case 'pending':
                    // Has docuseal_url but waiver not signed
                    $meta_query['relation'] = 'AND';
                    $meta_query[] = array(
                        'key' => 'docuseal_url',
                        'value' => '',
                        'compare' => '!='
                    );
                    $meta_query[] = array(
                        'relation' => 'OR',
                        array(
                            'key' => 'sr_waiver_signed',
                            'value' => 'yes',
                            'compare' => '!='
                        ),
                        array(
                            'key' => 'sr_waiver_signed',
                            'compare' => 'NOT EXISTS'
                        )
                    );
                    break;
                    
                case 'not_sent':
                    // No docuseal_url
                    $meta_query[] = array(
                        'relation' => 'OR',
                        array(
                            'key' => 'docuseal_url',
                            'value' => '',
                            'compare' => '='
                        ),
                        array(
                            'key' => 'docuseal_url',
                            'compare' => 'NOT EXISTS'
                        )
                    );
                    break;
            }
            
            if (!empty($meta_query)) {
                $query->set('meta_query', $meta_query);
            }
        }
    }
    
    /**
     * Add row action for individual reset
     */
    public function add_row_action($actions, $user) {
        if (current_user_can('manage_options')) {
            $reset_url = wp_nonce_url(
                add_query_arg(array(
                    'action' => 'reset_waiver',
                    'user_id' => $user->ID
                ), admin_url('users.php')),
                'reset_waiver_' . $user->ID
            );
            
            $reset_regen_url = wp_nonce_url(
                add_query_arg(array(
                    'action' => 'reset_waiver_regenerate',
                    'user_id' => $user->ID
                ), admin_url('users.php')),
                'reset_waiver_' . $user->ID
            );
            
            $actions['reset_waiver'] = '<a href="' . esc_url($reset_url) . '">Reset Waiver</a>';
            $actions['reset_waiver_regen'] = '<a href="' . esc_url($reset_regen_url) . '">Reset & Resend</a>';
        }
        
        return $actions;
    }
    
    /**
     * Handle individual reset via URL
     */
    public function handle_individual_reset() {
        if (empty($_GET['action']) || !in_array($_GET['action'], array('reset_waiver', 'reset_waiver_regenerate'))) {
            return;
        }
        
        if (empty($_GET['user_id'])) {
            return;
        }
        
        $user_id = intval($_GET['user_id']);
        
        if (!wp_verify_nonce($_GET['_wpnonce'], 'reset_waiver_' . $user_id)) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
        
        $regenerate = ($_GET['action'] === 'reset_waiver_regenerate');
        $this->reset_user_waiver($user_id, $regenerate);
        
        wp_redirect(add_query_arg(array(
            'waiver_reset_success' => $user_id,
            'regenerated' => $regenerate ? '1' : '0'
        ), admin_url('users.php')));
        exit;
    }
    
    /**
     * Reset a user's waiver status
     * 
     * @param int  $user_id    User ID
     * @param bool $regenerate Whether to trigger new document generation
     * @return array Result with status and message
     */
    public function reset_user_waiver($user_id, $regenerate = false) {
        // Reset waiver signed status
        update_user_meta($user_id, 'sr_waiver_signed', 'no');
        
        // Clear DocuSeal URL
        delete_user_meta($user_id, 'docuseal_url');
        
        $result = array(
            'reset' => true,
            'regenerated' => false,
            'message' => 'Waiver reset'
        );
        
        if ($regenerate) {
            $regen_result = $this->trigger_docuseal_regeneration($user_id);
            $result['regenerated'] = $regen_result;
            $result['message'] = $regen_result ? 'Reset & sent new document' : 'Reset only (no entry found for regeneration)';
        }
        
        return $result;
    }
    
    /**
     * Trigger DocuSeal document regeneration for a user
     * 
     * @param int $user_id User ID
     * @return bool Success
     */
    private function trigger_docuseal_regeneration($user_id) {
        // Get user's Form 3 entry ID
        $entry_id = get_user_meta($user_id, 'aks_form_2_entry_id', true);
        
        if (empty($entry_id)) {
            return false;
        }
        
        // Get the entry
        if (!class_exists('GFAPI')) {
            return false;
        }
        
        $entry = GFAPI::get_entry($entry_id);
        if (is_wp_error($entry)) {
            return false;
        }
        
        // Get DocuSeal integration class
        if (!class_exists('AKS_DocuSeal_Integration')) {
            if (defined('AKS_INTEGRATION_PLUGIN_DIR')) {
                require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/docuseal/class-docuseal-integration.php';
            } else {
                return false;
            }
        }
        
        // Get the form
        $form = GFAPI::get_form(3);
        
        // Trigger document generation
        $docuseal = new AKS_DocuSeal_Integration();
        $docuseal->send_to_docuseal($entry, $form, true);
        
        return true;
    }
    
    /**
     * AJAX: Get users for batch processing
     */
    public function ajax_get_users() {
        check_ajax_referer('aks_waiver_reset_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $limit = isset($_POST['limit']) ? min(100, absint($_POST['limit'])) : 10;
        $criteria = isset($_POST['criteria']) ? sanitize_text_field($_POST['criteria']) : 'all_pending';
        
        global $wpdb;
        $user_ids = array();
        
        switch ($criteria) {
            case 'all_pending':
                // Users with docuseal_url but not signed
                $user_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT um1.user_id FROM {$wpdb->usermeta} um1
                    LEFT JOIN {$wpdb->usermeta} um2 ON um1.user_id = um2.user_id AND um2.meta_key = 'sr_waiver_signed' AND um2.meta_value = 'yes'
                    WHERE um1.meta_key = 'docuseal_url' AND um1.meta_value != '' AND um2.user_id IS NULL
                    ORDER BY um1.user_id ASC
                    LIMIT %d OFFSET %d",
                    $limit,
                    $offset
                ));
                break;
                
            case 'all_signed':
                // Users with signed waivers
                $user_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT user_id FROM {$wpdb->usermeta} 
                    WHERE meta_key = 'sr_waiver_signed' AND meta_value = 'yes'
                    ORDER BY user_id ASC
                    LIMIT %d OFFSET %d",
                    $limit,
                    $offset
                ));
                break;
                
            case 'all':
                // All users with any waiver-related meta
                $user_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT user_id FROM {$wpdb->usermeta} 
                    WHERE meta_key IN ('sr_waiver_signed', 'docuseal_url')
                    ORDER BY user_id ASC
                    LIMIT %d OFFSET %d",
                    $limit,
                    $offset
                ));
                break;
                
            case 'not_sent':
                // Users without docuseal_url who have completed registration
                $user_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT um1.user_id FROM {$wpdb->usermeta} um1
                    LEFT JOIN {$wpdb->usermeta} um2 ON um1.user_id = um2.user_id AND um2.meta_key = 'docuseal_url' AND um2.meta_value != ''
                    WHERE um1.meta_key = 'aks_form_2_entry_id' AND um1.meta_value != '' AND um2.user_id IS NULL
                    ORDER BY um1.user_id ASC
                    LIMIT %d OFFSET %d",
                    $limit,
                    $offset
                ));
                break;
        }
        
        $user_list = array();
        foreach ($user_ids as $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $waiver_signed = get_user_meta($user_id, 'sr_waiver_signed', true);
                $docuseal_url = get_user_meta($user_id, 'docuseal_url', true);
                
                $status = 'not_sent';
                if ($waiver_signed === 'yes') {
                    $status = 'signed';
                } elseif (!empty($docuseal_url)) {
                    $status = 'pending';
                }
                
                $user_list[] = array(
                    'id' => $user->ID,
                    'email' => $user->user_email,
                    'name' => trim($user->first_name . ' ' . $user->last_name),
                    'status' => $status,
                );
            }
        }
        
        wp_send_json_success($user_list);
    }
    
    /**
     * AJAX: Process single user waiver reset
     */
    public function ajax_process_user() {
        check_ajax_referer('aks_waiver_reset_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $regenerate = isset($_POST['regenerate']) && $_POST['regenerate'] === 'true';
        
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }
        
        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error('User not found');
        }
        
        $result = $this->reset_user_waiver($user_id, $regenerate);
        
        wp_send_json_success(array(
            'user_id' => $user_id,
            'email' => $user->user_email,
            'name' => trim($user->first_name . ' ' . $user->last_name),
            'reset' => $result['reset'],
            'regenerated' => $result['regenerated'],
            'message' => $result['message'],
        ));
    }
    
    /**
     * Get count of users by criteria
     */
    private function get_user_count_by_criteria($criteria) {
        global $wpdb;
        
        switch ($criteria) {
            case 'all_pending':
                return (int) $wpdb->get_var(
                    "SELECT COUNT(DISTINCT um1.user_id) FROM {$wpdb->usermeta} um1
                    LEFT JOIN {$wpdb->usermeta} um2 ON um1.user_id = um2.user_id AND um2.meta_key = 'sr_waiver_signed' AND um2.meta_value = 'yes'
                    WHERE um1.meta_key = 'docuseal_url' AND um1.meta_value != '' AND um2.user_id IS NULL"
                );
                
            case 'all_signed':
                return (int) $wpdb->get_var(
                    "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} 
                    WHERE meta_key = 'sr_waiver_signed' AND meta_value = 'yes'"
                );
                
            case 'all':
                return (int) $wpdb->get_var(
                    "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} 
                    WHERE meta_key IN ('sr_waiver_signed', 'docuseal_url')"
                );
                
            case 'not_sent':
                return (int) $wpdb->get_var(
                    "SELECT COUNT(DISTINCT um1.user_id) FROM {$wpdb->usermeta} um1
                    LEFT JOIN {$wpdb->usermeta} um2 ON um1.user_id = um2.user_id AND um2.meta_key = 'docuseal_url' AND um2.meta_value != ''
                    WHERE um1.meta_key = 'aks_form_2_entry_id' AND um1.meta_value != '' AND um2.user_id IS NULL"
                );
                
            default:
                return 0;
        }
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get counts for each criteria
        $pending_count = $this->get_user_count_by_criteria('all_pending');
        $signed_count = $this->get_user_count_by_criteria('all_signed');
        $all_count = $this->get_user_count_by_criteria('all');
        $not_sent_count = $this->get_user_count_by_criteria('not_sent');
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Waiver Reset Tool', 'aks-integration'); ?></h1>
            
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; border-radius: 4px;">
                <h2 style="margin-top: 0;"><?php echo esc_html__('Individual Reset', 'aks-integration'); ?></h2>
                <p><?php echo esc_html__('To reset an individual user\'s waiver, go to Users → All Users and use the "Reset Waiver" or "Reset & Resend" links in the row actions.', 'aks-integration'); ?></p>
                <p><a href="<?php echo esc_url(admin_url('users.php')); ?>" class="button"><?php echo esc_html__('Go to Users List', 'aks-integration'); ?></a></p>
            </div>
            
            <?php $this->render_waiver_stats(); ?>
            
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; border-radius: 4px;">
                <h2 style="margin-top: 0;"><?php echo esc_html__('Bulk Reset & Resend', 'aks-integration'); ?></h2>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Reset Criteria', 'aks-integration'); ?></th>
                            <td>
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="radio" name="reset_criteria" id="criteria-pending" value="all_pending" checked />
                                    <?php echo esc_html__('All users with pending (unsigned) waivers', 'aks-integration'); ?>
                                    <strong>(<?php echo number_format($pending_count); ?>)</strong>
                                </label>
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="radio" name="reset_criteria" id="criteria-signed" value="all_signed" />
                                    <?php echo esc_html__('All users with signed waivers (force re-sign)', 'aks-integration'); ?>
                                    <strong>(<?php echo number_format($signed_count); ?>)</strong>
                                </label>
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="radio" name="reset_criteria" id="criteria-not-sent" value="not_sent" />
                                    <?php echo esc_html__('Users with completed registration but no waiver sent', 'aks-integration'); ?>
                                    <strong>(<?php echo number_format($not_sent_count); ?>)</strong>
                                </label>
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="radio" name="reset_criteria" id="criteria-all" value="all" />
                                    <?php echo esc_html__('All users with any waiver status', 'aks-integration'); ?>
                                    <strong>(<?php echo number_format($all_count); ?>)</strong>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php echo esc_html__('Action', 'aks-integration'); ?></th>
                            <td>
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="radio" name="reset_action" id="action-reset" value="reset_only" checked />
                                    <?php echo esc_html__('Reset only (clear status and URL)', 'aks-integration'); ?>
                                </label>
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="radio" name="reset_action" id="action-regenerate" value="reset_and_regenerate" />
                                    <?php echo esc_html__('Reset and send new signing documents', 'aks-integration'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php echo esc_html__('Batch Settings', 'aks-integration'); ?></th>
                            <td>
                                <label>
                                    Start Offset: <input type="number" id="sync-offset" value="0" min="0" style="width: 80px;" />
                                </label>
                                &nbsp;&nbsp;
                                <label>
                                    Batch Size: <input type="number" id="sync-limit" value="10" min="1" max="50" style="width: 80px;" />
                                </label>
                                &nbsp;&nbsp;
                                <label>
                                    End Offset: <input type="number" id="sync-end-offset" value="<?php echo $pending_count; ?>" min="0" style="width: 80px;" />
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php echo esc_html__('Timing', 'aks-integration'); ?></th>
                            <td>
                                <label>
                                    Delay between users: <input type="number" id="sync-user-delay" value="500" min="100" max="5000" step="100" style="width: 80px;" /> ms
                                </label>
                                &nbsp;&nbsp;
                                <label>
                                    Delay between batches: <input type="number" id="sync-batch-delay" value="5" min="1" max="60" style="width: 60px;" /> seconds
                                </label>
                                <p class="description">For "Reset & Resend", use longer delays (1000ms+ between users) to avoid overwhelming DocuSeal API</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <p>
                    <button type="button" id="btn-start-sync" class="button button-large">
                        Process Single Batch
                    </button>
                    <button type="button" id="btn-auto-process" class="button button-primary button-large" style="background: #00a32a; border-color: #00a32a;">
                        ▶ Auto Process All
                    </button>
                    <button type="button" id="btn-stop-sync" class="button button-large" style="display: none;">
                        ⏹ Stop
                    </button>
                    <span id="sync-status" style="margin-left: 15px; font-style: italic;"></span>
                </p>
                
                <div id="batch-progress" style="display: none; margin-top: 15px; padding: 15px; background: #f0f6fc; border: 1px solid #c3c4c7; border-radius: 4px;">
                    <strong>Batch Progress:</strong> 
                    <span id="batch-current">0</span> / <span id="batch-total">0</span> batches
                    &nbsp;|&nbsp;
                    <strong>Current Offset:</strong> <span id="current-offset">0</span>
                    &nbsp;|&nbsp;
                    <span id="batch-countdown"></span>
                </div>
            </div>
            
            <div class="aks-sync-progress" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; display: none;">
                <h2 style="margin-top: 0;">Progress</h2>
                <div class="progress-bar-container" style="background: #f0f0f1; height: 24px; border-radius: 4px; overflow: hidden; margin-bottom: 15px;">
                    <div id="progress-bar" style="width: 0%; height: 100%; background: #2271b1; transition: width 0.3s;"></div>
                </div>
                <p id="progress-text">0 / 0 users processed</p>
                
                <div id="sync-summary" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-top: 15px;">
                    <div style="text-align: center; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                        <div id="stat-processed" style="font-size: 24px; font-weight: 600;">0</div>
                        <div style="color: #646970; font-size: 12px;">Processed</div>
                    </div>
                    <div style="text-align: center; padding: 10px; background: #d4edda; border-radius: 4px;">
                        <div id="stat-reset" style="font-size: 24px; font-weight: 600; color: #155724;">0</div>
                        <div style="color: #155724; font-size: 12px;">Reset Only</div>
                    </div>
                    <div style="text-align: center; padding: 10px; background: #cce5ff; border-radius: 4px;">
                        <div id="stat-regenerated" style="font-size: 24px; font-weight: 600; color: #004085;">0</div>
                        <div style="color: #004085; font-size: 12px;">Reset & Sent</div>
                    </div>
                    <div style="text-align: center; padding: 10px; background: #f8d7da; border-radius: 4px;">
                        <div id="stat-errors" style="font-size: 24px; font-weight: 600; color: #721c24;">0</div>
                        <div style="color: #721c24; font-size: 12px;">Errors</div>
                    </div>
                </div>
            </div>
            
            <div class="aks-sync-results" style="background: #fff; border: 1px solid #ccd0d4; margin: 20px 0;">
                <h2 style="padding: 15px 20px; margin: 0; border-bottom: 1px solid #ccd0d4; background: #f6f7f7;">Results Log</h2>
                <div id="results-container" style="max-height: 500px; overflow-y: auto;">
                    <table class="wp-list-table widefat fixed striped" id="results-table">
                        <thead>
                            <tr>
                                <th style="width: 60px;">User ID</th>
                                <th style="width: 200px;">Email</th>
                                <th style="width: 150px;">Name</th>
                                <th style="width: 100px;">Previous Status</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody id="results-body">
                            <tr id="results-empty">
                                <td colspan="5" style="text-align: center; color: #646970; padding: 30px;">
                                    Select criteria and click "Auto Process All" to begin
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <style>
            .status-success { color: #155724; }
            .status-error { color: #721c24; }
            .status-signed { color: #46b450; }
            .status-pending { color: #ffb900; }
            .status-not-sent { color: #dc3232; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var isRunning = false;
            var shouldStop = false;
            var isAutoMode = false;
            var users = [];
            var currentIndex = 0;
            var stats = { processed: 0, reset: 0, regenerated: 0, errors: 0 };
            var currentOffset = 0;
            var batchSize = 10;
            var endOffset = 0;
            var batchCount = 0;
            var totalBatches = 0;
            var countdownInterval = null;
            
            // Update end offset when criteria changes
            $('input[name="reset_criteria"]').on('change', function() {
                var counts = {
                    'all_pending': <?php echo $pending_count; ?>,
                    'all_signed': <?php echo $signed_count; ?>,
                    'not_sent': <?php echo $not_sent_count; ?>,
                    'all': <?php echo $all_count; ?>
                };
                $('#sync-end-offset').val(counts[$(this).val()] || 0);
            });
            
            function processUser(user, callback) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aks_process_waiver_reset',
                        nonce: '<?php echo wp_create_nonce('aks_waiver_reset_nonce'); ?>',
                        user_id: user.id,
                        regenerate: $('input[name="reset_action"]:checked').val() === 'reset_and_regenerate'
                    },
                    success: function(response) {
                        callback(response.success ? response.data : null, response.success ? null : response.data);
                    },
                    error: function() {
                        callback(null, 'Request failed');
                    }
                });
            }
            
            function addResultRow(result, prevStatus) {
                $('#results-empty').hide();
                
                var statusClass = 'status-' + prevStatus;
                var statusText = prevStatus === 'signed' ? '✓ Signed' : (prevStatus === 'pending' ? '⏳ Pending' : '✗ Not Sent');
                
                var resultClass = result.regenerated ? 'status-success' : (result.reset ? 'status-success' : 'status-error');
                var resultText = result.message || 'Unknown';
                
                if (result.regenerated) {
                    stats.regenerated++;
                } else if (result.reset) {
                    stats.reset++;
                } else {
                    stats.errors++;
                }
                
                var row = '<tr>' +
                    '<td>' + result.user_id + '</td>' +
                    '<td style="font-size: 12px;">' + result.email + '</td>' +
                    '<td>' + result.name + '</td>' +
                    '<td class="' + statusClass + '">' + statusText + '</td>' +
                    '<td class="' + resultClass + '">' + resultText + '</td>' +
                '</tr>';
                
                $('#results-body').prepend(row);
                
                stats.processed++;
                updateStats();
            }
            
            function updateStats() {
                $('#stat-processed').text(stats.processed);
                $('#stat-reset').text(stats.reset);
                $('#stat-regenerated').text(stats.regenerated);
                $('#stat-errors').text(stats.errors);
                
                var progress = users.length > 0 ? (currentIndex / users.length) * 100 : 0;
                $('#progress-bar').css('width', progress + '%');
                $('#progress-text').text(currentIndex + ' / ' + users.length + ' users in current batch');
                
                $('#batch-current').text(batchCount);
                $('#current-offset').text(currentOffset);
            }
            
            function processNext() {
                if (shouldStop) {
                    finishSync();
                    return;
                }
                
                if (currentIndex >= users.length) {
                    if (isAutoMode) {
                        startNextBatch();
                    } else {
                        finishSync();
                    }
                    return;
                }
                
                var user = users[currentIndex];
                $('#sync-status').text('Processing user ' + (currentIndex + 1) + ' of ' + users.length + ' (ID: ' + user.id + ')...');
                
                var prevStatus = user.status;
                
                processUser(user, function(result, error) {
                    if (error) {
                        addResultRow({
                            user_id: user.id,
                            email: user.email,
                            name: user.name,
                            reset: false,
                            regenerated: false,
                            message: error
                        }, prevStatus);
                    } else {
                        addResultRow(result, prevStatus);
                    }
                    
                    currentIndex++;
                    
                    var userDelay = parseInt($('#sync-user-delay').val()) || 500;
                    setTimeout(processNext, userDelay);
                });
            }
            
            function startNextBatch() {
                currentOffset += batchSize;
                
                if (currentOffset >= endOffset) {
                    $('#sync-status').text('All batches complete!');
                    finishSync();
                    return;
                }
                
                batchCount++;
                var batchDelay = (parseInt($('#sync-batch-delay').val()) || 5) * 1000;
                
                var secondsLeft = batchDelay / 1000;
                $('#batch-countdown').text('Next batch in ' + secondsLeft + 's...');
                
                countdownInterval = setInterval(function() {
                    secondsLeft--;
                    if (secondsLeft > 0) {
                        $('#batch-countdown').text('Next batch in ' + secondsLeft + 's...');
                    } else {
                        $('#batch-countdown').text('Starting batch...');
                        clearInterval(countdownInterval);
                    }
                }, 1000);
                
                setTimeout(function() {
                    if (shouldStop) {
                        finishSync();
                        return;
                    }
                    clearInterval(countdownInterval);
                    $('#batch-countdown').text('Processing...');
                    fetchAndProcessBatch(currentOffset, batchSize);
                }, batchDelay);
            }
            
            function fetchAndProcessBatch(offset, limit) {
                $('#sync-status').text('Fetching users (offset: ' + offset + ')...');
                $('#current-offset').text(offset);
                
                var criteria = $('input[name="reset_criteria"]:checked').val();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aks_get_waiver_users',
                        nonce: '<?php echo wp_create_nonce('aks_waiver_reset_nonce'); ?>',
                        offset: offset,
                        limit: limit,
                        criteria: criteria
                    },
                    success: function(response) {
                        if (response.success) {
                            users = response.data;
                            currentIndex = 0;
                            
                            if (users.length === 0) {
                                if (isAutoMode) {
                                    startNextBatch();
                                } else {
                                    $('#sync-status').text('No users to process in this batch');
                                    finishSync();
                                }
                                return;
                            }
                            
                            updateStats();
                            processNext();
                        } else {
                            alert('Error: ' + response.data);
                            finishSync();
                        }
                    },
                    error: function() {
                        alert('Failed to fetch users');
                        finishSync();
                    }
                });
            }
            
            function finishSync() {
                isRunning = false;
                isAutoMode = false;
                if (countdownInterval) {
                    clearInterval(countdownInterval);
                }
                $('#btn-start-sync, #btn-auto-process').show().prop('disabled', false);
                $('#btn-stop-sync').hide().prop('disabled', false).text('⏹ Stop');
                $('#batch-countdown').text('');
                if (shouldStop) {
                    $('#sync-status').text('Stopped. Processed ' + stats.processed + ' users. Reset: ' + stats.reset + ', Sent: ' + stats.regenerated);
                } else {
                    $('#sync-status').text('Complete! Processed ' + stats.processed + ' users. Reset: ' + stats.reset + ', Sent: ' + stats.regenerated);
                }
            }
            
            $('#btn-start-sync').on('click', function() {
                var offset = parseInt($('#sync-offset').val()) || 0;
                var limit = parseInt($('#sync-limit').val()) || 10;
                
                if (!$('input[name="reset_criteria"]:checked').val()) {
                    alert('Please select a reset criteria');
                    return;
                }
                
                isRunning = true;
                isAutoMode = false;
                shouldStop = false;
                currentIndex = 0;
                currentOffset = offset;
                batchSize = limit;
                batchCount = 1;
                stats = { processed: 0, reset: 0, regenerated: 0, errors: 0 };
                
                $('#btn-start-sync, #btn-auto-process').hide();
                $('#btn-stop-sync').show();
                $('#sync-status').text('Fetching users...');
                $('.aks-sync-progress').show();
                $('#batch-progress').hide();
                $('#results-body').html('<tr id="results-empty"><td colspan="5" style="text-align: center;">Loading...</td></tr>');
                
                fetchAndProcessBatch(offset, limit);
            });
            
            $('#btn-auto-process').on('click', function() {
                var startOffset = parseInt($('#sync-offset').val()) || 0;
                var limit = parseInt($('#sync-limit').val()) || 10;
                endOffset = parseInt($('#sync-end-offset').val()) || 0;
                
                if (!$('input[name="reset_criteria"]:checked').val()) {
                    alert('Please select a reset criteria');
                    return;
                }
                
                var action = $('input[name="reset_action"]:checked').val();
                var actionText = action === 'reset_and_regenerate' ? 'reset waivers AND send new documents' : 'reset waivers only';
                
                totalBatches = Math.ceil((endOffset - startOffset) / limit);
                
                if (!confirm('This will process approximately ' + (endOffset - startOffset) + ' users in ' + totalBatches + ' batches.\n\nAction: ' + actionText + '\n\nContinue?')) {
                    return;
                }
                
                isRunning = true;
                isAutoMode = true;
                shouldStop = false;
                currentIndex = 0;
                currentOffset = startOffset;
                batchSize = limit;
                batchCount = 1;
                stats = { processed: 0, reset: 0, regenerated: 0, errors: 0 };
                
                $('#btn-start-sync, #btn-auto-process').hide();
                $('#btn-stop-sync').show();
                $('#sync-status').text('Starting auto-process...');
                $('.aks-sync-progress').show();
                $('#batch-progress').show();
                $('#batch-total').text(totalBatches);
                $('#results-body').html('<tr id="results-empty"><td colspan="5" style="text-align: center;">Loading...</td></tr>');
                
                fetchAndProcessBatch(startOffset, limit);
            });
            
            $('#btn-stop-sync').on('click', function() {
                shouldStop = true;
                $(this).prop('disabled', true).text('Stopping...');
                if (countdownInterval) {
                    clearInterval(countdownInterval);
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render waiver statistics
     */
    private function render_waiver_stats() {
        global $wpdb;
        
        // Count users by waiver status
        $signed_count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} 
            WHERE meta_key = 'sr_waiver_signed' AND meta_value = 'yes'"
        );
        
        $pending_count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT um1.user_id) FROM {$wpdb->usermeta} um1
            LEFT JOIN {$wpdb->usermeta} um2 ON um1.user_id = um2.user_id AND um2.meta_key = 'sr_waiver_signed' AND um2.meta_value = 'yes'
            WHERE um1.meta_key = 'docuseal_url' AND um1.meta_value != '' AND um2.user_id IS NULL"
        );
        
        $not_sent_count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT u.ID) FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'docuseal_url'
            WHERE um.meta_value IS NULL OR um.meta_value = ''"
        );
        
        ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; border-radius: 4px;">
            <h2 style="margin-top: 0;"><?php echo esc_html__('Waiver Statistics', 'aks-integration'); ?></h2>
            
            <div style="display: flex; gap: 30px;">
                <div style="text-align: center;">
                    <div style="font-size: 36px; font-weight: bold; color: #46b450;"><?php echo number_format($signed_count); ?></div>
                    <div style="color: #666;"><?php echo esc_html__('Signed', 'aks-integration'); ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 36px; font-weight: bold; color: #ffb900;"><?php echo number_format($pending_count); ?></div>
                    <div style="color: #666;"><?php echo esc_html__('Pending', 'aks-integration'); ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 36px; font-weight: bold; color: #dc3232;"><?php echo number_format($not_sent_count); ?></div>
                    <div style="color: #666;"><?php echo esc_html__('Not Sent', 'aks-integration'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }
}

new AKS_Waiver_Reset_Admin();