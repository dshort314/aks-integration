<?php
/**
 * CRM Sync Handler
 * Syncs user profile changes (phone, email, name) to SendPulse and Quo
 * Includes retry queue for failed requests and email notifications
 */

if (!defined('ABSPATH')) {
    exit;
}

class AKS_CRM_Sync_Handler {
    
    private static $instance = null;
    private $settings;
    private $max_retries = 3;
    private $retry_delays = array(300, 300, 300); // 5 minutes each (matches cron interval)
    private $queue_processed = false; // Prevent processing queue multiple times per request
    
    // Notification recipients
    private $notification_emails = array();
    
    public function __construct() {
        $this->settings = get_option('aks_sendpulse_settings');
        
        // Set notification recipients
        $admin_email = get_option('admin_email');
        $this->notification_emails = array(
            $admin_email,
            'darin@shortresults.com' // Temporary - remove after debugging
        );
        
        // Hook into WooCommerce account save
        add_action('woocommerce_save_account_details', array($this, 'handle_account_update'), 10, 1);
        
        // Also hook into profile update for admin changes
        add_action('profile_update', array($this, 'handle_profile_update'), 10, 2);
        
        // Hook into billing/shipping phone updates
        add_action('woocommerce_customer_save_address', array($this, 'handle_address_save'), 10, 2);
        
        // Process retry queue on every page load - checks if items are due
        add_action('init', array($this, 'process_retry_queue'));
        
        // Admin menu for viewing queue
        add_action('admin_menu', array($this, 'add_admin_menu'), 25);
        
        // AJAX handlers
        add_action('wp_ajax_aks_retry_queue_item', array($this, 'ajax_retry_queue_item'));
        add_action('wp_ajax_aks_delete_queue_item', array($this, 'ajax_delete_queue_item'));
        add_action('wp_ajax_aks_clear_completed_queue', array($this, 'ajax_clear_completed_queue'));
        add_action('wp_ajax_aks_process_queue_now', array($this, 'ajax_process_queue_now'));
    }
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Handle WooCommerce account details save
     */
    public function handle_account_update($user_id) {
        $this->sync_user_to_crm($user_id, 'woocommerce_account');
    }
    
    /**
     * Handle WordPress profile update (admin)
     */
    public function handle_profile_update($user_id, $old_user_data) {
        $this->sync_user_to_crm($user_id, 'profile_update');
    }
    
    /**
     * Handle WooCommerce address save (billing/shipping)
     */
    public function handle_address_save($user_id, $address_type) {
        $this->sync_user_to_crm($user_id, 'address_' . $address_type);
    }
    
    /**
     * Main sync function - checks what changed and syncs to CRM
     */
    public function sync_user_to_crm($user_id, $source = 'unknown') {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }
        
        // Get stored CRM IDs
        $sendpulse_contact_id = get_user_meta($user_id, 'sendpulse_contact_id', true);
        $sendpulse_phone_id = get_user_meta($user_id, 'sendpulse_phone_id', true);
        $quo_contact_id = get_user_meta($user_id, 'quo_contact_id', true);
        
        // If no CRM IDs, user hasn't been synced yet - skip
        if (empty($sendpulse_contact_id) && empty($quo_contact_id)) {
            return;
        }
        
        // Get current user data
        $phone = get_user_meta($user_id, 'billing_phone', true);
        $first_name = $user->first_name;
        $last_name = $user->last_name;
        $email = $user->user_email;
        
        // Get previously synced values (stored in user meta)
        $synced_phone = get_user_meta($user_id, '_aks_synced_phone', true);
        $synced_first_name = get_user_meta($user_id, '_aks_synced_first_name', true);
        $synced_last_name = get_user_meta($user_id, '_aks_synced_last_name', true);
        
        $changes = array();
        
        // Detect changes
        if ($phone !== $synced_phone && !empty($phone)) {
            $changes['phone'] = $phone;
        }
        if ($first_name !== $synced_first_name && !empty($first_name)) {
            $changes['first_name'] = $first_name;
        }
        if ($last_name !== $synced_last_name && !empty($last_name)) {
            $changes['last_name'] = $last_name;
        }
        
