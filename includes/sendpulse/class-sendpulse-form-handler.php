<?php
/**
 * Gravity Forms Handler
 * Monitors form submissions and creates SendPulse and Quo contacts
 */

class AKS_SendPulse_Form_Handler {
    
    private $settings;
    
    public function __construct() {
        $this->settings = get_option('aks_sendpulse_settings');
        
        // Hook into Gravity Forms pre-submission (after validation, before entry creation)
        add_action('gform_pre_submission', array($this, 'handle_form_submission'), 10, 1);
        
        // Hook after submission to update user meta
        add_action('gform_after_submission_2', array($this, 'update_user_meta'), 10, 2);
        
        // Hook after Form 3 submission to handle email list and SMS opt-in
        add_action('gform_after_submission_3', array($this, 'handle_form_3_opt_ins'), 10, 2);
    }
    
    /**
     * Handle form submission and update field values before entry is created
     * 
     * @param array $form The form object
     */
    public function handle_form_submission($form) {
        // Check if this is the form we're monitoring
        if (empty($this->settings['form_id']) || $form['id'] != $this->settings['form_id']) {
            return;
        }
        
        // Get field values from the submission
        $first_name = $this->get_field_value('3.3');  // First Name
        $last_name = $this->get_field_value('3.6');   // Last Name
        $email = $this->get_field_value('4');         // Email
        $phone = $this->get_field_value('5');         // Phone
        
        // Validate required fields
        if (empty($first_name) || empty($last_name)) {
            return;
        }
        
        // Prepare contact data
        $contact_data = array(
            'firstName' => $first_name,
            'lastName' => $last_name
        );
        
        if (!empty($email)) {
            $contact_data['email'] = $email;
        }
        
        if (!empty($phone)) {
            $contact_data['phone'] = $phone;
        }
        
        // Create or update contact in SendPulse
        $this->create_sendpulse_contact($contact_data);
        
        // Create or update contact in Quo
        $this->create_quo_contact($contact_data);
    }
    
    /**
     * Update user meta with SendPulse and Quo IDs after entry is saved
     * 
     * @param array $entry The entry object
     * @param array $form The form object
     */
    public function update_user_meta($entry, $form) {
        // Get user ID from field 32
        $user_id = rgar($entry, '32');
        
        if (empty($user_id)) {
            return;
        }
        
        // Update SendPulse IDs
        $sendpulse_contact_id = rgar($entry, '26');
        $sendpulse_user_id = rgar($entry, '27');
        $sendpulse_phone_id = rgar($entry, '28');
        $sendpulse_email_id = rgar($entry, '29');
        
        if (!empty($sendpulse_contact_id)) {
            update_user_meta($user_id, 'sendpulse_contact_id', $sendpulse_contact_id);
        }
        
        if (!empty($sendpulse_user_id)) {
            update_user_meta($user_id, 'sendpulse_user_id', $sendpulse_user_id);
        }
        
        if (!empty($sendpulse_phone_id)) {
            update_user_meta($user_id, 'sendpulse_phone_id', $sendpulse_phone_id);
        }
        
        if (!empty($sendpulse_email_id)) {
            update_user_meta($user_id, 'sendpulse_email_id', $sendpulse_email_id);
        }
        
        // Update Quo IDs
        $quo_contact_id = rgar($entry, '30');
        $quo_phone_id = rgar($entry, '31');
        
        if (!empty($quo_contact_id)) {
            update_user_meta($user_id, 'quo_contact_id', $quo_contact_id);
        }
        
        if (!empty($quo_phone_id)) {
            update_user_meta($user_id, 'quo_phone_id', $quo_phone_id);
        }
    }
    
    /**
     * Create or update contact in Quo
     * 
     * @param array $contact_data Contact information
     */
    private function create_quo_contact($contact_data) {
        // Check if Quo API key is configured
        if (empty($this->settings['quo_api_key'])) {
            return;
        }
        
        $phone = isset($contact_data['phone']) ? $contact_data['phone'] : '';
        
        if (empty($phone)) {
            return;
        }
        
        // Initialize Quo API client
        if (!class_exists('AKS_Quo_API')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-quo-api.php';
        }
        
        $api = new AKS_Quo_API($this->settings['quo_api_key']);
        
        // Search for existing contact by phone
        $existing_contact = $api->search_contact_by_phone($phone);
        
        if ($existing_contact) {
            // Check if first name and last name are empty
            $first_name = isset($existing_contact['defaultFields']['firstName']) ? $existing_contact['defaultFields']['firstName'] : '';
            $last_name = isset($existing_contact['defaultFields']['lastName']) ? $existing_contact['defaultFields']['lastName'] : '';
            
            if (empty($first_name) && empty($last_name)) {
                // Update with names
                $api->update_contact_names(
                    $existing_contact['id'],
                    $contact_data['firstName'],
                    $contact_data['lastName'],
                    $phone
                );
            }
            
            // Store Quo Contact ID in field 30
            $_POST['input_30'] = $existing_contact['id'];
            
            // Store Quo Phone ID in field 31 if available
            if (isset($existing_contact['defaultFields']['phoneNumbers'][0]['id'])) {
                $_POST['input_31'] = $existing_contact['defaultFields']['phoneNumbers'][0]['id'];
            }
        } else {
            // Create new contact
            $result = $api->create_contact($contact_data);
            
            if ($result !== false && isset($result['data'])) {
                // Store Quo Contact ID in field 30
                if (isset($result['data']['id'])) {
                    $_POST['input_30'] = $result['data']['id'];
                }
                
                // Store Quo Phone ID in field 31
                if (isset($result['data']['defaultFields']['phoneNumbers'][0]['id'])) {
                    $_POST['input_31'] = $result['data']['defaultFields']['phoneNumbers'][0]['id'];
                }
            }
        }
    }
    
