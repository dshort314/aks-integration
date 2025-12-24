<?php
/**
 * CRM User Sync Tool
 * Processes WordPress users and syncs them with SendPulse and Quo (OpenPhone)
 * 
 * Checks for missing CRM IDs, searches for existing contacts, creates if needed
 */

if (!defined('ABSPATH')) {
    exit;
}

class AKS_CRM_User_Sync {
    
    private static $instance = null;
    private $settings;
    
    // User meta keys
    const META_SENDPULSE_CONTACT_ID = 'sendpulse_contact_id';
    const META_SENDPULSE_USER_ID = 'sendpulse_user_id';
    const META_SENDPULSE_PHONE_ID = 'sendpulse_phone_id';
    const META_SENDPULSE_EMAIL_ID = 'sendpulse_email_id';
    const META_QUO_CONTACT_ID = 'quo_contact_id';
    const META_QUO_PHONE_ID = 'quo_phone_id';
    
    public function __construct() {
        $this->settings = get_option('aks_sendpulse_settings');
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Handle AJAX requests
        add_action('wp_ajax_aks_process_user_sync', array($this, 'ajax_process_user'));
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
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'aks-integration',
            'CRM User Sync',
            'CRM User Sync',
            'manage_options',
            'aks-crm-sync',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Get users for processing
     */
    private function get_users($offset = 0, $limit = 10) {
        $args = array(
            'number' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
        );
        
        return get_users($args);
    }
    
    /**
     * Get total user count
     */
    private function get_total_users() {
        $result = count_users();
        return $result['total_users'];
    }
    
    /**
     * Check if user needs CRM sync
     */
    private function user_needs_sync($user_id) {
        $needs = array(
            'sendpulse' => false,
            'quo' => false,
        );
        
        // Check SendPulse
        $sp_contact = get_user_meta($user_id, self::META_SENDPULSE_CONTACT_ID, true);
        $sp_user = get_user_meta($user_id, self::META_SENDPULSE_USER_ID, true);
        $sp_phone = get_user_meta($user_id, self::META_SENDPULSE_PHONE_ID, true);
        $sp_email = get_user_meta($user_id, self::META_SENDPULSE_EMAIL_ID, true);
        
        if (empty($sp_contact) || empty($sp_user)) {
            $needs['sendpulse'] = true;
        }
        
        // Check Quo
        $quo_contact = get_user_meta($user_id, self::META_QUO_CONTACT_ID, true);
        $quo_phone = get_user_meta($user_id, self::META_QUO_PHONE_ID, true);
        
        if (empty($quo_contact)) {
            $needs['quo'] = true;
        }
        
        return $needs;
    }
    
    /**
     * Get user's current CRM status
     */
    private function get_user_crm_status($user_id) {
        return array(
            'sendpulse_contact_id' => get_user_meta($user_id, self::META_SENDPULSE_CONTACT_ID, true),
            'sendpulse_user_id' => get_user_meta($user_id, self::META_SENDPULSE_USER_ID, true),
            'sendpulse_phone_id' => get_user_meta($user_id, self::META_SENDPULSE_PHONE_ID, true),
            'sendpulse_email_id' => get_user_meta($user_id, self::META_SENDPULSE_EMAIL_ID, true),
            'quo_contact_id' => get_user_meta($user_id, self::META_QUO_CONTACT_ID, true),
            'quo_phone_id' => get_user_meta($user_id, self::META_QUO_PHONE_ID, true),
        );
    }
    
    /**
     * Process single user - sync with SendPulse
     */
    private function sync_user_sendpulse($user_id, $user_data, $add_sms_tag = false) {
        $result = array(
            'action' => 'none',
            'status' => 'skipped',
            'message' => '',
            'ids' => array(),
        );
        
        // Check if API credentials are configured
        if (empty($this->settings['api_id']) || empty($this->settings['api_secret'])) {
            $result['status'] = 'error';
            $result['message'] = 'SendPulse API credentials not configured';
            return $result;
        }
        
        // Initialize API
        if (!class_exists('AKS_SendPulse_API')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-sendpulse-api.php';
        }
        
        $api = new AKS_SendPulse_API($this->settings['api_id'], $this->settings['api_secret']);
        
        $email = $user_data['email'];
        $phone = $user_data['phone'];
        $first_name = $user_data['first_name'];
        $last_name = $user_data['last_name'];
        
        // Search for existing contact
        $search_result = $api->search_contact($email, $phone);
        
        if ($search_result['exists']) {
            // Contact found - get full details
            $result['action'] = 'found';
            $contact_id = $search_result['contact_id'];
            
            $contact = $api->get_contact($contact_id);
            
            if ($contact && isset($contact['data'])) {
                $contact_data = $contact['data'];
                
                // Update user meta with IDs
                update_user_meta($user_id, self::META_SENDPULSE_CONTACT_ID, $contact_data['id']);
                update_user_meta($user_id, self::META_SENDPULSE_USER_ID, $contact_data['userId']);
                
                $result['ids']['contact_id'] = $contact_data['id'];
                $result['ids']['user_id'] = $contact_data['userId'];
                
                // Get phone ID if available
                if (isset($contact_data['phones']) && !empty($contact_data['phones'])) {
                    update_user_meta($user_id, self::META_SENDPULSE_PHONE_ID, $contact_data['phones'][0]['id']);
                    $result['ids']['phone_id'] = $contact_data['phones'][0]['id'];
                }
                
                // Get email ID if available
                if (isset($contact_data['emails']) && !empty($contact_data['emails'])) {
                    update_user_meta($user_id, self::META_SENDPULSE_EMAIL_ID, $contact_data['emails'][0]['id']);
                    $result['ids']['email_id'] = $contact_data['emails'][0]['id'];
                }
                
                $result['status'] = 'success';
                $result['message'] = 'Found existing contact, updated user meta';
                
                // Add SMS opted-in tag if requested
                if ($add_sms_tag && !empty($result['ids']['contact_id'])) {
                    $tag_result = $api->add_tag_to_contact($result['ids']['contact_id'], 55139);
                    if ($tag_result) {
                        $result['message'] .= ' + SMS tag';
                    }
                }
            } else {
                $result['status'] = 'error';
                $result['message'] = 'Found contact but could not retrieve details';
            }
        } else {
            // Contact not found - create new
            $result['action'] = 'created';
            
            $contact_data = array(
                'firstName' => $first_name,
                'lastName' => $last_name,
            );
            
            if (!empty($email)) {
                $contact_data['email'] = $email;
            }
            
            if (!empty($phone)) {
                $contact_data['phone'] = $phone;
            }
            
            $create_result = $api->create_contact($contact_data);
            
            if ($create_result && isset($create_result['data'])) {
                $data = $create_result['data'];
                
                // Update user meta
                if (isset($data['id'])) {
                    update_user_meta($user_id, self::META_SENDPULSE_CONTACT_ID, $data['id']);
                    $result['ids']['contact_id'] = $data['id'];
                }
                
                if (isset($data['userId'])) {
                    update_user_meta($user_id, self::META_SENDPULSE_USER_ID, $data['userId']);
                    $result['ids']['user_id'] = $data['userId'];
                }
                
                if (isset($data['phones'][0]['id'])) {
                    update_user_meta($user_id, self::META_SENDPULSE_PHONE_ID, $data['phones'][0]['id']);
                    $result['ids']['phone_id'] = $data['phones'][0]['id'];
                }
                
                if (isset($data['emails'][0]['id'])) {
                    update_user_meta($user_id, self::META_SENDPULSE_EMAIL_ID, $data['emails'][0]['id']);
                    $result['ids']['email_id'] = $data['emails'][0]['id'];
                }
                
                $result['status'] = 'success';
                $result['message'] = 'Created new contact';
                
                // Add SMS opted-in tag if requested
                if ($add_sms_tag && !empty($result['ids']['contact_id'])) {
                    $tag_result = $api->add_tag_to_contact($result['ids']['contact_id'], 55139);
                    if ($tag_result) {
                        $result['message'] .= ' + SMS tag';
                    }
                }
            } else {
                $result['status'] = 'error';
                $result['message'] = 'Failed to create contact';
            }
        }
        
        return $result;
    }
    
    /**
     * Process single user - sync with Quo
     */
    private function sync_user_quo($user_id, $user_data) {
        $result = array(
            'action' => 'none',
            'status' => 'skipped',
            'message' => '',
            'ids' => array(),
        );
        
        // Check if API key is configured
        if (empty($this->settings['quo_api_key'])) {
            $result['status'] = 'error';
            $result['message'] = 'Quo API key not configured';
            return $result;
        }
        
        $phone = $user_data['phone'];
        
        // Quo requires phone number
        if (empty($phone)) {
            $result['status'] = 'skipped';
            $result['message'] = 'No phone number available';
            return $result;
        }
        
        // Initialize API
        if (!class_exists('AKS_Quo_API')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-quo-api.php';
        }
        
        $api = new AKS_Quo_API($this->settings['quo_api_key']);
        
        // Search for existing contact
        $existing_contact = $api->search_contact_by_phone($phone);
        
        if ($existing_contact) {
            // Contact found
            $result['action'] = 'found';
            
            update_user_meta($user_id, self::META_QUO_CONTACT_ID, $existing_contact['id']);
            $result['ids']['contact_id'] = $existing_contact['id'];
            
            if (isset($existing_contact['defaultFields']['phoneNumbers'][0]['id'])) {
                update_user_meta($user_id, self::META_QUO_PHONE_ID, $existing_contact['defaultFields']['phoneNumbers'][0]['id']);
                $result['ids']['phone_id'] = $existing_contact['defaultFields']['phoneNumbers'][0]['id'];
            }
            
            $result['status'] = 'success';
            $result['message'] = 'Found existing contact, updated user meta';
        } else {
            // Create new contact
            $result['action'] = 'created';
            
            $contact_data = array(
                'firstName' => $user_data['first_name'],
                'lastName' => $user_data['last_name'],
                'phone' => $phone,
            );
            
            if (!empty($user_data['email'])) {
                $contact_data['email'] = $user_data['email'];
            }
            
            $create_result = $api->create_contact($contact_data);
            
            if ($create_result && isset($create_result['data'])) {
                $data = $create_result['data'];
                
                if (isset($data['id'])) {
                    update_user_meta($user_id, self::META_QUO_CONTACT_ID, $data['id']);
                    $result['ids']['contact_id'] = $data['id'];
                }
                
                if (isset($data['defaultFields']['phoneNumbers'][0]['id'])) {
                    update_user_meta($user_id, self::META_QUO_PHONE_ID, $data['defaultFields']['phoneNumbers'][0]['id']);
                    $result['ids']['phone_id'] = $data['defaultFields']['phoneNumbers'][0]['id'];
                }
                
                $result['status'] = 'success';
                $result['message'] = 'Created new contact';
            } else {
                $result['status'] = 'error';
                $result['message'] = 'Failed to create contact';
            }
        }
        
        return $result;
    }
    
    /**
     * AJAX handler for processing a single user
     */
    public function ajax_process_user() {
        check_ajax_referer('aks_crm_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $sync_sendpulse = isset($_POST['sync_sendpulse']) && $_POST['sync_sendpulse'] === 'true';
        $sync_quo = isset($_POST['sync_quo']) && $_POST['sync_quo'] === 'true';
        $add_sms_tag = isset($_POST['add_sms_tag']) && $_POST['add_sms_tag'] === 'true';
        
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }
        
        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error('User not found');
        }
        
        // Gather user data
        $user_data = array(
            'email' => $user->user_email,
            'phone' => get_user_meta($user_id, 'billing_phone', true),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
        );
        
        $results = array(
            'user_id' => $user_id,
            'email' => $user_data['email'],
            'name' => $user_data['first_name'] . ' ' . $user_data['last_name'],
            'sendpulse' => null,
            'quo' => null,
        );
        
        // Process SendPulse
        if ($sync_sendpulse) {
            $needs = $this->user_needs_sync($user_id);
            if ($needs['sendpulse']) {
                $results['sendpulse'] = $this->sync_user_sendpulse($user_id, $user_data, $add_sms_tag);
            } else {
                $results['sendpulse'] = array(
                    'action' => 'none',
                    'status' => 'skipped',
                    'message' => 'Already synced',
                    'ids' => $this->get_user_crm_status($user_id),
                );
            }
        }
        
        // Process Quo
        if ($sync_quo) {
            $needs = $this->user_needs_sync($user_id);
            if ($needs['quo']) {
                $results['quo'] = $this->sync_user_quo($user_id, $user_data);
            } else {
                $results['quo'] = array(
                    'action' => 'none',
                    'status' => 'skipped',
                    'message' => 'Already synced',
                    'ids' => $this->get_user_crm_status($user_id),
                );
            }
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $total_users = $this->get_total_users();
        
        // Check API configuration
        $sp_configured = !empty($this->settings['api_id']) && !empty($this->settings['api_secret']);
        $quo_configured = !empty($this->settings['quo_api_key']);
        
        ?>
        <div class="wrap">
            <h1>CRM User Sync</h1>
            <p>Sync WordPress users with SendPulse and Quo (OpenPhone) CRM systems.</p>
            
            <?php if (!$sp_configured || !$quo_configured): ?>
            <div class="notice notice-warning">
                <p>
                    <strong>API Configuration:</strong>
                    <?php if (!$sp_configured): ?>SendPulse API credentials not configured. <?php endif; ?>
                    <?php if (!$quo_configured): ?>Quo API key not configured. <?php endif; ?>
                    <a href="<?php echo admin_url('admin.php?page=aks-sendpulse'); ?>">Configure API Settings</a>
                </p>
            </div>
            <?php endif; ?>
            
            <div class="aks-sync-controls" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <h2 style="margin-top: 0;">Sync Configuration</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">User Range</th>
                        <td>
                            <label>
                                Start Offset: <input type="number" id="sync-offset" value="20" min="0" style="width: 80px;" />
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                Batch Size: <input type="number" id="sync-limit" value="10" min="1" max="50" style="width: 80px;" />
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                End Offset: <input type="number" id="sync-end-offset" value="<?php echo $total_users; ?>" min="0" style="width: 80px;" />
                            </label>
                            <p class="description">Total users: <?php echo number_format($total_users); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Batch Settings</th>
                        <td>
                            <label>
                                Delay between users: <input type="number" id="sync-user-delay" value="500" min="100" max="5000" step="100" style="width: 80px;" /> ms
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                Delay between batches: <input type="number" id="sync-batch-delay" value="5" min="1" max="60" style="width: 60px;" /> seconds
                            </label>
                            <p class="description">Recommended: 500ms between users, 5 seconds between batches to avoid API rate limits</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">CRM Systems</th>
                        <td>
                            <label>
                                <input type="checkbox" id="sync-sendpulse" checked <?php echo !$sp_configured ? 'disabled' : ''; ?> />
                                SendPulse
                            </label>
                            &nbsp;&nbsp;&nbsp;
                            <label>
                                <input type="checkbox" id="sync-quo" checked <?php echo !$quo_configured ? 'disabled' : ''; ?> />
                                Quo (OpenPhone)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">SendPulse Options</th>
                        <td>
                            <label>
                                <input type="checkbox" id="add-sms-tag" <?php echo !$sp_configured ? 'disabled' : ''; ?> />
                                Add "SMS opted-in" tag (ID: 55139)
                            </label>
                            <p class="description">This will add the SMS opted-in tag to all processed contacts in SendPulse</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Options</th>
                        <td>
                            <label>
                                <input type="checkbox" id="sync-skip-existing" checked />
                                Skip users already synced
                            </label>
                            <br />
                            <label>
                                <input type="checkbox" id="sync-only-with-phone" />
                                Only process users with phone numbers
                            </label>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button type="button" id="btn-start-sync" class="button button-primary button-large">
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
                        <div id="stat-created" style="font-size: 24px; font-weight: 600; color: #155724;">0</div>
                        <div style="color: #155724; font-size: 12px;">Created</div>
                    </div>
                    <div style="text-align: center; padding: 10px; background: #cce5ff; border-radius: 4px;">
                        <div id="stat-found" style="font-size: 24px; font-weight: 600; color: #004085;">0</div>
                        <div style="color: #004085; font-size: 12px;">Found & Linked</div>
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
                                <th style="width: 180px;">Email</th>
                                <th style="width: 150px;">Name</th>
                                <th>SendPulse</th>
                                <th>Quo</th>
                            </tr>
                        </thead>
                        <tbody id="results-body">
                            <tr id="results-empty">
                                <td colspan="5" style="text-align: center; color: #646970; padding: 30px;">
                                    Click "Start Sync" to begin processing users
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
            .status-skipped { color: #856404; }
            .status-created { color: #155724; font-weight: 600; }
            .status-found { color: #004085; }
            .result-cell { font-size: 12px; }
            .result-ids { font-family: monospace; font-size: 10px; color: #666; margin-top: 3px; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var isRunning = false;
            var shouldStop = false;
            var isAutoMode = false;
            var users = [];
            var currentIndex = 0;
            var stats = { processed: 0, created: 0, found: 0, errors: 0, skipped: 0 };
            var currentOffset = 0;
            var batchSize = 10;
            var endOffset = 0;
            var batchCount = 0;
            var totalBatches = 0;
            var countdownInterval = null;
            
            // Process single user
            function processUser(user, callback) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aks_process_user_sync',
                        nonce: '<?php echo wp_create_nonce('aks_crm_sync_nonce'); ?>',
                        user_id: user.id,
                        sync_sendpulse: $('#sync-sendpulse').is(':checked'),
                        sync_quo: $('#sync-quo').is(':checked'),
                        add_sms_tag: $('#add-sms-tag').is(':checked')
                    },
                    success: function(response) {
                        callback(response.success ? response.data : null, response.success ? null : response.data);
                    },
                    error: function() {
                        callback(null, 'Request failed');
                    }
                });
            }
            
            // Update UI with result
            function addResultRow(result) {
                $('#results-empty').hide();
                
                var spStatus = '-';
                var quoStatus = '-';
                
                if (result.sendpulse) {
                    var sp = result.sendpulse;
                    var spClass = 'status-' + sp.status;
                    if (sp.action === 'created') spClass = 'status-created';
                    if (sp.action === 'found') spClass = 'status-found';
                    
                    spStatus = '<span class="' + spClass + '">' + sp.message + '</span>';
                    if (sp.ids && Object.keys(sp.ids).length > 0) {
                        spStatus += '<div class="result-ids">ID: ' + (sp.ids.contact_id || '-') + '</div>';
                    }
                    
                    // Update stats
                    if (sp.action === 'created') stats.created++;
                    else if (sp.action === 'found') stats.found++;
                    else if (sp.status === 'error') stats.errors++;
                }
                
                if (result.quo) {
                    var quo = result.quo;
                    var quoClass = 'status-' + quo.status;
                    if (quo.action === 'created') quoClass = 'status-created';
                    if (quo.action === 'found') quoClass = 'status-found';
                    
                    quoStatus = '<span class="' + quoClass + '">' + quo.message + '</span>';
                    if (quo.ids && quo.ids.contact_id) {
                        quoStatus += '<div class="result-ids">ID: ' + quo.ids.contact_id + '</div>';
                    }
                }
                
                var row = '<tr>' +
                    '<td>' + result.user_id + '</td>' +
                    '<td style="font-size: 12px;">' + result.email + '</td>' +
                    '<td>' + result.name + '</td>' +
                    '<td class="result-cell">' + spStatus + '</td>' +
                    '<td class="result-cell">' + quoStatus + '</td>' +
                '</tr>';
                
                $('#results-body').prepend(row);
                
                stats.processed++;
                updateStats();
            }
            
            // Update stats display
            function updateStats() {
                $('#stat-processed').text(stats.processed);
                $('#stat-created').text(stats.created);
                $('#stat-found').text(stats.found);
                $('#stat-errors').text(stats.errors);
                
                var progress = users.length > 0 ? (currentIndex / users.length) * 100 : 0;
                $('#progress-bar').css('width', progress + '%');
                $('#progress-text').text(currentIndex + ' / ' + users.length + ' users in current batch');
                
                // Update batch progress
                $('#batch-current').text(batchCount);
                $('#current-offset').text(currentOffset);
            }
            
            // Process next user in queue
            function processNext() {
                if (shouldStop) {
                    finishSync();
                    return;
                }
                
                if (currentIndex >= users.length) {
                    // Batch complete
                    if (isAutoMode) {
                        startNextBatch();
                    } else {
                        finishSync();
                    }
                    return;
                }
                
                var user = users[currentIndex];
                $('#sync-status').text('Processing user ' + (currentIndex + 1) + ' of ' + users.length + ' (ID: ' + user.id + ')...');
                
                processUser(user, function(result, error) {
                    if (error) {
                        addResultRow({
                            user_id: user.id,
                            email: user.email,
                            name: user.name,
                            sendpulse: { status: 'error', message: error, action: 'none', ids: {} },
                            quo: { status: 'error', message: error, action: 'none', ids: {} }
                        });
                    } else {
                        addResultRow(result);
                    }
                    
                    currentIndex++;
                    
                    // Delay between users
                    var userDelay = parseInt($('#sync-user-delay').val()) || 500;
                    setTimeout(processNext, userDelay);
                });
            }
            
            // Start next batch in auto mode
            function startNextBatch() {
                currentOffset += batchSize;
                
                if (currentOffset >= endOffset) {
                    $('#sync-status').text('All batches complete!');
                    finishSync();
                    return;
                }
                
                batchCount++;
                var batchDelay = (parseInt($('#sync-batch-delay').val()) || 5) * 1000;
                
                // Countdown display
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
            
            // Fetch users and start processing
            function fetchAndProcessBatch(offset, limit) {
                $('#sync-status').text('Fetching users (offset: ' + offset + ')...');
                $('#current-offset').text(offset);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aks_get_users_for_sync',
                        nonce: '<?php echo wp_create_nonce('aks_crm_sync_nonce'); ?>',
                        offset: offset,
                        limit: limit,
                        only_with_phone: $('#sync-only-with-phone').is(':checked') ? 1 : 0,
                        skip_existing: $('#sync-skip-existing').is(':checked') ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            users = response.data;
                            currentIndex = 0;
                            
                            if (users.length === 0) {
                                if (isAutoMode) {
                                    // Skip to next batch
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
            
            // Finish sync
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
                    $('#sync-status').text('Stopped by user. Processed ' + stats.processed + ' users total.');
                } else {
                    $('#sync-status').text('Complete! Processed ' + stats.processed + ' users total.');
                }
            }
            
            // Single batch sync
            $('#btn-start-sync').on('click', function() {
                var offset = parseInt($('#sync-offset').val()) || 0;
                var limit = parseInt($('#sync-limit').val()) || 10;
                
                if (!$('#sync-sendpulse').is(':checked') && !$('#sync-quo').is(':checked')) {
                    alert('Please select at least one CRM system to sync');
                    return;
                }
                
                // Reset
                isRunning = true;
                isAutoMode = false;
                shouldStop = false;
                currentIndex = 0;
                currentOffset = offset;
                batchSize = limit;
                batchCount = 1;
                stats = { processed: 0, created: 0, found: 0, errors: 0, skipped: 0 };
                
                $('#btn-start-sync, #btn-auto-process').hide();
                $('#btn-stop-sync').show();
                $('#sync-status').text('Fetching users...');
                $('.aks-sync-progress').show();
                $('#batch-progress').hide();
                $('#results-body').html('<tr id="results-empty"><td colspan="5" style="text-align: center;">Loading...</td></tr>');
                
                fetchAndProcessBatch(offset, limit);
            });
            
            // Auto process all batches
            $('#btn-auto-process').on('click', function() {
                var startOffset = parseInt($('#sync-offset').val()) || 0;
                var limit = parseInt($('#sync-limit').val()) || 10;
                endOffset = parseInt($('#sync-end-offset').val()) || <?php echo $total_users; ?>;
                
                if (!$('#sync-sendpulse').is(':checked') && !$('#sync-quo').is(':checked')) {
                    alert('Please select at least one CRM system to sync');
                    return;
                }
                
                totalBatches = Math.ceil((endOffset - startOffset) / limit);
                
                if (!confirm('This will process approximately ' + (endOffset - startOffset) + ' users in ' + totalBatches + ' batches.\n\nStart offset: ' + startOffset + '\nEnd offset: ' + endOffset + '\nBatch size: ' + limit + '\n\nContinue?')) {
                    return;
                }
                
                // Reset
                isRunning = true;
                isAutoMode = true;
                shouldStop = false;
                currentIndex = 0;
                currentOffset = startOffset;
                batchSize = limit;
                batchCount = 1;
                stats = { processed: 0, created: 0, found: 0, errors: 0, skipped: 0 };
                
                $('#btn-start-sync, #btn-auto-process').hide();
                $('#btn-stop-sync').show();
                $('#sync-status').text('Starting auto-process...');
                $('.aks-sync-progress').show();
                $('#batch-progress').show();
                $('#batch-total').text(totalBatches);
                $('#results-body').html('<tr id="results-empty"><td colspan="5" style="text-align: center;">Loading...</td></tr>');
                
                fetchAndProcessBatch(startOffset, limit);
            });
            
            // Stop sync
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
}

// AJAX handler to get users list
add_action('wp_ajax_aks_get_users_for_sync', function() {
    check_ajax_referer('aks_crm_sync_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }
    
    $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
    $limit = isset($_POST['limit']) ? min(100, absint($_POST['limit'])) : 10;
    $only_with_phone = isset($_POST['only_with_phone']) && $_POST['only_with_phone'] === '1';
    $skip_existing = isset($_POST['skip_existing']) && $_POST['skip_existing'] === '1';
    
    $args = array(
        'number' => $limit,
        'offset' => $offset,
        'orderby' => 'ID',
        'order' => 'ASC',
    );
    
    // If only processing users with phone, add meta query
    if ($only_with_phone) {
        $args['meta_query'] = array(
            array(
                'key' => 'billing_phone',
                'value' => '',
                'compare' => '!='
            )
        );
    }
    
    $users = get_users($args);
    $user_list = array();
    
    foreach ($users as $user) {
        // Skip if already synced and option is checked
        if ($skip_existing) {
            $sp_id = get_user_meta($user->ID, 'sendpulse_contact_id', true);
            $quo_id = get_user_meta($user->ID, 'quo_contact_id', true);
            
            if (!empty($sp_id) && !empty($quo_id)) {
                continue;
            }
        }
        
        $user_list[] = array(
            'id' => $user->ID,
            'email' => $user->user_email,
            'name' => $user->first_name . ' ' . $user->last_name,
            'phone' => get_user_meta($user->ID, 'billing_phone', true),
        );
    }
    
    wp_send_json_success($user_list);
});