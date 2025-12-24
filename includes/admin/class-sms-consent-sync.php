<?php
/**
 * SMS Consent Sync Tool
 * Syncs SMS opt-in tags based on Gravity Forms consent field
 * 
 * Reads field 23 from Form 3 entries and adds/removes SendPulse tag accordingly
 */

if (!defined('ABSPATH')) {
    exit;
}

class AKS_SMS_Consent_Sync {
    
    private static $instance = null;
    private $settings;
    
    // Configuration
    const FORM_ID = 3;           // Gravity Forms form ID
    const FIELD_ID = 23;         // SMS consent field ID
    const TAG_ID = 55139;        // SendPulse SMS Opt-in tag ID
    
    public function __construct() {
        $this->settings = get_option('aks_sendpulse_settings');
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Handle AJAX requests
        add_action('wp_ajax_aks_process_sms_consent', array($this, 'ajax_process_user'));
        add_action('wp_ajax_aks_get_users_for_sms_sync', array($this, 'ajax_get_users'));
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
            'SMS Consent Sync',
            'SMS Consent Sync',
            'manage_options',
            'aks-sms-consent-sync',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Get SendPulse access token
     */
    private function get_access_token() {
        $transient_key = 'aks_sendpulse_access_token';
        $token = get_transient($transient_key);
        
        if ($token) {
            return $token;
        }
        
        $response = wp_remote_post('https://api.sendpulse.com/oauth/access_token', array(
            'body' => array(
                'grant_type' => 'client_credentials',
                'client_id' => $this->settings['api_id'],
                'client_secret' => $this->settings['api_secret'],
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['access_token'])) {
            set_transient($transient_key, $body['access_token'], 3500);
            return $body['access_token'];
        }
        
        return false;
    }
    
    /**
     * Add tag to contact
     */
    private function add_tag_to_contact($contact_id) {
        $token = $this->get_access_token();
        if (!$token) {
            return array('success' => false, 'message' => 'Failed to get access token');
        }
        
        $url = 'https://api.sendpulse.com/crm/v1/contact-tags/' . self::TAG_ID . '/contact/' . $contact_id;
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code >= 200 && $code < 300) {
            return array('success' => true, 'message' => 'Tag added');
        }
        
        // Handle specific error codes
        if ($code == 404) {
            return array('success' => false, 'message' => 'Contact not found in SendPulse (ID may be invalid)');
        }
        
        if ($code == 429 || $code == 500) {
            return array('success' => false, 'message' => 'Rate limited (HTTP ' . $code . ') - increase delay');
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return array('success' => false, 'message' => 'HTTP ' . $code . ': ' . ($body['message'] ?? 'Unknown error'));
    }
    
    /**
     * Remove tag from contact
     */
    private function remove_tag_from_contact($contact_id) {
        $token = $this->get_access_token();
        if (!$token) {
            return array('success' => false, 'message' => 'Failed to get access token');
        }
        
        $url = 'https://api.sendpulse.com/crm/v1/contact-tags/' . self::TAG_ID . '/contact/' . $contact_id;
        
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        // 204 No Content is success for DELETE, also accept 200 and 404 (already removed)
        if ($code == 204 || $code == 200 || $code == 404) {
            return array('success' => true, 'message' => 'Tag removed');
        }
        
        // Handle rate limiting
        if ($code == 429 || $code == 500) {
            return array('success' => false, 'message' => 'Rate limited (HTTP ' . $code . ') - increase delay');
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return array('success' => false, 'message' => 'HTTP ' . $code . ': ' . ($body['message'] ?? 'Unknown error'));
    }
    
    /**
     * Get user's SMS consent from Gravity Forms entry
     */
    private function get_user_sms_consent($user_id) {
        if (!class_exists('GFAPI')) {
            return null;
        }
        
        // First check if we have a stored entry ID
        $entry_id = get_user_meta($user_id, 'aks_form_2_entry_id', true);
        
        if ($entry_id) {
            $entry = GFAPI::get_entry($entry_id);
            if (!is_wp_error($entry) && isset($entry[self::FIELD_ID])) {
                return $entry[self::FIELD_ID];
            }
        }
        
        // Fallback: search for entry by created_by
        $search_criteria = array(
            'status' => 'active',
            'field_filters' => array(
                array(
                    'key' => 'created_by',
                    'value' => $user_id,
                ),
            ),
        );
        
        $entries = GFAPI::get_entries(self::FORM_ID, $search_criteria, null, array('offset' => 0, 'page_size' => 1));
        
        if (!empty($entries) && isset($entries[0][self::FIELD_ID])) {
            return $entries[0][self::FIELD_ID];
        }
        
        return null;
    }
    
    /**
     * AJAX: Get users for processing
     */
    public function ajax_get_users() {
        check_ajax_referer('aks_sms_consent_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $limit = isset($_POST['limit']) ? min(100, absint($_POST['limit'])) : 10;
        
        // Get users who have SendPulse contact IDs
        $args = array(
            'number' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => array(
                array(
                    'key' => 'sendpulse_contact_id',
                    'value' => '',
                    'compare' => '!=',
                ),
            ),
        );
        
        $users = get_users($args);
        $user_list = array();
        
        foreach ($users as $user) {
            $user_list[] = array(
                'id' => $user->ID,
                'email' => $user->user_email,
                'name' => trim($user->first_name . ' ' . $user->last_name),
                'sendpulse_contact_id' => get_user_meta($user->ID, 'sendpulse_contact_id', true),
            );
        }
        
        wp_send_json_success($user_list);
    }
    
    /**
     * AJAX: Process single user
     */
    public function ajax_process_user() {
        check_ajax_referer('aks_sms_consent_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }
        
        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error('User not found');
        }
        
        $sendpulse_contact_id = get_user_meta($user_id, 'sendpulse_contact_id', true);
        
        if (empty($sendpulse_contact_id)) {
            wp_send_json_success(array(
                'user_id' => $user_id,
                'email' => $user->user_email,
                'name' => trim($user->first_name . ' ' . $user->last_name),
                'consent' => null,
                'action' => 'skipped',
                'status' => 'skipped',
                'message' => 'No SendPulse contact ID',
            ));
            return;
        }
        
        // Get consent value from Gravity Forms
        $consent = $this->get_user_sms_consent($user_id);
        
        $result = array(
            'user_id' => $user_id,
            'email' => $user->user_email,
            'name' => trim($user->first_name . ' ' . $user->last_name),
            'consent' => $consent,
            'sendpulse_id' => $sendpulse_contact_id,
        );
        
        if ($consent === 'Yes') {
            // Explicitly opted in - ensure tag exists
            $tag_result = $this->add_tag_to_contact($sendpulse_contact_id);
            $result['action'] = 'add_tag';
            $result['status'] = $tag_result['success'] ? 'success' : 'error';
            $result['message'] = $tag_result['message'];
        } else {
            // Anything else (No, phone number, empty, null) - remove tag
            $tag_result = $this->remove_tag_from_contact($sendpulse_contact_id);
            $result['action'] = 'remove_tag';
            $result['status'] = $tag_result['success'] ? 'success' : 'error';
            $result['message'] = $tag_result['message'] . ' (was: "' . ($consent ?? 'null') . '")';
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * Get count of users with SendPulse IDs
     */
    private function get_syncable_user_count() {
        global $wpdb;
        
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} 
             WHERE meta_key = 'sendpulse_contact_id' AND meta_value != ''"
        );
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $total_users = $this->get_syncable_user_count();
        $sp_configured = !empty($this->settings['api_id']) && !empty($this->settings['api_secret']);
        $gf_active = class_exists('GFAPI');
        
        ?>
        <div class="wrap">
            <h1>SMS Consent Sync</h1>
            <p>Sync SMS opt-in tags based on Gravity Forms consent field (Form <?php echo self::FORM_ID; ?>, Field <?php echo self::FIELD_ID; ?>).</p>
            
            <?php if (!$sp_configured): ?>
            <div class="notice notice-error">
                <p><strong>Error:</strong> SendPulse API credentials not configured. <a href="<?php echo admin_url('admin.php?page=aks-sendpulse'); ?>">Configure API Settings</a></p>
            </div>
            <?php endif; ?>
            
            <?php if (!$gf_active): ?>
            <div class="notice notice-error">
                <p><strong>Error:</strong> Gravity Forms is not active.</p>
            </div>
            <?php endif; ?>
            
            <div class="notice notice-info">
                <p>
                    <strong>How it works:</strong><br>
                    • Checks each user's Gravity Forms entry (Form <?php echo self::FORM_ID; ?>, Field <?php echo self::FIELD_ID; ?>)<br>
                    • If consent = "Yes" → Adds SMS Opt-in tag (ID: <?php echo self::TAG_ID; ?>)<br>
                    • If consent = "No" → Removes SMS Opt-in tag<br>
                    • Only processes users who have a SendPulse Contact ID
                </p>
            </div>
            
            <div class="aks-sync-controls" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <h2 style="margin-top: 0;">Sync Configuration</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">User Range</th>
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
                                End Offset: <input type="number" id="sync-end-offset" value="<?php echo $total_users; ?>" min="0" style="width: 80px;" />
                            </label>
                            <p class="description">Users with SendPulse IDs: <?php echo number_format($total_users); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Timing</th>
                        <td>
                            <label>
                                Delay between users: <input type="number" id="sync-user-delay" value="300" min="100" max="5000" step="100" style="width: 80px;" /> ms
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                Delay between batches: <input type="number" id="sync-batch-delay" value="3" min="1" max="60" style="width: 60px;" /> seconds
                            </label>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button type="button" id="btn-start-sync" class="button button-large" <?php echo (!$sp_configured || !$gf_active) ? 'disabled' : ''; ?>>
                        Process Single Batch
                    </button>
                    <button type="button" id="btn-auto-process" class="button button-primary button-large" style="background: #00a32a; border-color: #00a32a;" <?php echo (!$sp_configured || !$gf_active) ? 'disabled' : ''; ?>>
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
                
                <div id="sync-summary" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; margin-top: 15px;">
                    <div style="text-align: center; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                        <div id="stat-processed" style="font-size: 24px; font-weight: 600;">0</div>
                        <div style="color: #646970; font-size: 12px;">Processed</div>
                    </div>
                    <div style="text-align: center; padding: 10px; background: #d4edda; border-radius: 4px;">
                        <div id="stat-added" style="font-size: 24px; font-weight: 600; color: #155724;">0</div>
                        <div style="color: #155724; font-size: 12px;">Tags Added</div>
                    </div>
                    <div style="text-align: center; padding: 10px; background: #fff3cd; border-radius: 4px;">
                        <div id="stat-removed" style="font-size: 24px; font-weight: 600; color: #856404;">0</div>
                        <div style="color: #856404; font-size: 12px;">Tags Removed</div>
                    </div>
                    <div style="text-align: center; padding: 10px; background: #e2e3e5; border-radius: 4px;">
                        <div id="stat-skipped" style="font-size: 24px; font-weight: 600; color: #383d41;">0</div>
                        <div style="color: #383d41; font-size: 12px;">Skipped</div>
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
                                <th style="width: 120px;">Name</th>
                                <th style="width: 80px;">Consent</th>
                                <th style="width: 100px;">Action</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody id="results-body">
                            <tr id="results-empty">
                                <td colspan="6" style="text-align: center; color: #646970; padding: 30px;">
                                    Click "Auto Process All" to begin syncing SMS consent
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
            .consent-yes { color: #155724; font-weight: 600; }
            .consent-no { color: #dc3232; font-weight: 600; }
            .consent-null { color: #666; font-style: italic; }
            .action-add { color: #155724; }
            .action-remove { color: #856404; }
            .action-skip { color: #666; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var isRunning = false;
            var shouldStop = false;
            var isAutoMode = false;
            var users = [];
            var currentIndex = 0;
            var stats = { processed: 0, added: 0, removed: 0, skipped: 0, errors: 0 };
            var currentOffset = 0;
            var batchSize = 10;
            var endOffset = 0;
            var batchCount = 0;
            var totalBatches = 0;
            var countdownInterval = null;
            
            function processUser(user, callback) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aks_process_sms_consent',
                        nonce: '<?php echo wp_create_nonce('aks_sms_consent_nonce'); ?>',
                        user_id: user.id
                    },
                    success: function(response) {
                        callback(response.success ? response.data : null, response.success ? null : response.data);
                    },
                    error: function() {
                        callback(null, 'Request failed');
                    }
                });
            }
            
            function addResultRow(result) {
                $('#results-empty').hide();
                
                var consentClass = 'consent-null';
                var consentText = '-';
                if (result.consent === 'Yes') {
                    consentClass = 'consent-yes';
                    consentText = '✓ Yes';
                } else if (result.consent === 'No') {
                    consentClass = 'consent-no';
                    consentText = '✗ No';
                }
                
                var actionClass = 'action-skip';
                var actionText = '-';
                if (result.action === 'add_tag') {
                    actionClass = 'action-add';
                    actionText = '+ Add Tag';
                    if (result.status === 'success') stats.added++;
                } else if (result.action === 'remove_tag') {
                    actionClass = 'action-remove';
                    actionText = '− Remove Tag';
                    if (result.status === 'success') stats.removed++;
                } else if (result.action === 'skipped') {
                    stats.skipped++;
                }
                
                if (result.status === 'error') {
                    stats.errors++;
                }
                
                var statusClass = 'status-' + result.status;
                
                var row = '<tr>' +
                    '<td>' + result.user_id + '</td>' +
                    '<td style="font-size: 12px;">' + result.email + '</td>' +
                    '<td>' + result.name + '</td>' +
                    '<td class="' + consentClass + '">' + consentText + '</td>' +
                    '<td class="' + actionClass + '">' + actionText + '</td>' +
                    '<td class="' + statusClass + '">' + result.message + '</td>' +
                '</tr>';
                
                $('#results-body').prepend(row);
                
                stats.processed++;
                updateStats();
            }
            
            function updateStats() {
                $('#stat-processed').text(stats.processed);
                $('#stat-added').text(stats.added);
                $('#stat-removed').text(stats.removed);
                $('#stat-skipped').text(stats.skipped);
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
                
                processUser(user, function(result, error) {
                    if (error) {
                        addResultRow({
                            user_id: user.id,
                            email: user.email,
                            name: user.name,
                            consent: null,
                            action: 'error',
                            status: 'error',
                            message: error
                        });
                    } else {
                        addResultRow(result);
                    }
                    
                    currentIndex++;
                    
                    var userDelay = parseInt($('#sync-user-delay').val()) || 300;
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
                var batchDelay = (parseInt($('#sync-batch-delay').val()) || 3) * 1000;
                
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
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aks_get_users_for_sms_sync',
                        nonce: '<?php echo wp_create_nonce('aks_sms_consent_nonce'); ?>',
                        offset: offset,
                        limit: limit
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
                    $('#sync-status').text('Stopped. Processed ' + stats.processed + ' users. Added: ' + stats.added + ', Removed: ' + stats.removed);
                } else {
                    $('#sync-status').text('Complete! Processed ' + stats.processed + ' users. Added: ' + stats.added + ', Removed: ' + stats.removed);
                }
            }
            
            $('#btn-start-sync').on('click', function() {
                var offset = parseInt($('#sync-offset').val()) || 0;
                var limit = parseInt($('#sync-limit').val()) || 10;
                
                isRunning = true;
                isAutoMode = false;
                shouldStop = false;
                currentIndex = 0;
                currentOffset = offset;
                batchSize = limit;
                batchCount = 1;
                stats = { processed: 0, added: 0, removed: 0, skipped: 0, errors: 0 };
                
                $('#btn-start-sync, #btn-auto-process').hide();
                $('#btn-stop-sync').show();
                $('#sync-status').text('Fetching users...');
                $('.aks-sync-progress').show();
                $('#batch-progress').hide();
                $('#results-body').html('<tr id="results-empty"><td colspan="6" style="text-align: center;">Loading...</td></tr>');
                
                fetchAndProcessBatch(offset, limit);
            });
            
            $('#btn-auto-process').on('click', function() {
                var startOffset = parseInt($('#sync-offset').val()) || 0;
                var limit = parseInt($('#sync-limit').val()) || 10;
                endOffset = parseInt($('#sync-end-offset').val()) || <?php echo $total_users; ?>;
                
                totalBatches = Math.ceil((endOffset - startOffset) / limit);
                
                if (!confirm('This will process approximately ' + (endOffset - startOffset) + ' users in ' + totalBatches + ' batches.\n\nFor each user:\n• Consent "Yes" → Add SMS tag\n• Consent "No" → Remove SMS tag\n\nContinue?')) {
                    return;
                }
                
                isRunning = true;
                isAutoMode = true;
                shouldStop = false;
                currentIndex = 0;
                currentOffset = startOffset;
                batchSize = limit;
                batchCount = 1;
                stats = { processed: 0, added: 0, removed: 0, skipped: 0, errors: 0 };
                
                $('#btn-start-sync, #btn-auto-process').hide();
                $('#btn-stop-sync').show();
                $('#sync-status').text('Starting auto-process...');
                $('.aks-sync-progress').show();
                $('#batch-progress').show();
                $('#batch-total').text(totalBatches);
                $('#results-body').html('<tr id="results-empty"><td colspan="6" style="text-align: center;">Loading...</td></tr>');
                
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
}