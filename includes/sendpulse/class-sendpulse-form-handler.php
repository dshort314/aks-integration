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
        
        // Create contact in SendPulse
        $this->create_sendpulse_contact($contact_data);
        
        // Create or update contact in Quo
        $this->create_quo_contact($contact_data);
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
     * Create contact in SendPulse CRM
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
            
            // Contact exists with both email and phone - skip
            if ($search_result['has_email'] && $search_result['has_phone']) {
                error_log('SendPulse: Contact already exists with both email and phone, skipping');
                return;
            }
            
            // Contact has email but not this phone - add phone
            if ($search_result['has_email'] && !$search_result['has_phone'] && !empty($phone)) {
                error_log('SendPulse: Adding phone to existing contact ' . $contact_id);
                $api->add_phone_to_contact($contact_id, $phone);
                return;
            }
            
            // Contact has phone but not this email - add email
            if ($search_result['has_phone'] && !$search_result['has_email'] && !empty($email)) {
                error_log('SendPulse: Adding email to existing contact ' . $contact_id);
                $api->add_email_to_contact($contact_id, $email);
                return;
            }
        }
        
        // Contact doesn't exist - create new
        $result = $api->create_contact($contact_data);
        
        if ($result === false) {
            error_log('SendPulse: Failed to create contact');
            return;
        }
        
        error_log('SendPulse: Contact created successfully - ' . json_encode($result));
        
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
}
