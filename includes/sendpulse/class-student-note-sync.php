<?php
/**
 * Student Note Sync Handler
 * Syncs student information from Gravity Forms to SendPulse contact notes
 * 
 * Handles:
 * - Form 3 submission (creates note)
 * - GravityView edit (updates note)
 * - Admin bulk sync for existing entries
 */

if (!defined('ABSPATH')) {
    exit;
}

class AKS_Student_Note_Sync {
    
    private static $instance = null;
    private $settings;
    
    // Configuration
    const FORM_ID = 3;           // Registration Form 2 (Gravity Forms ID)
    const STUDENT_FIELD_ID = 21; // Nested form field containing student(s)
    const STUDENT_FORM_ID = 1;   // Child form ID for students
    
    public function __construct() {
        $this->settings = get_option('aks_sendpulse_settings');
        
        // Hook into Form 3 submission
        add_action('gform_after_submission_' . self::FORM_ID, array($this, 'handle_form_submission'), 20, 2);
        
        // Hook into GravityView entry update (student form edits)
        add_action('gravityview/edit_entry/after_update', array($this, 'handle_gravityview_update'), 10, 3);
        
        // Alternative hook for nested form updates
        add_action('gform_after_update_entry', array($this, 'handle_entry_update'), 10, 2);
        
        // Admin menu for sync tool
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // AJAX handlers
        add_action('wp_ajax_aks_sync_student_note', array($this, 'ajax_sync_single_user'));
        add_action('wp_ajax_aks_get_users_for_note_sync', array($this, 'ajax_get_users'));
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
     * Handle Form 3 submission - create or update student note
     */
    public function handle_form_submission($entry, $form) {
        // Get user ID from entry
        $user_id = rgar($entry, 'created_by');
        
        if (empty($user_id)) {
            error_log('Student Note Sync: No user ID found in Form 3 entry');
            return;
        }
        
        $this->sync_student_note_for_user($user_id, $entry);
    }
    
    /**
     * Handle GravityView entry update
     */
    public function handle_gravityview_update($entry, $form_id, $gv_entry) {
        // Check if this is a student form entry (Form 1)
        if ($form_id != self::STUDENT_FORM_ID) {
            return;
        }
        
        // Find the parent entry to get the user
        $user_id = $this->find_user_from_student_entry($entry['id']);
        
        if ($user_id) {
            error_log('Student Note Sync: GravityView update triggered for user ' . $user_id);
            $this->sync_student_note_for_user($user_id);
        }
    }
    
    /**
     * Handle general entry update
     */
    public function handle_entry_update($form, $entry_id) {
        $entry = GFAPI::get_entry($entry_id);
        
        if (is_wp_error($entry)) {
            return;
        }
        
        $form_id = $entry['form_id'];
        
        // If student form was updated
        if ($form_id == self::STUDENT_FORM_ID) {
            $user_id = $this->find_user_from_student_entry($entry_id);
            
            if ($user_id) {
                error_log('Student Note Sync: Entry update triggered for user ' . $user_id);
                $this->sync_student_note_for_user($user_id);
            }
        }
        
        // If Form 3 was updated directly
        if ($form_id == self::FORM_ID) {
            $user_id = rgar($entry, 'created_by');
            if ($user_id) {
                $this->sync_student_note_for_user($user_id, $entry);
            }
        }
    }
    
    /**
     * Find user from a student entry by looking up the parent Form 3 entry
     */
    private function find_user_from_student_entry($student_entry_id) {
        // Search Form 3 entries that contain this student entry ID in field 21
        $search_criteria = array(
            'status' => 'active',
        );
        
        $entries = GFAPI::get_entries(self::FORM_ID, $search_criteria);
        
        foreach ($entries as $entry) {
            $student_ids = rgar($entry, self::STUDENT_FIELD_ID);
            
            if (!empty($student_ids)) {
                $ids_array = array_map('trim', explode(',', $student_ids));
                
                if (in_array($student_entry_id, $ids_array)) {
                    return rgar($entry, 'created_by');
                }
            }
        }
        
        return null;
    }
    
    /**
     * Sync student note for a specific user
     */
    public function sync_student_note_for_user($user_id, $entry = null) {
        // Get SendPulse contact ID
        $contact_id = get_user_meta($user_id, 'sendpulse_contact_id', true);
        
        if (empty($contact_id)) {
            error_log('Student Note Sync: No SendPulse contact ID for user ' . $user_id);
            return array('success' => false, 'message' => 'No SendPulse contact ID');
        }
        
        // Get existing comment ID if any
        $comment_id = get_user_meta($user_id, 'sendpulse_comment_id', true);
        
        // If no entry provided, find it
        if (empty($entry)) {
            $entry = $this->get_form3_entry_for_user($user_id);
        }
        
        if (empty($entry)) {
            error_log('Student Note Sync: No Form 3 entry found for user ' . $user_id);
            return array('success' => false, 'message' => 'No Form 3 entry found');
        }
        
        // Build the note message from student data
        $note_message = $this->build_student_note_message($entry);
        
        if (empty($note_message)) {
            error_log('Student Note Sync: No student data to sync for user ' . $user_id);
            return array('success' => false, 'message' => 'No student data');
        }
        
        // Create or update the note
        if (!empty($comment_id)) {
            // Update existing note
            $result = $this->update_contact_note($contact_id, $comment_id, $note_message);
            $action = 'updated';
        } else {
            // Create new note
            $result = $this->add_contact_note($contact_id, $note_message);
            $action = 'created';
            
            // Store the comment ID if successful
            if ($result && isset($result['data']['id'])) {
                update_user_meta($user_id, 'sendpulse_comment_id', $result['data']['id']);
                error_log('Student Note Sync: Stored comment ID ' . $result['data']['id'] . ' for user ' . $user_id);
            }
        }
        
        if ($result) {
            error_log('Student Note Sync: Successfully ' . $action . ' note for user ' . $user_id);
            return array('success' => true, 'message' => 'Note ' . $action, 'action' => $action);
        } else {
            error_log('Student Note Sync: Failed to ' . $action . ' note for user ' . $user_id);
            return array('success' => false, 'message' => 'API call failed');
        }
    }
    
    /**
     * Get Form 3 entry for a user
     */
    private function get_form3_entry_for_user($user_id) {
        $search_criteria = array(
            'status' => 'active',
            'field_filters' => array(
                array(
                    'key' => 'created_by',
                    'value' => $user_id
                )
            )
        );
        
        $entries = GFAPI::get_entries(self::FORM_ID, $search_criteria, null, array('offset' => 0, 'page_size' => 1));
        
        return !empty($entries) ? $entries[0] : null;
    }
    
    /**
     * Build the note message from student data
     */
    private function build_student_note_message($entry) {
        $child_entry_ids_string = rgar($entry, self::STUDENT_FIELD_ID);
        
        if (empty($child_entry_ids_string)) {
            return '';
        }
        
        $child_entry_ids = array_map('trim', explode(',', $child_entry_ids_string));
        $student_lines = array();
        
        foreach ($child_entry_ids as $child_entry_id) {
            if (empty($child_entry_id)) {
                continue;
            }
            
            $child_entry = GFAPI::get_entry($child_entry_id);
            
            if (is_wp_error($child_entry) || !$child_entry) {
                continue;
            }
            
            // Name field is split: 1.3 = first name, 1.6 = last name
            $student_first_name = rgar($child_entry, '1.3');
            $student_last_name = rgar($child_entry, '1.6');
            $student_birthdate = rgar($child_entry, '3');
            
            $student_full_name = trim($student_first_name . ' ' . $student_last_name);
            
            // Format birthdate from YYYY-MM-DD to MM/DD/YYYY
            if ($student_birthdate) {
                $date = DateTime::createFromFormat('Y-m-d', $student_birthdate);
                if ($date) {
                    $student_birthdate = $date->format('m/d/Y');
                }
            }
            
            if ($student_full_name) {
                $student_lines[] = $student_full_name . ' ' . $student_birthdate;
            }
        }
        
        if (empty($student_lines)) {
            return '';
        }
        
        return "Student Names and Birthdays:<br>" . implode("<br>", $student_lines);
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
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'grant_type' => 'client_credentials',
                'client_id' => $this->settings['api_id'],
                'client_secret' => $this->settings['api_secret']
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Student Note Sync: Token error - ' . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['access_token'])) {
            set_transient($transient_key, $body['access_token'], 50 * MINUTE_IN_SECONDS);
            return $body['access_token'];
        }
        
        return false;
    }
    
