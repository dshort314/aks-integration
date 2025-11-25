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
        
        // Log what we're getting
        error_log('Form Handler - First Name: ' . $first_name);
        error_log('Form Handler - Last Name: ' . $last_name);
        error_log('Form Handler - Email: ' . $email);
        error_log('Form Handler - Phone: ' . $phone);
        
        // Validate required fields
        if (empty($first_name) || empty($last_name)) {
            error_log('SendPulse: First name and last name are required');
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
            error_log('SendPulse: No user ID found in entry field 32');
            return;
        }
        
        // Update SendPulse IDs
        $sendpulse_contact_id = rgar($entry, '26');
        $sendpulse_user_id = rgar($entry, '27');
        $sendpulse_phone_id = rgar($entry, '28');
        $sendpulse_email_id = rgar($entry, '29');
        
        if (!empty($sendpulse_contact_id)) {
            update_user_meta($user_id, 'sendpulse_contact_id', $sendpulse_contact_id);
            error_log('SendPulse: Updated user ' . $user_id . ' with contact_id: ' . $sendpulse_contact_id);
        }
        
        if (!empty($sendpulse_user_id)) {
            update_user_meta($user_id, 'sendpulse_user_id', $sendpulse_user_id);
            error_log('SendPulse: Updated user ' . $user_id . ' with user_id: ' . $sendpulse_user_id);
        }
        
        if (!empty($sendpulse_phone_id)) {
            update_user_meta($user_id, 'sendpulse_phone_id', $sendpulse_phone_id);
            error_log('SendPulse: Updated user ' . $user_id . ' with phone_id: ' . $sendpulse_phone_id);
        }
        
        if (!empty($sendpulse_email_id)) {
            update_user_meta($user_id, 'sendpulse_email_id', $sendpulse_email_id);
            error_log('SendPulse: Updated user ' . $user_id . ' with email_id: ' . $sendpulse_email_id);
        }
        
        // Update Quo IDs
        $quo_contact_id = rgar($entry, '30');
        $quo_phone_id = rgar($entry, '31');
        
        if (!empty($quo_contact_id)) {
            update_user_meta($user_id, 'quo_contact_id', $quo_contact_id);
            error_log('Quo: Updated user ' . $user_id . ' with contact_id: ' . $quo_contact_id);
        }
        
        if (!empty($quo_phone_id)) {
            update_user_meta($user_id, 'quo_phone_id', $quo_phone_id);
            error_log('Quo: Updated user ' . $user_id . ' with phone_id: ' . $quo_phone_id);
        }
    }
    
    /**
     * Create or update contact in Quo
     * 
     * @param array $contact_data Contact information
     */
    private function create_quo_contact($contact_data) {
        error_log('Quo: Entering create_quo_contact - Data: ' . json_encode($contact_data));
        
        // Check if Quo API key is configured
        if (empty($this->settings['quo_api_key'])) {
            error_log('Quo: API key not configured in settings');
            return;
        }
        
        $phone = isset($contact_data['phone']) ? $contact_data['phone'] : '';
        
        if (empty($phone)) {
            error_log('Quo: Phone number required but not provided');
            return;
        }
        
        error_log('Quo: Phone found: ' . $phone);
        
        // Initialize Quo API client
        if (!class_exists('AKS_Quo_API')) {
            require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/sendpulse/class-quo-api.php';
        }
        
        $api = new AKS_Quo_API($this->settings['quo_api_key']);
        error_log('Quo: API client initialized');
        
        // Search for existing contact by phone
        error_log('Quo: Searching for contact by phone...');
        $existing_contact = $api->search_contact_by_phone($phone);
        
        if ($existing_contact) {
            error_log('Quo: Found existing contact: ' . json_encode($existing_contact));
            
            // Check if first name and last name are empty
            $first_name = isset($existing_contact['defaultFields']['firstName']) ? $existing_contact['defaultFields']['firstName'] : '';
            $last_name = isset($existing_contact['defaultFields']['lastName']) ? $existing_contact['defaultFields']['lastName'] : '';
            
            if (empty($first_name) && empty($last_name)) {
                // Update with names
                error_log('Quo: Updating contact ' . $existing_contact['id'] . ' with names');
                $update_result = $api->update_contact_names(
                    $existing_contact['id'],
                    $contact_data['firstName'],
                    $contact_data['lastName'],
                    $phone
                );
                error_log('Quo: Update result: ' . json_encode($update_result));
            } else {
                error_log('Quo: Contact already has names (First: ' . $first_name . ', Last: ' . $last_name . '), skipping update');
            }
            
            // Store Quo Contact ID in field 30
            $_POST['input_30'] = $existing_contact['id'];
            error_log('Quo: Set input_30 to: ' . $existing_contact['id']);
            
            // Store Quo Phone ID in field 31 if available
            if (isset($existing_contact['defaultFields']['phoneNumbers'][0]['id'])) {
                $_POST['input_31'] = $existing_contact['defaultFields']['phoneNumbers'][0]['id'];
                error_log('Quo: Set input_31 to: ' . $existing_contact['defaultFields']['phoneNumbers'][0]['id']);
            }
        } else {
            // Create new contact
            error_log('Quo: No existing contact found, creating new contact');
            $result = $api->create_contact($contact_data);
            error_log('Quo: Create contact result: ' . json_encode($result));
            
            if ($result !== false && isset($result['data'])) {
                // Store Quo Contact ID in field 30
                if (isset($result['data']['id'])) {
                    $_POST['input_30'] = $result['data']['id'];
                    error_log('Quo: Set input_30 to: ' . $result['data']['id']);
                }
                
                // Store Quo Phone ID in field 31
                if (isset($result['data']['defaultFields']['phoneNumbers'][0]['id'])) {
                    $_POST['input_31'] = $result['data']['defaultFields']['phoneNumbers'][0]['id'];
                    error_log('Quo: Set input_31 to: ' . $result['data']['defaultFields']['phoneNumbers'][0]['id']);
                }
            } else {
                error_log('Quo: Create contact failed or returned false');
            }
        }
        
        error_log('Quo: Exiting create_quo_contact');
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
            error_log('SendPulse: API credentials not configured');
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
            error_log('SendPulse: Found existing contact ' . $contact_id);
            error_log('SendPulse: Match details - has_email: ' . ($search_result['has_email'] ? 'YES' : 'NO') . ', has_phone: ' . ($search_result['has_phone'] ? 'YES' : 'NO'));
            
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
                    error_log('SendPulse: Name differs - Current: "' . $current_first . ' ' . $current_last . '" vs Submitted: "' . $submitted_first . ' ' . $submitted_last . '"');
                    error_log('SendPulse: Updating contact name');
                    $update_result = $api->update_contact_name($contact_id, $submitted_first, $submitted_last);
                    error_log('SendPulse: Name update result: ' . json_encode($update_result));
                } else {
                    error_log('SendPulse: Name matches, no update needed');
                }
                
                // Set base IDs
                $_POST['input_26'] = $contact_detail['id']; // Contact ID
                $_POST['input_27'] = $contact_detail['userId']; // User ID
                error_log('SendPulse: Set contact ID to ' . $contact_detail['id']);
                error_log('SendPulse: Set user ID to ' . $contact_detail['userId']);
                
                // Handle PHONE: If we matched on email only, need to add phone
                if ($search_result['has_email'] && !$search_result['has_phone'] && !empty($phone)) {
                    error_log('SendPulse: Adding phone to existing contact (matched on email only)');
                    $phone_result = $api->add_phone_to_contact($contact_id, $phone);
                    error_log('SendPulse: Phone add result FULL: ' . json_encode($phone_result));
                    
                    // Extract phone ID from add response - check different possible response structures
                    if ($phone_result) {
                        if (isset($phone_result['data']['id'])) {
                            $_POST['input_28'] = $phone_result['data']['id'];
                            error_log('SendPulse: Set phone ID to ' . $phone_result['data']['id'] . ' (from data.id)');
                        } else if (isset($phone_result['data'][0]['id'])) {
                            $_POST['input_28'] = $phone_result['data'][0]['id'];
                            error_log('SendPulse: Set phone ID to ' . $phone_result['data'][0]['id'] . ' (from data[0].id)');
                        } else if (isset($phone_result['id'])) {
                            $_POST['input_28'] = $phone_result['id'];
                            error_log('SendPulse: Set phone ID to ' . $phone_result['id'] . ' (from id)');
                        } else {
                            error_log('SendPulse: Phone ID not found in standard locations, checking data structure...');
                            error_log('SendPulse: data keys: ' . (isset($phone_result['data']) ? implode(', ', array_keys($phone_result['data'])) : 'no data key'));
                            
                            // Fallback: re-fetch contact to get phone ID
                            error_log('SendPulse: Re-fetching contact to get phone ID');
                            $updated_contact = $api->get_contact($contact_id);
                            if ($updated_contact && isset($updated_contact['data']['phones'])) {
                                $phone_clean = preg_replace('/[^0-9]/', '', $phone);
                                if (strlen($phone_clean) === 10) {
                                    $phone_clean = '1' . $phone_clean;
                                }
                                foreach ($updated_contact['data']['phones'] as $phone_item) {
                                    if ($phone_item['phone'] === $phone_clean) {
                                        $_POST['input_28'] = $phone_item['id'];
                                        error_log('SendPulse: Set phone ID to ' . $phone_item['id'] . ' (from re-fetch)');
                                        break;
                                    }
                                }
                            }
                        }
                    }
                } else if (isset($contact_detail['phones']) && !empty($contact_detail['phones'])) {
                    // Phone already exists, get ID from contact
                    $_POST['input_28'] = $contact_detail['phones'][0]['id'];
                    error_log('SendPulse: Set phone ID to ' . $contact_detail['phones'][0]['id'] . ' (from existing contact)');
                }
                
                // Handle EMAIL: If we matched on phone only, need to add email
                if ($search_result['has_phone'] && !$search_result['has_email'] && !empty($email)) {
                    error_log('SendPulse: Adding email to existing contact (matched on phone only)');
                    $email_result = $api->add_email_to_contact($contact_id, $email);
                    error_log('SendPulse: Email add response: ' . json_encode($email_result));
                    
                    // Extract email ID from add response - check different possible response structures
                    if ($email_result) {
                        if (isset($email_result['data']['id'])) {
                            $_POST['input_29'] = $email_result['data']['id'];
                            error_log('SendPulse: Set email ID to ' . $email_result['data']['id'] . ' (from data.id)');
                        } else if (isset($email_result['data'][0]['id'])) {
                            $_POST['input_29'] = $email_result['data'][0]['id'];
                            error_log('SendPulse: Set email ID to ' . $email_result['data'][0]['id'] . ' (from data[0].id)');
                        } else if (isset($email_result['id'])) {
                            $_POST['input_29'] = $email_result['id'];
                            error_log('SendPulse: Set email ID to ' . $email_result['id'] . ' (from id)');
                        } else {
                            // Fallback: re-fetch contact to get email ID
                            error_log('SendPulse: Email ID not in add response, re-fetching contact');
                            $updated_contact = $api->get_contact($contact_id);
                            if ($updated_contact && isset($updated_contact['data']['emails'])) {
                                foreach ($updated_contact['data']['emails'] as $email_item) {
                                    if ($email_item['email'] === $email) {
                                        $_POST['input_29'] = $email_item['id'];
                                        error_log('SendPulse: Set email ID to ' . $email_item['id'] . ' (from re-fetch)');
                                        break;
                                    }
                                }
                            }
                        }
                    }
                } else if (isset($contact_detail['emails']) && !empty($contact_detail['emails'])) {
                    // Email already exists (we matched on it), get ID from contact
                    $_POST['input_29'] = $contact_detail['emails'][0]['id'];
                    error_log('SendPulse: Set email ID to ' . $contact_detail['emails'][0]['id'] . ' (from existing contact)');
                }
            }
            
            return;
        }
        
        // Contact doesn't exist - create new
        error_log('SendPulse: Creating new contact');
        $result = $api->create_contact($contact_data);
        
        if ($result === false) {
            error_log('SendPulse: Failed to create contact');
            return;
        }
        
        error_log('SendPulse: Contact created successfully');
        
        // Extract IDs from response and update POST data so they're saved to the entry
        if (isset($result['data'])) {
            $data = $result['data'];
            
            // Populate Gravity Forms fields with SendPulse IDs
            if (isset($data['id'])) {
                $_POST['input_26'] = $data['id']; // SendPulse Contact ID
                error_log('SendPulse: Set contact ID to ' . $data['id']);
            }
            
            if (isset($data['userId'])) {
                $_POST['input_27'] = $data['userId']; // SendPulse User ID
                error_log('SendPulse: Set user ID to ' . $data['userId']);
            }
            
            if (isset($data['phones'][0]['id'])) {
                $_POST['input_28'] = $data['phones'][0]['id']; // SendPulse Phone ID
                error_log('SendPulse: Set phone ID to ' . $data['phones'][0]['id']);
            }
            
            if (isset($data['emails'][0]['id'])) {
                $_POST['input_29'] = $data['emails'][0]['id']; // SendPulse Email ID
                error_log('SendPulse: Set email ID to ' . $data['emails'][0]['id']);
            }
        }
    }
}