    /**
     * Get field value from POST data
     * 
     * @param string $field_id The field ID to retrieve
     * @return string The field value
     */
    private function get_field_value($field_id) {
        if (empty($field_id)) {
            return '';
        }
        
        // Gravity Forms stores field values in POST with input_ prefix
        $input_name = 'input_' . str_replace('.', '_', $field_id);
        
        if (isset($_POST[$input_name])) {
            return sanitize_text_field($_POST[$input_name]);
        }
        
        return '';
    }
    
    /**
     * Create or update contact in SendPulse CRM
     * 
     * @param array $contact_data Contact information
     */
    private function create_sendpulse_contact($contact_data) {
        // Check if API credentials are configured
        if (empty($this->settings['api_id']) || empty($this->settings['api_secret'])) {
            return;
        }
        
        // Initialize API client
        require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-sendpulse-api.php';
        $api = new AKS_SendPulse_API($this->settings['api_id'], $this->settings['api_secret']);
        
        // Check if contact already exists
        $email = isset($contact_data['email']) ? $contact_data['email'] : '';
        $phone = isset($contact_data['phone']) ? $contact_data['phone'] : '';
        
        $search_result = $api->search_contact($email, $phone);
        
        if ($search_result['exists']) {
            $contact_id = $search_result['contact_id'];
            
            // Get full contact details to extract all IDs
            $contact = $api->get_contact($contact_id);
            
            if ($contact && isset($contact['data'])) {
                $contact_detail = $contact['data'];
                
                // Update name if it's different from submitted values
                $current_first = isset($contact_detail['firstName']) ? $contact_detail['firstName'] : '';
                $current_last = isset($contact_detail['lastName']) ? $contact_detail['lastName'] : '';
                $submitted_first = $contact_data['firstName'];
                $submitted_last = $contact_data['lastName'];
                
                if ($current_first !== $submitted_first || $current_last !== $submitted_last) {
                    $api->update_contact_name($contact_id, $submitted_first, $submitted_last);
                }
                
                // Set base IDs
                $_POST['input_26'] = $contact_detail['id']; // Contact ID
                $_POST['input_27'] = $contact_detail['userId']; // User ID
                
                // Handle PHONE: If we matched on email only, need to add phone
                if ($search_result['has_email'] && !$search_result['has_phone'] && !empty($phone)) {
                    $phone_result = $api->add_phone_to_contact($contact_id, $phone);
                    
                    // Extract phone ID from add response - check different possible response structures
                    if ($phone_result) {
                        if (isset($phone_result['data']['id'])) {
                            $_POST['input_28'] = $phone_result['data']['id'];
                        } else if (isset($phone_result['data'][0]['id'])) {
                            $_POST['input_28'] = $phone_result['data'][0]['id'];
                        } else if (isset($phone_result['id'])) {
                            $_POST['input_28'] = $phone_result['id'];
                        } else {
                            // Fallback: re-fetch contact to get phone ID
                            $updated_contact = $api->get_contact($contact_id);
                            if ($updated_contact && isset($updated_contact['data']['phones'])) {
                                $phone_clean = preg_replace('/[^0-9]/', '', $phone);
                                if (strlen($phone_clean) === 10) {
                                    $phone_clean = '1' . $phone_clean;
                                }
                                foreach ($updated_contact['data']['phones'] as $phone_item) {
                                    if ($phone_item['phone'] === $phone_clean) {
                                        $_POST['input_28'] = $phone_item['id'];
                                        break;
                                    }
                                }
                            }
                        }
                    }
                } else if (isset($contact_detail['phones']) && !empty($contact_detail['phones'])) {
                    // Phone already exists, get ID from contact
                    $_POST['input_28'] = $contact_detail['phones'][0]['id'];
                }
                
                // Handle EMAIL: If we matched on phone only, need to add email
                if ($search_result['has_phone'] && !$search_result['has_email'] && !empty($email)) {
                    $email_result = $api->add_email_to_contact($contact_id, $email);
                    
                    // Extract email ID from add response - check different possible response structures
                    if ($email_result) {
                        if (isset($email_result['data']['id'])) {
                            $_POST['input_29'] = $email_result['data']['id'];
                        } else if (isset($email_result['data'][0]['id'])) {
                            $_POST['input_29'] = $email_result['data'][0]['id'];
                        } else if (isset($email_result['id'])) {
                            $_POST['input_29'] = $email_result['id'];
                        } else {
                            // Fallback: re-fetch contact to get email ID
                            $updated_contact = $api->get_contact($contact_id);
                            if ($updated_contact && isset($updated_contact['data']['emails'])) {
                                foreach ($updated_contact['data']['emails'] as $email_item) {
                                    if ($email_item['email'] === $email) {
                                        $_POST['input_29'] = $email_item['id'];
                                        break;
                                    }
                                }
                            }
                        }
                    }
                } else if (isset($contact_detail['emails']) && !empty($contact_detail['emails'])) {
                    // Email already exists (we matched on it), get ID from contact
                    $_POST['input_29'] = $contact_detail['emails'][0]['id'];
                }
            }
            
            return;
        }
        
        // Contact doesn't exist - create new
        $result = $api->create_contact($contact_data);
        
        if ($result === false) {
            return;
        }
        
        // Extract IDs from response and update POST data so they're saved to the entry
        if (isset($result['data'])) {
            $data = $result['data'];
            
            // Populate Gravity Forms fields with SendPulse IDs
            if (isset($data['id'])) {
                $_POST['input_26'] = $data['id']; // SendPulse Contact ID
            }
            
            if (isset($data['userId'])) {
                $_POST['input_27'] = $data['userId']; // SendPulse User ID
            }
            
            if (isset($data['phones'][0]['id'])) {
                $_POST['input_28'] = $data['phones'][0]['id']; // SendPulse Phone ID
            }
            
            if (isset($data['emails'][0]['id'])) {
                $_POST['input_29'] = $data['emails'][0]['id']; // SendPulse Email ID
            }
        }
    }
    