    /**
     * Add note to SendPulse contact (POST)
     */
    private function add_contact_note($contact_id, $message) {
        $token = $this->get_access_token();
        
        if (!$token) {
            return false;
        }
        
        $url = 'https://api.sendpulse.com/crm/v1/contacts/' . $contact_id . '/comments';
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
            'body' => json_encode(array(
                'message' => $message
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Student Note Sync: Add note error - ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code === 200 || $code === 201) {
            return $body;
        }
        
        error_log('Student Note Sync: Add note failed - HTTP ' . $code . ' - ' . wp_remote_retrieve_body($response));
        return false;
    }
    
    /**
     * Update note on SendPulse contact (PUT)
     */
    private function update_contact_note($contact_id, $comment_id, $message) {
        $token = $this->get_access_token();
        
        if (!$token) {
            return false;
        }
        
        $url = 'https://api.sendpulse.com/crm/v1/contacts/' . $contact_id . '/comments/' . $comment_id;
        
        $response = wp_remote_request($url, array(
            'method' => 'PUT',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
            'body' => json_encode(array(
                'message' => $message
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Student Note Sync: Update note error - ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code === 200) {
            return $body;
        }
        
        // If 404, the comment no longer exists - create a new one
        if ($code === 404) {
            error_log('Student Note Sync: Comment ' . $comment_id . ' not found, creating new one');
            return $this->add_contact_note($contact_id, $message);
        }
        
        error_log('Student Note Sync: Update note failed - HTTP ' . $code . ' - ' . wp_remote_retrieve_body($response));
        return false;
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'aks-integration',
            'Student Note Sync',
            'Student Note Sync',
            'manage_options',
            'aks-student-note-sync',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        $sp_configured = !empty($this->settings['api_id']) && !empty($this->settings['api_secret']);
        $gf_active = class_exists('GFAPI');
        
        // Count users with SendPulse contact IDs
        $total_users = count(get_users(array(
            'meta_key' => 'sendpulse_contact_id',
            'meta_compare' => '!=',
            'meta_value' => '',
            'fields' => 'ID'
        )));
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Student Note Sync', 'aks-integration'); ?></h1>
            
            <?php if (!$sp_configured): ?>
            <div class="notice notice-error">
                <p><strong>Error:</strong> SendPulse API is not configured. 
                <a href="<?php echo admin_url('admin.php?page=aks-sendpulse'); ?>">Configure API Settings</a></p>
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
                    • Reads student data from Gravity Forms (Form <?php echo self::FORM_ID; ?>, Field <?php echo self::STUDENT_FIELD_ID; ?>)<br>
                    • Creates or updates a note on the SendPulse contact with student names and birthdays<br>
                    • Automatically syncs when Form 3 is submitted or when student entries are updated via GravityView<br>
                    • Use this tool to bulk sync existing entries
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
                
                <div id="sync-results" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; font-family: monospace; font-size: 12px;"></div>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var isProcessing = false;
            var shouldStop = false;
            var processedCount = 0;
            var totalToProcess = 0;
            var currentBatch = 0;
            var totalBatches = 0;
            
            function log(message, type) {
                var color = type === 'error' ? '#dc3545' : (type === 'success' ? '#28a745' : '#333');
                var timestamp = new Date().toLocaleTimeString();
                $('#sync-results').prepend('<div style="color: ' + color + ';">[' + timestamp + '] ' + message + '</div>');
            }
            
            function updateProgress() {
                var percent = totalToProcess > 0 ? (processedCount / totalToProcess * 100) : 0;
                $('#progress-bar').css('width', percent + '%');
                $('#progress-text').text(processedCount + ' / ' + totalToProcess + ' users processed');
            }
            
            function processBatch(offset, limit, endOffset, userDelay, batchDelay, autoAdvance) {
                if (shouldStop) {
                    log('Processing stopped by user', 'error');
                    resetUI();
                    return;
                }
                
                isProcessing = true;
                $('#btn-start-sync, #btn-auto-process').prop('disabled', true);
                $('#btn-stop-sync').show();
                $('.aks-sync-progress').show();
                
                if (autoAdvance) {
                    $('#batch-progress').show();
                    $('#batch-current').text(currentBatch);
                    $('#batch-total').text(totalBatches);
                    $('#current-offset').text(offset);
                }
                
                log('Fetching users (offset: ' + offset + ', limit: ' + limit + ')...');
                
                $.post(ajaxurl, {
                    action: 'aks_get_users_for_note_sync',
                    offset: offset,
                    limit: limit
                }, function(response) {
                    if (!response.success) {
                        log('Error: ' + response.data, 'error');
                        resetUI();
                        return;
                    }
                    
                    var users = response.data;
                    if (users.length === 0) {
                        log('No more users to process', 'success');
                        resetUI();
                        return;
                    }
                    
                    log('Processing ' + users.length + ' users...');
                    
                    var userIndex = 0;
                    
                    function processNextUser() {
                        if (shouldStop) {
                            log('Processing stopped by user', 'error');
                            resetUI();
                            return;
                        }
                        
                        if (userIndex >= users.length) {
                            // Batch complete
                            var newOffset = offset + limit;
                            $('#sync-offset').val(newOffset);
                            
                            if (autoAdvance && newOffset < endOffset) {
                                currentBatch++;
                                
                                // Countdown
                                var countdown = batchDelay;
                                var countdownInterval = setInterval(function() {
                                    if (shouldStop) {
                                        clearInterval(countdownInterval);
                                        return;
                                    }
                                    $('#batch-countdown').text('Next batch in ' + countdown + 's...');
                                    countdown--;
                                    if (countdown < 0) {
                                        clearInterval(countdownInterval);
                                        $('#batch-countdown').text('Processing...');
                                        processBatch(newOffset, limit, endOffset, userDelay, batchDelay, true);
                                    }
                                }, 1000);
                            } else {
                                log('All batches complete!', 'success');
                                resetUI();
                            }
                            return;
                        }
                        
                        var user = users[userIndex];
                        
                        $.post(ajaxurl, {
                            action: 'aks_sync_student_note',
                            user_id: user.ID
                        }, function(res) {
                            processedCount++;
                            updateProgress();
                            
                            if (res.success) {
                                var action = res.data.action || 'synced';
                                log('✓ User ' + user.ID + ' (' + user.display_name + '): Note ' + action, 'success');
                            } else {
                                log('✗ User ' + user.ID + ' (' + user.display_name + '): ' + res.data, 'error');
                            }
                            
                            userIndex++;
                            setTimeout(processNextUser, userDelay);
                        }).fail(function() {
                            processedCount++;
                            updateProgress();
                            log('✗ User ' + user.ID + ': Request failed', 'error');
                            userIndex++;
                            setTimeout(processNextUser, userDelay);
                        });
                    }
                    
                    processNextUser();
                }).fail(function() {
                    log('Failed to fetch users', 'error');
                    resetUI();
                });
            }
            
            function resetUI() {
                isProcessing = false;
                shouldStop = false;
                $('#btn-start-sync, #btn-auto-process').prop('disabled', false);
                $('#btn-stop-sync').hide();
                $('#batch-countdown').text('');
                $('#sync-status').text('');
            }
            
            // Single batch button
            $('#btn-start-sync').click(function() {
                if (isProcessing) return;
                
                shouldStop = false;
                processedCount = 0;
                
                var offset = parseInt($('#sync-offset').val()) || 0;
                var limit = parseInt($('#sync-limit').val()) || 10;
                var userDelay = parseInt($('#sync-user-delay').val()) || 300;
                
                totalToProcess = limit;
                updateProgress();
                
                processBatch(offset, limit, offset + limit, userDelay, 0, false);
            });
            
            // Auto process all button
            $('#btn-auto-process').click(function() {
                if (isProcessing) return;
                
                shouldStop = false;
                processedCount = 0;
                currentBatch = 1;
                
                var offset = parseInt($('#sync-offset').val()) || 0;
                var limit = parseInt($('#sync-limit').val()) || 10;
                var endOffset = parseInt($('#sync-end-offset').val()) || <?php echo $total_users; ?>;
                var userDelay = parseInt($('#sync-user-delay').val()) || 300;
                var batchDelay = parseInt($('#sync-batch-delay').val()) || 3;
                
                totalToProcess = endOffset - offset;
                totalBatches = Math.ceil(totalToProcess / limit);
                
                updateProgress();
                
                log('Starting auto-process: ' + totalBatches + ' batches, ' + totalToProcess + ' users');
                
                processBatch(offset, limit, endOffset, userDelay, batchDelay, true);
            });
            
            // Stop button
            $('#btn-stop-sync').click(function() {
                shouldStop = true;
                $(this).text('Stopping...');
                $('#sync-status').text('Stopping after current user...');
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Get users for sync
     */
    public function ajax_get_users() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 10;
        
        $users = get_users(array(
            'meta_key' => 'sendpulse_contact_id',
            'meta_compare' => '!=',
            'meta_value' => '',
            'number' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => array('ID', 'display_name')
        ));
        
        wp_send_json_success($users);
    }
    
    /**
     * AJAX: Sync single user
     */
    public function ajax_sync_single_user() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }
        
        $result = $this->sync_student_note_for_user($user_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
}
