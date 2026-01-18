<?php
/**
 * Student Note Sync Handler
 * Syncs student information from Gravity Forms to SendPulse contact description
 * 
 * Handles:
 * - Form 3 submission (creates/updates description)
 * - GravityView edit (updates description)
 * - Admin bulk sync for existing entries
 * - Cleanup utility for removing old notes
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
        add_action('wp_ajax_aks_remove_student_note', array($this, 'ajax_remove_single_note'));
        add_action('wp_ajax_aks_get_users_with_notes', array($this, 'ajax_get_users_with_notes'));
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
     * Handle Form 3 submission - create or update student description
     */
    public function handle_form_submission($entry, $form) {
        // Get user ID from entry
        $user_id = rgar($entry, 'created_by');
        
        if (empty($user_id)) {
            error_log('Student Note Sync: No user ID found in Form 3 entry');
            return;
        }
        
        $this->sync_student_description_for_user($user_id, $entry);
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
            $this->sync_student_description_for_user($user_id);
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
                $this->sync_student_description_for_user($user_id);
            }
        }
        
        // If Form 3 was updated directly
        if ($form_id == self::FORM_ID) {
            $user_id = rgar($entry, 'created_by');
            if ($user_id) {
                $this->sync_student_description_for_user($user_id, $entry);
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
     * Sync student description for a specific user
     */
    public function sync_student_description_for_user($user_id, $entry = null) {
        // Get SendPulse contact ID
        $contact_id = get_user_meta($user_id, 'sendpulse_contact_id', true);
        
        if (empty($contact_id)) {
            error_log('Student Note Sync: No SendPulse contact ID for user ' . $user_id);
            return array('success' => false, 'message' => 'No SendPulse contact ID');
        }
        
        // If no entry provided, find it
        if (empty($entry)) {
            $entry = $this->get_form3_entry_for_user($user_id);
        }
        
        if (empty($entry)) {
            error_log('Student Note Sync: No Form 3 entry found for user ' . $user_id);
            return array('success' => false, 'message' => 'No Form 3 entry found');
        }
        
        // Build the description from student data
        $description = $this->build_student_description($entry);
        
        if (empty($description)) {
            error_log('Student Note Sync: No student data to sync for user ' . $user_id);
            return array('success' => false, 'message' => 'No student data');
        }
        
        // Update the contact description
        $result = $this->update_contact_description($contact_id, $description);
        
        if ($result) {
            error_log('Student Note Sync: Successfully updated description for user ' . $user_id);
            return array('success' => true, 'message' => 'Description updated', 'action' => 'updated');
        } else {
            error_log('Student Note Sync: Failed to update description for user ' . $user_id);
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
     * Build the description from student data
     */
    private function build_student_description($entry) {
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
        
        // Use newlines - SendPulse description field supports them
        return implode("\n", $student_lines);
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
     * Update contact attribute via PUT, or POST if it doesn't exist yet
     */
    private function update_contact_description($contact_id, $description) {
        $token = $this->get_access_token();
        
        if (!$token) {
            error_log('Student Note Sync: Failed to get access token');
            return false;
        }
        
        $attribute_id = 1009955; // Custom "Students" attribute ID
        
        // First try PUT to update existing value
        $result = $this->put_attribute_value($contact_id, $attribute_id, $description, $token);
        
        if ($result === true) {
            return true;
        }
        
        // If PUT returned 404, try POST to create the attribute value
        if ($result === 404) {
            error_log('Student Note Sync: Attribute not found, creating with POST');
            return $this->post_attribute_value($contact_id, $attribute_id, $description, $token);
        }
        
        return false;
    }
    
    /**
     * PUT attribute value (update existing)
     * Returns: true on success, 404 if not found, false on other errors
     */
    private function put_attribute_value($contact_id, $attribute_id, $value, $token) {
        $url = 'https://api.sendpulse.com/crm/v1/contacts/' . $contact_id . '/attributes/' . $attribute_id;
        
        $body = array('value' => $value);
        
        error_log('Student Note Sync: PUT contact ' . $contact_id . ' attribute ' . $attribute_id);
        
        $response = wp_remote_request($url, array(
            'method' => 'PUT',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Student Note Sync: PUT error - ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code === 200) {
            error_log('Student Note Sync: PUT successful');
            return true;
        }
        
        if ($code === 404) {
            return 404;
        }
        
        error_log('Student Note Sync: PUT failed - HTTP ' . $code . ' - ' . wp_remote_retrieve_body($response));
        return false;
    }
    
    /**
     * POST attribute value (create new)
     */
    private function post_attribute_value($contact_id, $attribute_id, $value, $token) {
        $url = 'https://api.sendpulse.com/crm/v1/contacts/' . $contact_id . '/attributes';
        
        $body = array(
            'attributeId' => $attribute_id,
            'value' => $value
        );
        
        error_log('Student Note Sync: POST contact ' . $contact_id . ' attribute ' . $attribute_id);
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Student Note Sync: POST error - ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code === 200 || $code === 201) {
            error_log('Student Note Sync: POST successful');
            return true;
        }
        
        error_log('Student Note Sync: POST failed - HTTP ' . $code . ' - ' . wp_remote_retrieve_body($response));
        return false;
    }
    
    /**
     * Get contact details from SendPulse
     */
    private function get_contact($contact_id) {
        $token = $this->get_access_token();
        
        if (!$token) {
            return null;
        }
        
        $url = 'https://api.sendpulse.com/crm/v1/contacts/' . $contact_id;
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Student Note Sync: Get contact error - ' . $response->get_error_message());
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code !== 200) {
            error_log('Student Note Sync: Get contact failed - HTTP ' . $code);
            return null;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return $body['data'] ?? null;
    }
    
    /**
     * Delete a note/comment from a contact
     */
    private function delete_contact_note($contact_id, $comment_id) {
        $token = $this->get_access_token();
        
        if (!$token) {
            return false;
        }
        
        $url = 'https://api.sendpulse.com/crm/v1/contacts/' . $contact_id . '/comments/' . $comment_id;
        
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Student Note Sync: Delete note error - ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        // 200 = success, 404 = already deleted (still counts as success)
        if ($code === 200 || $code === 204 || $code === 404) {
            return true;
        }
        
        error_log('Student Note Sync: Delete note failed - HTTP ' . $code . ' - ' . wp_remote_retrieve_body($response));
        return false;
    }
    
    /**
     * Get all comments for a contact
     */
    private function get_contact_comments($contact_id) {
        $token = $this->get_access_token();
        
        if (!$token) {
            return array();
        }
        
        $url = 'https://api.sendpulse.com/crm/v1/contacts/' . $contact_id . '/comments';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Student Note Sync: Get comments error - ' . $response->get_error_message());
            return array();
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code !== 200) {
            error_log('Student Note Sync: Get comments failed - HTTP ' . $code . ' - ' . wp_remote_retrieve_body($response));
            return array();
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return $body['data'] ?? array();
    }
    
    /**
     * Find and delete student-related comments for a contact
     * Returns array with success status and count of deleted comments
     */
    public function delete_student_comments_for_contact($contact_id) {
        $comments = $this->get_contact_comments($contact_id);
        
        if (empty($comments)) {
            return array('success' => true, 'deleted' => 0, 'message' => 'No comments found');
        }
        
        $deleted_count = 0;
        
        foreach ($comments as $comment) {
            // The message is in eventData.text, not message
            $message = '';
            if (isset($comment['eventData']['text'])) {
                $message = $comment['eventData']['text'];
            } elseif (isset($comment['message'])) {
                $message = $comment['message'];
            }
            
            $comment_id = $comment['id'] ?? null;
            
            if (!$comment_id || empty($message)) {
                continue;
            }
            
            // Check if this comment contains student data patterns
            // Look for: "Student Names and Birthdays" or date patterns like MM/DD/YYYY
            $is_student_note = false;
            
            if (stripos($message, 'Student Names and Birthdays') !== false) {
                $is_student_note = true;
            }
            
            if ($is_student_note) {
                error_log('Student Note Sync: Deleting comment ' . $comment_id . ' from contact ' . $contact_id);
                
                if ($this->delete_contact_note($contact_id, $comment_id)) {
                    $deleted_count++;
                }
            }
        }
        
        return array(
            'success' => true, 
            'deleted' => $deleted_count, 
            'message' => $deleted_count . ' comment(s) deleted'
        );
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'aks-integration',
            'Student Description Sync',
            'Student Description Sync',
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
        
        // For cleanup, we use the same count (all users with SendPulse IDs)
        // since we now check for notes via API rather than stored comment IDs
        $users_with_notes = $total_users;
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Student Description Sync', 'aks-integration'); ?></h1>
            
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
            
            <!-- TAB NAVIGATION -->
            <h2 class="nav-tab-wrapper">
                <a href="#sync-tab" class="nav-tab nav-tab-active" data-tab="sync-tab">Sync Descriptions</a>
                <a href="#cleanup-tab" class="nav-tab" data-tab="cleanup-tab">Remove Old Notes</a>
            </h2>
            
            <!-- SYNC DESCRIPTIONS TAB -->
            <div id="sync-tab" class="tab-content" style="display: block;">
                <div class="notice notice-info">
                    <p>
                        <strong>How it works:</strong><br>
                        • Reads student data from Gravity Forms (Form <?php echo self::FORM_ID; ?>, Field <?php echo self::STUDENT_FIELD_ID; ?>)<br>
                        • Updates the SendPulse contact's Description field with student names and birthdays<br>
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
            
            <!-- REMOVE OLD NOTES TAB -->
            <div id="cleanup-tab" class="tab-content" style="display: none;">
                <div class="notice notice-warning">
                    <p>
                        <strong>Note Cleanup:</strong><br>
                        • This tool removes student notes that were added to SendPulse contacts<br>
                        • It fetches each contact's comments via API and deletes those containing student data<br>
                        • Looks for notes with "Student Names and Birthdays" or date patterns (MM/DD/YYYY)<br>
                        • <strong>Users with SendPulse IDs to check: <?php echo number_format($users_with_notes); ?></strong>
                    </p>
                </div>
                
                <div class="aks-sync-controls" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                    <h2 style="margin-top: 0;">Cleanup Configuration</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">User Range</th>
                            <td>
                                <label>
                                    Start Offset: <input type="number" id="cleanup-offset" value="0" min="0" style="width: 80px;" />
                                </label>
                                &nbsp;&nbsp;
                                <label>
                                    Batch Size: <input type="number" id="cleanup-limit" value="10" min="1" max="50" style="width: 80px;" />
                                </label>
                                &nbsp;&nbsp;
                                <label>
                                    End Offset: <input type="number" id="cleanup-end-offset" value="<?php echo $users_with_notes; ?>" min="0" style="width: 80px;" />
                                </label>
                                <p class="description">Users with SendPulse IDs: <?php echo number_format($users_with_notes); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Timing</th>
                            <td>
                                <label>
                                    Delay between users: <input type="number" id="cleanup-user-delay" value="300" min="100" max="5000" step="100" style="width: 80px;" /> ms
                                </label>
                                &nbsp;&nbsp;
                                <label>
                                    Delay between batches: <input type="number" id="cleanup-batch-delay" value="3" min="1" max="60" style="width: 60px;" /> seconds
                                </label>
                            </td>
                        </tr>
                    </table>
                    
                    <p>
                        <button type="button" id="btn-start-cleanup" class="button button-large" <?php echo (!$sp_configured || $users_with_notes === 0) ? 'disabled' : ''; ?>>
                            Process Single Batch
                        </button>
                        <button type="button" id="btn-auto-cleanup" class="button button-primary button-large" style="background: #d63638; border-color: #d63638;" <?php echo (!$sp_configured || $users_with_notes === 0) ? 'disabled' : ''; ?>>
                            ▶ Auto Remove All Notes
                        </button>
                        <button type="button" id="btn-stop-cleanup" class="button button-large" style="display: none;">
                            ⏹ Stop
                        </button>
                        <span id="cleanup-status" style="margin-left: 15px; font-style: italic;"></span>
                    </p>
                    
                    <div id="cleanup-batch-progress" style="display: none; margin-top: 15px; padding: 15px; background: #fcf0f0; border: 1px solid #d63638; border-radius: 4px;">
                        <strong>Batch Progress:</strong> 
                        <span id="cleanup-batch-current">0</span> / <span id="cleanup-batch-total">0</span> batches
                        &nbsp;|&nbsp;
                        <strong>Current Offset:</strong> <span id="cleanup-current-offset">0</span>
                        &nbsp;|&nbsp;
                        <span id="cleanup-batch-countdown"></span>
                    </div>
                </div>
                
                <div class="aks-cleanup-progress" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; display: none;">
                    <h2 style="margin-top: 0;">Cleanup Progress</h2>
                    <div class="progress-bar-container" style="background: #f0f0f1; height: 24px; border-radius: 4px; overflow: hidden; margin-bottom: 15px;">
                        <div id="cleanup-progress-bar" style="width: 0%; height: 100%; background: #d63638; transition: width 0.3s;"></div>
                    </div>
                    <p id="cleanup-progress-text">0 / 0 users processed</p>
                    
                    <div id="cleanup-results" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; font-family: monospace; font-size: 12px;"></div>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Tab switching
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                var tabId = $(this).data('tab');
                
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                $('.tab-content').hide();
                $('#' + tabId).show();
            });
            
            // ========== SYNC DESCRIPTIONS ==========
            var syncIsProcessing = false;
            var syncShouldStop = false;
            var syncProcessedCount = 0;
            var syncTotalToProcess = 0;
            var syncCurrentBatch = 0;
            var syncTotalBatches = 0;
            
            function syncLog(message, type) {
                var color = type === 'error' ? '#dc3545' : (type === 'success' ? '#28a745' : '#333');
                var timestamp = new Date().toLocaleTimeString();
                $('#sync-results').prepend('<div style="color: ' + color + ';">[' + timestamp + '] ' + message + '</div>');
            }
            
            function syncUpdateProgress() {
                var percent = syncTotalToProcess > 0 ? (syncProcessedCount / syncTotalToProcess * 100) : 0;
                $('#progress-bar').css('width', percent + '%');
                $('#progress-text').text(syncProcessedCount + ' / ' + syncTotalToProcess + ' users processed');
            }
            
            function syncProcessBatch(offset, limit, endOffset, userDelay, batchDelay, autoAdvance) {
                if (syncShouldStop) {
                    syncLog('Processing stopped by user', 'error');
                    syncResetUI();
                    return;
                }
                
                syncIsProcessing = true;
                $('#btn-start-sync, #btn-auto-process').prop('disabled', true);
                $('#btn-stop-sync').show();
                $('.aks-sync-progress').show();
                
                if (autoAdvance) {
                    $('#batch-progress').show();
                    $('#batch-current').text(syncCurrentBatch);
                    $('#batch-total').text(syncTotalBatches);
                    $('#current-offset').text(offset);
                }
                
                syncLog('Fetching users (offset: ' + offset + ', limit: ' + limit + ')...');
                
                $.post(ajaxurl, {
                    action: 'aks_get_users_for_note_sync',
                    offset: offset,
                    limit: limit
                }, function(response) {
                    if (!response.success) {
                        syncLog('Error: ' + response.data, 'error');
                        syncResetUI();
                        return;
                    }
                    
                    var users = response.data;
                    if (users.length === 0) {
                        syncLog('No more users to process', 'success');
                        syncResetUI();
                        return;
                    }
                    
                    syncLog('Processing ' + users.length + ' users...');
                    
                    var userIndex = 0;
                    
                    function processNextUser() {
                        if (syncShouldStop) {
                            syncLog('Processing stopped by user', 'error');
                            syncResetUI();
                            return;
                        }
                        
                        if (userIndex >= users.length) {
                            var newOffset = offset + limit;
                            $('#sync-offset').val(newOffset);
                            
                            if (autoAdvance && newOffset < endOffset) {
                                syncCurrentBatch++;
                                
                                var countdown = batchDelay;
                                var countdownInterval = setInterval(function() {
                                    if (syncShouldStop) {
                                        clearInterval(countdownInterval);
                                        return;
                                    }
                                    $('#batch-countdown').text('Next batch in ' + countdown + 's...');
                                    countdown--;
                                    if (countdown < 0) {
                                        clearInterval(countdownInterval);
                                        $('#batch-countdown').text('Processing...');
                                        syncProcessBatch(newOffset, limit, endOffset, userDelay, batchDelay, true);
                                    }
                                }, 1000);
                            } else {
                                syncLog('All batches complete!', 'success');
                                syncResetUI();
                            }
                            return;
                        }
                        
                        var user = users[userIndex];
                        
                        $.post(ajaxurl, {
                            action: 'aks_sync_student_note',
                            user_id: user.ID
                        }, function(res) {
                            syncProcessedCount++;
                            syncUpdateProgress();
                            
                            if (res.success) {
                                var action = res.data.action || 'synced';
                                syncLog('✓ User ' + user.ID + ' (' + user.display_name + '): Description ' + action, 'success');
                            } else {
                                syncLog('✗ User ' + user.ID + ' (' + user.display_name + '): ' + res.data, 'error');
                            }
                            
                            userIndex++;
                            setTimeout(processNextUser, userDelay);
                        }).fail(function() {
                            syncProcessedCount++;
                            syncUpdateProgress();
                            syncLog('✗ User ' + user.ID + ': Request failed', 'error');
                            userIndex++;
                            setTimeout(processNextUser, userDelay);
                        });
                    }
                    
                    processNextUser();
                }).fail(function() {
                    syncLog('Failed to fetch users', 'error');
                    syncResetUI();
                });
            }
            
            function syncResetUI() {
                syncIsProcessing = false;
                syncShouldStop = false;
                $('#btn-start-sync, #btn-auto-process').prop('disabled', false);
                $('#btn-stop-sync').hide();
                $('#batch-countdown').text('');
                $('#sync-status').text('');
            }
            
            $('#btn-start-sync').click(function() {
                if (syncIsProcessing) return;
                
                syncShouldStop = false;
                syncProcessedCount = 0;
                
                var offset = parseInt($('#sync-offset').val()) || 0;
                var limit = parseInt($('#sync-limit').val()) || 10;
                var userDelay = parseInt($('#sync-user-delay').val()) || 300;
                
                syncTotalToProcess = limit;
                syncUpdateProgress();
                
                syncProcessBatch(offset, limit, offset + limit, userDelay, 0, false);
            });
            
            $('#btn-auto-process').click(function() {
                if (syncIsProcessing) return;
                
                syncShouldStop = false;
                syncProcessedCount = 0;
                syncCurrentBatch = 1;
                
                var offset = parseInt($('#sync-offset').val()) || 0;
                var limit = parseInt($('#sync-limit').val()) || 10;
                var endOffset = parseInt($('#sync-end-offset').val()) || <?php echo $total_users; ?>;
                var userDelay = parseInt($('#sync-user-delay').val()) || 300;
                var batchDelay = parseInt($('#sync-batch-delay').val()) || 3;
                
                syncTotalToProcess = endOffset - offset;
                syncTotalBatches = Math.ceil(syncTotalToProcess / limit);
                
                syncUpdateProgress();
                
                syncLog('Starting auto-process: ' + syncTotalBatches + ' batches, ' + syncTotalToProcess + ' users');
                
                syncProcessBatch(offset, limit, endOffset, userDelay, batchDelay, true);
            });
            
            $('#btn-stop-sync').click(function() {
                syncShouldStop = true;
                $(this).text('Stopping...');
                $('#sync-status').text('Stopping after current user...');
            });
            
            // ========== CLEANUP NOTES ==========
            var cleanupIsProcessing = false;
            var cleanupShouldStop = false;
            var cleanupProcessedCount = 0;
            var cleanupTotalToProcess = 0;
            var cleanupCurrentBatch = 0;
            var cleanupTotalBatches = 0;
            
            function cleanupLog(message, type) {
                var color = type === 'error' ? '#dc3545' : (type === 'success' ? '#28a745' : '#333');
                var timestamp = new Date().toLocaleTimeString();
                $('#cleanup-results').prepend('<div style="color: ' + color + ';">[' + timestamp + '] ' + message + '</div>');
            }
            
            function cleanupUpdateProgress() {
                var percent = cleanupTotalToProcess > 0 ? (cleanupProcessedCount / cleanupTotalToProcess * 100) : 0;
                $('#cleanup-progress-bar').css('width', percent + '%');
                $('#cleanup-progress-text').text(cleanupProcessedCount + ' / ' + cleanupTotalToProcess + ' users processed');
            }
            
            function cleanupProcessBatch(offset, limit, endOffset, userDelay, batchDelay, autoAdvance) {
                if (cleanupShouldStop) {
                    cleanupLog('Processing stopped by user', 'error');
                    cleanupResetUI();
                    return;
                }
                
                cleanupIsProcessing = true;
                $('#btn-start-cleanup, #btn-auto-cleanup').prop('disabled', true);
                $('#btn-stop-cleanup').show();
                $('.aks-cleanup-progress').show();
                
                if (autoAdvance) {
                    $('#cleanup-batch-progress').show();
                    $('#cleanup-batch-current').text(cleanupCurrentBatch);
                    $('#cleanup-batch-total').text(cleanupTotalBatches);
                    $('#cleanup-current-offset').text(offset);
                }
                
                cleanupLog('Fetching users with notes (offset: ' + offset + ', limit: ' + limit + ')...');
                
                $.post(ajaxurl, {
                    action: 'aks_get_users_with_notes',
                    offset: offset,
                    limit: limit
                }, function(response) {
                    if (!response.success) {
                        cleanupLog('Error: ' + response.data, 'error');
                        cleanupResetUI();
                        return;
                    }
                    
                    var users = response.data;
                    if (users.length === 0) {
                        cleanupLog('No more users with notes to process', 'success');
                        cleanupResetUI();
                        return;
                    }
                    
                    cleanupLog('Processing ' + users.length + ' users...');
                    
                    var userIndex = 0;
                    
                    function processNextUser() {
                        if (cleanupShouldStop) {
                            cleanupLog('Processing stopped by user', 'error');
                            cleanupResetUI();
                            return;
                        }
                        
                        if (userIndex >= users.length) {
                            var newOffset = offset + limit;
                            $('#cleanup-offset').val(newOffset);
                            
                            if (autoAdvance && newOffset < endOffset) {
                                cleanupCurrentBatch++;
                                
                                var countdown = batchDelay;
                                var countdownInterval = setInterval(function() {
                                    if (cleanupShouldStop) {
                                        clearInterval(countdownInterval);
                                        return;
                                    }
                                    $('#cleanup-batch-countdown').text('Next batch in ' + countdown + 's...');
                                    countdown--;
                                    if (countdown < 0) {
                                        clearInterval(countdownInterval);
                                        $('#cleanup-batch-countdown').text('Processing...');
                                        cleanupProcessBatch(newOffset, limit, endOffset, userDelay, batchDelay, true);
                                    }
                                }, 1000);
                            } else {
                                cleanupLog('All batches complete!', 'success');
                                cleanupResetUI();
                            }
                            return;
                        }
                        
                        var user = users[userIndex];
                        
                        $.post(ajaxurl, {
                            action: 'aks_remove_student_note',
                            user_id: user.ID
                        }, function(res) {
                            cleanupProcessedCount++;
                            cleanupUpdateProgress();
                            
                            if (res.success) {
                                var deleted = res.data.deleted || 0;
                                if (deleted > 0) {
                                    cleanupLog('✓ User ' + user.ID + ' (' + user.display_name + '): ' + deleted + ' note(s) removed', 'success');
                                } else {
                                    cleanupLog('○ User ' + user.ID + ' (' + user.display_name + '): No student notes found', '');
                                }
                            } else {
                                cleanupLog('✗ User ' + user.ID + ' (' + user.display_name + '): ' + res.data, 'error');
                            }
                            
                            userIndex++;
                            setTimeout(processNextUser, userDelay);
                        }).fail(function() {
                            cleanupProcessedCount++;
                            cleanupUpdateProgress();
                            cleanupLog('✗ User ' + user.ID + ': Request failed', 'error');
                            userIndex++;
                            setTimeout(processNextUser, userDelay);
                        });
                    }
                    
                    processNextUser();
                }).fail(function() {
                    cleanupLog('Failed to fetch users', 'error');
                    cleanupResetUI();
                });
            }
            
            function cleanupResetUI() {
                cleanupIsProcessing = false;
                cleanupShouldStop = false;
                $('#btn-start-cleanup, #btn-auto-cleanup').prop('disabled', false);
                $('#btn-stop-cleanup').hide();
                $('#cleanup-batch-countdown').text('');
                $('#cleanup-status').text('');
            }
            
            $('#btn-start-cleanup').click(function() {
                if (cleanupIsProcessing) return;
                
                cleanupShouldStop = false;
                cleanupProcessedCount = 0;
                
                var offset = parseInt($('#cleanup-offset').val()) || 0;
                var limit = parseInt($('#cleanup-limit').val()) || 10;
                var userDelay = parseInt($('#cleanup-user-delay').val()) || 300;
                
                cleanupTotalToProcess = limit;
                cleanupUpdateProgress();
                
                cleanupProcessBatch(offset, limit, offset + limit, userDelay, 0, false);
            });
            
            $('#btn-auto-cleanup').click(function() {
                if (cleanupIsProcessing) return;
                
                if (!confirm('Are you sure you want to remove all student notes from SendPulse contacts? This cannot be undone.')) {
                    return;
                }
                
                cleanupShouldStop = false;
                cleanupProcessedCount = 0;
                cleanupCurrentBatch = 1;
                
                var offset = parseInt($('#cleanup-offset').val()) || 0;
                var limit = parseInt($('#cleanup-limit').val()) || 10;
                var endOffset = parseInt($('#cleanup-end-offset').val()) || <?php echo $users_with_notes; ?>;
                var userDelay = parseInt($('#cleanup-user-delay').val()) || 300;
                var batchDelay = parseInt($('#cleanup-batch-delay').val()) || 3;
                
                cleanupTotalToProcess = endOffset - offset;
                cleanupTotalBatches = Math.ceil(cleanupTotalToProcess / limit);
                
                cleanupUpdateProgress();
                
                cleanupLog('Starting auto-cleanup: ' + cleanupTotalBatches + ' batches, ' + cleanupTotalToProcess + ' users');
                
                cleanupProcessBatch(offset, limit, endOffset, userDelay, batchDelay, true);
            });
            
            $('#btn-stop-cleanup').click(function() {
                cleanupShouldStop = true;
                $(this).text('Stopping...');
                $('#cleanup-status').text('Stopping after current user...');
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Get users for sync (users with SendPulse contact ID)
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
     * AJAX: Get users with SendPulse contact IDs (for cleanup - will check for notes via API)
     */
    public function ajax_get_users_with_notes() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 10;
        
        // Get all users with SendPulse contact IDs (we'll check for notes via API)
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
     * AJAX: Sync single user description
     */
    public function ajax_sync_single_user() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }
        
        $result = $this->sync_student_description_for_user($user_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX: Remove single user's notes via API lookup
     */
    public function ajax_remove_single_note() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }
        
        $contact_id = get_user_meta($user_id, 'sendpulse_contact_id', true);
        
        if (empty($contact_id)) {
            wp_send_json_error('No SendPulse contact ID');
        }
        
        // Use the new method that fetches and deletes via API
        $result = $this->delete_student_comments_for_contact($contact_id);
        
        if ($result['success']) {
            // Clear any stored comment ID from user meta
            delete_user_meta($user_id, 'sendpulse_comment_id');
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
}