        // If no changes, skip
        if (empty($changes)) {
            return;
        }
        
        error_log('AKS CRM Sync: Detected changes for user ' . $user_id . ' from ' . $source . ': ' . print_r($changes, true));
        
        // Queue SendPulse sync
        if (!empty($sendpulse_contact_id)) {
            $this->queue_sync('sendpulse', array(
                'user_id' => $user_id,
                'contact_id' => $sendpulse_contact_id,
                'phone_id' => $sendpulse_phone_id,
                'changes' => $changes,
                'email' => $email,
                'source' => $source
            ));
        }
        
        // Queue Quo sync
        if (!empty($quo_contact_id)) {
            $this->queue_sync('quo', array(
                'user_id' => $user_id,
                'contact_id' => $quo_contact_id,
                'changes' => $changes,
                'email' => $email,
                'source' => $source
            ));
        }
        
        // Update synced values
        if (isset($changes['phone'])) {
            update_user_meta($user_id, '_aks_synced_phone', $phone);
        }
        if (isset($changes['first_name'])) {
            update_user_meta($user_id, '_aks_synced_first_name', $first_name);
        }
        if (isset($changes['last_name'])) {
            update_user_meta($user_id, '_aks_synced_last_name', $last_name);
        }
    }
    
    /**
     * Queue a sync operation
     */
    private function queue_sync($service, $data) {
        $queue = get_option('aks_crm_sync_queue', array());
        
        $queue_item = array(
            'id' => uniqid('sync_'),
            'service' => $service,
            'data' => $data,
            'attempts' => 0,
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'next_attempt' => current_time('mysql'),
            'last_error' => '',
            'completed_at' => null
        );
        
        $queue[] = $queue_item;
        update_option('aks_crm_sync_queue', $queue);
        
        error_log('AKS CRM Sync: Queued ' . $service . ' sync for user ' . $data['user_id']);
        
        // Try to process immediately
        $this->process_single_item($queue_item);
    }
    
    /**
     * Process the retry queue (called on init)
     */
    public function process_retry_queue() {
        // Prevent processing multiple times per request
        if ($this->queue_processed) {
            return;
        }
        $this->queue_processed = true;
        
        $queue = get_option('aks_crm_sync_queue', array());
        
        if (empty($queue)) {
            return;
        }
        
        $now = current_time('mysql');
        $updated = false;
        
        foreach ($queue as $key => $item) {
            // Skip completed or failed items
            if (in_array($item['status'], array('completed', 'failed'))) {
                continue;
            }
            
            // Check if it's time to retry
            if (strtotime($item['next_attempt']) > strtotime($now)) {
                continue;
            }
            
            // Process the item
            $result = $this->process_single_item($item);
            
            // Update queue item
            $queue[$key] = $result;
            $updated = true;
        }
        
        if ($updated) {
            update_option('aks_crm_sync_queue', $queue);
        }
    }
    
    /**
     * Process a single queue item
     */
    private function process_single_item($item) {
        $item['attempts']++;
        $item['last_attempt'] = current_time('mysql');
        
        error_log('AKS CRM Sync: Processing ' . $item['service'] . ' sync, attempt ' . $item['attempts']);
        
        $success = false;
        $error = '';
        
        try {
            if ($item['service'] === 'sendpulse') {
                $result = $this->sync_to_sendpulse($item['data']);
                $success = $result['success'];
                $error = $result['error'] ?? '';
            } elseif ($item['service'] === 'quo') {
                $result = $this->sync_to_quo($item['data']);
                $success = $result['success'];
                $error = $result['error'] ?? '';
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
        
        if ($success) {
            $item['status'] = 'completed';
            $item['completed_at'] = current_time('mysql');
            error_log('AKS CRM Sync: ' . $item['service'] . ' sync completed successfully');
        } else {
            $item['last_error'] = $error;
            
            if ($item['attempts'] >= $this->max_retries) {
                $item['status'] = 'failed';
                error_log('AKS CRM Sync: ' . $item['service'] . ' sync failed after ' . $this->max_retries . ' attempts: ' . $error);
                
                // Send notification
                $this->send_failure_notification($item);
            } else {
                $item['status'] = 'pending';
                // Schedule next retry using WordPress time
                $delay = $this->retry_delays[$item['attempts'] - 1] ?? 300;
                $item['next_attempt'] = date('Y-m-d H:i:s', current_time('timestamp') + $delay);
                error_log('AKS CRM Sync: ' . $item['service'] . ' sync failed, will retry at ' . $item['next_attempt'] . ': ' . $error);
            }
        }
        
        // Update the queue
        $queue = get_option('aks_crm_sync_queue', array());
        foreach ($queue as $key => $q_item) {
            if ($q_item['id'] === $item['id']) {
                $queue[$key] = $item;
                break;
            }
        }
        update_option('aks_crm_sync_queue', $queue);
        
        return $item;
    }
    
    /**
     * Sync changes to SendPulse
     */
    private function sync_to_sendpulse($data) {
        if (empty($this->settings['api_id']) || empty($this->settings['api_secret'])) {
            return array('success' => false, 'error' => 'SendPulse API not configured');
        }
        
        require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-sendpulse-api.php';
        $api = new AKS_SendPulse_API($this->settings['api_id'], $this->settings['api_secret']);
        
        $changes = $data['changes'];
        $contact_id = $data['contact_id'];
        $errors = array();
        
        // Update phone if changed
        if (isset($changes['phone'])) {
            // SendPulse requires updating phone via the phones endpoint
            // First, we need to get the current phone ID or add a new phone
            $phone_result = $api->add_phone_to_contact($contact_id, $changes['phone']);
            
            if ($phone_result === false) {
                $errors[] = 'Failed to update phone';
            } else {
                // Store the new phone ID if returned
                if (isset($phone_result['data']['id'])) {
                    update_user_meta($data['user_id'], 'sendpulse_phone_id', $phone_result['data']['id']);
                }
            }
        }
        
        // Update name if changed
        if (isset($changes['first_name']) || isset($changes['last_name'])) {
            $user = get_userdata($data['user_id']);
            $first_name = $changes['first_name'] ?? $user->first_name;
            $last_name = $changes['last_name'] ?? $user->last_name;
            
            $name_result = $api->update_contact_name($contact_id, $first_name, $last_name);
            
            if ($name_result === false) {
                $errors[] = 'Failed to update name';
            }
        }
        
        if (!empty($errors)) {
            return array('success' => false, 'error' => implode('; ', $errors));
        }
        
        return array('success' => true);
    }
    
    /**
     * Sync changes to Quo
     */
    private function sync_to_quo($data) {
        if (empty($this->settings['quo_api_key'])) {
            return array('success' => false, 'error' => 'Quo API not configured');
        }
        
        require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-quo-api.php';
        $api = new AKS_Quo_API($this->settings['quo_api_key']);
        
        $changes = $data['changes'];
        $contact_id = $data['contact_id'];
        
        // Get current user data for complete update
        $user = get_userdata($data['user_id']);
        $first_name = $changes['first_name'] ?? $user->first_name;
        $last_name = $changes['last_name'] ?? $user->last_name;
        $phone = $changes['phone'] ?? get_user_meta($data['user_id'], 'billing_phone', true);
        
        // Quo update requires all fields
        $result = $api->update_contact_names($contact_id, $first_name, $last_name, $phone);
        
        if ($result === false) {
            return array('success' => false, 'error' => 'Quo API call failed');
        }
        
        return array('success' => true);
    }
    
    /**
     * Send failure notification email
     */
    private function send_failure_notification($item) {
        $user = get_userdata($item['data']['user_id']);
        $user_name = $user ? $user->display_name : 'Unknown';
        $user_email = $item['data']['email'] ?? 'Unknown';
        
        $subject = '[AKS Integration] CRM Sync Failed - ' . ucfirst($item['service']);
        
        $message = "A CRM sync operation has failed after {$this->max_retries} attempts.\n\n";
        $message .= "Service: " . ucfirst($item['service']) . "\n";
        $message .= "User: {$user_name} ({$user_email})\n";
        $message .= "User ID: {$item['data']['user_id']}\n";
        $message .= "Source: {$item['data']['source']}\n";
        $message .= "Changes attempted: " . print_r($item['data']['changes'], true) . "\n";
        $message .= "Last error: {$item['last_error']}\n";
        $message .= "Created: {$item['created_at']}\n";
        $message .= "Failed at: " . current_time('mysql') . "\n\n";
        $message .= "Please check the CRM Sync Queue in the WordPress admin for more details.\n";
        $message .= admin_url('admin.php?page=aks-crm-sync-queue');
        
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        
        foreach ($this->notification_emails as $email) {
            wp_mail($email, $subject, $message, $headers);
        }
        
        error_log('AKS CRM Sync: Failure notification sent to ' . implode(', ', $this->notification_emails));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'aks-integration',
            'CRM Sync Queue',
            'CRM Sync Queue',
            'manage_options',
            'aks-crm-sync-queue',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        $queue = get_option('aks_crm_sync_queue', array());
        
        // Sort by created_at descending
        usort($queue, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        $pending = array_filter($queue, function($item) { return $item['status'] === 'pending'; });
        $failed = array_filter($queue, function($item) { return $item['status'] === 'failed'; });
        $completed = array_filter($queue, function($item) { return $item['status'] === 'completed'; });
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('CRM Sync Queue', 'aks-integration'); ?></h1>
            
            <div class="notice notice-info">
                <p>
                    <strong>Queue Summary:</strong>
                    Pending: <?php echo count($pending); ?> |
                    Failed: <?php echo count($failed); ?> |
                    Completed: <?php echo count($completed); ?>
                </p>
            </div>
            
            <?php if (count($failed) > 0): ?>
            <div class="notice notice-error">
                <p><strong>There are <?php echo count($failed); ?> failed sync operations that require attention.</strong></p>
            </div>
            <?php endif; ?>
            
            <div style="margin-bottom: 20px;">
                <button type="button" id="btn-process-queue" class="button button-primary">
                    ▶ Process Queue Now
                </button>
                <button type="button" id="btn-clear-completed" class="button" <?php echo count($completed) === 0 ? 'disabled' : ''; ?>>
                    Clear Completed Items (<?php echo count($completed); ?>)
                </button>
                <button type="button" id="btn-refresh" class="button">
                    🔄 Refresh
                </button>
                
                <?php
                $next_scheduled = wp_next_scheduled('aks_process_crm_retry_queue');
                if ($next_scheduled) {
                    echo '<span style="margin-left: 15px; color: #666;">Next auto-run: ' . date('Y-m-d H:i:s', $next_scheduled) . '</span>';
                } else {
                    echo '<span style="margin-left: 15px; color: #dc3545;">⚠ Cron not scheduled!</span>';
                }
                ?>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 140px;">Created</th>
                        <th style="width: 100px;">Service</th>
                        <th style="width: 80px;">Status</th>
                        <th style="width: 80px;">Attempts</th>
                        <th>User</th>
                        <th>Changes</th>
                        <th>Error</th>
                        <th style="width: 140px;">Next Attempt</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($queue)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center;">No sync operations in queue</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($queue as $item): ?>
                    <?php
                        $user = get_userdata($item['data']['user_id']);
                        $user_display = $user ? $user->display_name . ' (' . $user->user_email . ')' : 'User #' . $item['data']['user_id'];
                        $status_class = $item['status'] === 'completed' ? 'success' : ($item['status'] === 'failed' ? 'error' : 'warning');
                    ?>
                    <tr data-id="<?php echo esc_attr($item['id']); ?>">
                        <td><?php echo esc_html($item['created_at']); ?></td>
                        <td><?php echo esc_html(ucfirst($item['service'])); ?></td>
                        <td>
                            <span class="aks-status-badge <?php echo $status_class; ?>">
                                <?php echo esc_html(ucfirst($item['status'])); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($item['attempts']); ?> / <?php echo $this->max_retries; ?></td>
                        <td><?php echo esc_html($user_display); ?></td>
                        <td>
                            <?php 
                            $changes = array();
                            foreach ($item['data']['changes'] as $field => $value) {
                                $changes[] = $field . ': ' . $value;
                            }
                            echo esc_html(implode(', ', $changes));
                            ?>
                        </td>
                        <td style="color: #dc3545;"><?php echo esc_html($item['last_error']); ?></td>
                        <td>
                            <?php if ($item['status'] === 'pending'): ?>
                                <?php echo esc_html($item['next_attempt']); ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($item['status'] !== 'completed'): ?>
                            <button type="button" class="button button-small btn-retry" data-id="<?php echo esc_attr($item['id']); ?>">Retry Now</button>
                            <?php endif; ?>
                            <button type="button" class="button button-small btn-delete" data-id="<?php echo esc_attr($item['id']); ?>">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <style>
            .aks-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .aks-status-badge.success { background: #d4edda; color: #155724; }
            .aks-status-badge.error { background: #f8d7da; color: #721c24; }
            .aks-status-badge.warning { background: #fff3cd; color: #856404; }
        </style>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var nonce = '<?php echo wp_create_nonce('aks_crm_sync_nonce'); ?>';
            
            // Process Queue Now
            $('#btn-process-queue').click(function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Processing...');
                
                $.post(ajaxurl, {
                    action: 'aks_process_queue_now',
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                        btn.prop('disabled', false).text('▶ Process Queue Now');
                    }
                });
            });
            
            // Retry button
            $('.btn-retry').click(function() {
                var id = $(this).data('id');
                var btn = $(this);
                btn.prop('disabled', true).text('Retrying...');
                
                $.post(ajaxurl, {
                    action: 'aks_retry_queue_item',
                    nonce: nonce,
                    id: id
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                        btn.prop('disabled', false).text('Retry');
                    }
                });
            });
            
            // Delete button
            $('.btn-delete').click(function() {
                if (!confirm('Are you sure you want to delete this queue item?')) return;
                
                var id = $(this).data('id');
                var row = $(this).closest('tr');
                
                $.post(ajaxurl, {
                    action: 'aks_delete_queue_item',
                    nonce: nonce,
                    id: id
                }, function(response) {
                    if (response.success) {
                        row.fadeOut(function() { $(this).remove(); });
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
            
            // Clear completed
            $('#btn-clear-completed').click(function() {
                if (!confirm('Clear all completed items from the queue?')) return;
                
                $.post(ajaxurl, {
                    action: 'aks_clear_completed_queue',
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    }
                });
            });
            
            // Refresh
            $('#btn-refresh').click(function() {
                location.reload();
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Retry queue item
     */
    public function ajax_retry_queue_item() {
        check_ajax_referer('aks_crm_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $id = sanitize_text_field($_POST['id']);
        $queue = get_option('aks_crm_sync_queue', array());
        
        foreach ($queue as $key => $item) {
            if ($item['id'] === $id) {
                // Never reset attempts - just process
                $queue[$key]['status'] = 'pending';
                $queue[$key]['next_attempt'] = current_time('mysql');
                update_option('aks_crm_sync_queue', $queue);
                
                // Process immediately
                $this->process_single_item($queue[$key]);
                
                wp_send_json_success();
                return;
            }
        }
        
        wp_send_json_error('Item not found');
    }
    
    /**
     * AJAX: Delete queue item
     */
    public function ajax_delete_queue_item() {
        check_ajax_referer('aks_crm_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $id = sanitize_text_field($_POST['id']);
        $queue = get_option('aks_crm_sync_queue', array());
        
        $queue = array_filter($queue, function($item) use ($id) {
            return $item['id'] !== $id;
        });
        
        update_option('aks_crm_sync_queue', array_values($queue));
        wp_send_json_success();
    }
    
    /**
     * AJAX: Clear completed items
     */
    public function ajax_clear_completed_queue() {
        check_ajax_referer('aks_crm_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $queue = get_option('aks_crm_sync_queue', array());
        
        $queue = array_filter($queue, function($item) {
            return $item['status'] !== 'completed';
        });
        
        update_option('aks_crm_sync_queue', array_values($queue));
        wp_send_json_success();
    }
    
    /**
     * AJAX: Process queue now (manual trigger)
     */
    public function ajax_process_queue_now() {
        check_ajax_referer('aks_crm_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        // Process the queue
        $this->process_retry_queue();
        
        wp_send_json_success();
    }
}