    /**
     * Handle Form 3 submission - email list and SMS opt-in based on user choices
     * 
     * @param array $entry The entry object
     * @param array $form The form object
     */
    public function handle_form_3_opt_ins($entry, $form) {
        // Get the opt-in field values
        $email_list_opt_in = rgar($entry, '22'); // Field 22: Email list opt-in (Yes/No)
        $sms_opt_in = rgar($entry, '23');        // Field 23: SMS opt-in (Yes/No)
        
        // Get user email - field 29 in Form 3
        $email = rgar($entry, '29');
        
        // Get user by email to find their SendPulse contact ID
        if (empty($email)) {
            return;
        }
        
        $user = get_user_by('email', $email);
        if (!$user) {
            return;
        }
        
        $contact_id = get_user_meta($user->ID, 'sendpulse_contact_id', true);
        if (empty($contact_id)) {
            return;
        }
        
        // Initialize API
        if (!class_exists('AKS_SendPulse_API')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-sendpulse-api.php';
        }
        
        $api = new AKS_SendPulse_API($this->settings['api_id'], $this->settings['api_secret']);
        
        // Add to email list if they said "Yes"
        if ($email_list_opt_in === 'Yes') {
            $api->add_to_mailing_list($email, 1102646);
        }
        
        // Add SMS tag if they said "Yes"
        if ($sms_opt_in === 'Yes') {
            $api->add_tag_to_contact($contact_id, 55139);
        }
        
        // Add student data as a note
        $this->add_student_note($entry, $contact_id, $api);
    }
    
    /**
     * Add student information as a note to SendPulse contact
     * 
     * @param array $entry Gravity Forms entry
     * @param int $contact_id SendPulse contact ID
     * @param AKS_SendPulse_API $api SendPulse API instance
     */
    private function add_student_note($entry, $contact_id, $api) {
        // Get nested form entries (Students) from field 21
        $child_entry_ids_string = rgar($entry, '21');
        
        if (empty($child_entry_ids_string)) {
            return;
        }
        
        // Parse the comma-separated string into an array
        $child_entry_ids = explode(',', $child_entry_ids_string);
        
        // Retrieve each child entry
        $student_lines = array();
        foreach ($child_entry_ids as $child_entry_id) {
            $child_entry = GFAPI::get_entry($child_entry_id);
            
            if (!is_wp_error($child_entry) && $child_entry) {
                // Name field is split: 1.3 = first name, 1.6 = last name
                $student_first_name = rgar($child_entry, '1.3');
                $student_last_name = rgar($child_entry, '1.6');
                $student_birthdate = rgar($child_entry, '3');
                
                // Combine first and last name
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
        }
        
        if (empty($student_lines)) {
            return;
        }
        
        // Format the note message
        $note_message = "Student Names and Birthdays:\n" . implode("\n", $student_lines);
        
        $api->add_note_to_contact($contact_id, $note_message);
    }
    
    /**
     * Add contact to mailing list and tag (kept for potential future use)
     * 
     * @param AKS_SendPulse_API $api API instance
     * @param int $contact_id Contact ID
     * @param string $email Email address
     */
    private function add_to_list_and_tag($api, $contact_id, $email = '') {
        // Add to "Updates and info" mailing list (ID: 1102646)
        if (!empty($email)) {
            $api->add_to_mailing_list($email, 1102646);
        }
        
        // Add "SMS opted-in" tag (tag ID: 55139)
        $api->add_tags($contact_id, array('SMS opted-in'));
    }
}