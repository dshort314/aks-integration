<?php
/**
 * DocuSeal Integration Handler
 * Handles form submissions and creates DocuSeal documents
 */

class AKS_DocuSeal_Integration {
    
    private $settings;
    
    public function __construct() {
        $this->settings = get_option('aks_docuseal_settings');
        
        // Hook into Gravity Forms submission
        add_action('gform_after_submission', array($this, 'send_to_docuseal'), 10, 2);
        
        // Allow custom DocuSeal tags in TinyMCE
        add_filter('tiny_mce_before_init', array($this, 'allow_custom_tags'));
    }
    
    /**
     * Allow custom DocuSeal tags in TinyMCE
     */
    public function allow_custom_tags($init) {
        // Extend valid elements to include radio-field and signature-field
        $ext = 'radio-field[options|style],signature-field[style]';
        
        if (isset($init['extended_valid_elements'])) {
            $init['extended_valid_elements'] .= ',' . $ext;
        } else {
            $init['extended_valid_elements'] = $ext;
        }
        
        // Don't remove empty tags
        $init['remove_redundant_brs'] = false;
        
        return $init;
    }
    
    /**
     * Send to DocuSeal on form submission
     */
    public function send_to_docuseal($entry, $form) {
        // Check if this form has DocuSeal enabled
        if (empty($this->settings['form_mappings'][$form['id']]['enabled'])) {
            return;
        }
        
        // Get form mapping configuration
        $mapping = $this->settings['form_mappings'][$form['id']];
        
        // Check if API token is configured
        if (empty($this->settings['api_token'])) {
            error_log('DocuSeal: API token not configured');
            return;
        }
        
        try {
            // Get field values based on mapping
            $first_name = rgar($entry, $mapping['first_name_field']);
            $last_name = rgar($entry, $mapping['last_name_field']);
            $email = rgar($entry, $mapping['email_field']);
            
            // Combine first and last name
            $account_owner = trim($first_name . ' ' . $last_name);
            
            // Get nested form entries (Students) if configured
            $student_list = '';
            
            if (!empty($mapping['students_field'])) {
                // Get the nested entry IDs from the mapped field (comma-separated string)
                $child_entry_ids_string = rgar($entry, $mapping['students_field']);
                
                if (!empty($child_entry_ids_string)) {
                    // Parse the comma-separated string into an array
                    $child_entry_ids = explode(',', $child_entry_ids_string);
                    
                    // Retrieve each child entry
                    $child_entries = array();
                    foreach ($child_entry_ids as $child_entry_id) {
                        $child_entries[] = GFAPI::get_entry($child_entry_id);
                    }
                    
                    // Process and build the student list
                    $student_lines = array();
                    foreach ($child_entries as $child_entry) {
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
                    
                    $student_list = implode('<br />', $student_lines);
                }
            }
            
            // Get HTML template from settings
            $html_template = isset($this->settings['html_template']) ? $this->settings['html_template'] : '';
            
            if (empty($html_template)) {
                error_log('DocuSeal: No HTML template configured');
                return;
            }
            
            // Replace placeholders
            $html_template = str_replace('STUDENT-LOOP', $student_list, $html_template);
            $html_template = str_replace('ACCOUNT-OWNER', $account_owner, $html_template);
            $html_template = str_replace('ACCOUNT-EMAIL', $email, $html_template);
            
            // Prepare the payload
            $payload = array(
                'html' => $html_template,
                'folder_name' => 'Unsigned Templates',
                'name' => $email . ' ' . $account_owner . ' Initial'
            );
            
            // Send to DocuSeal API
            $response = wp_remote_post('https://api.docuseal.com/templates/html', array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-Auth-Token' => $this->settings['api_token']
                ),
                'body' => json_encode($payload),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                error_log('DocuSeal API Error: ' . $response->get_error_message());
                return;
            }
            
            $http_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            // Log the response for debugging
            if ($http_code >= 200 && $http_code < 300) {
                error_log('DocuSeal API Success: ' . $response_body);
                
                // Decode the response to get the template ID
                $response_data = json_decode($response_body, true);
                
                if (isset($response_data['id'])) {
                    $template_id = $response_data['id'];
                    
                    // Create a submission to send the document for signing
                    $submission_payload = array(
                        'template_id' => $template_id,
                        'send_email' => true,
                        'submitters' => array(
                            array(
                                'role' => 'First Party',
                                'email' => $email,
                                'name' => $account_owner
                            )
                        )
                    );
                    
                    // Send submission request
                    $submission_response = wp_remote_post('https://api.docuseal.com/submissions', array(
                        'headers' => array(
                            'Content-Type' => 'application/json',
                            'X-Auth-Token' => $this->settings['api_token']
                        ),
                        'body' => json_encode($submission_payload),
                        'timeout' => 30
                    ));
                    
                    if (is_wp_error($submission_response)) {
                        error_log('DocuSeal Submission Error: ' . $submission_response->get_error_message());
                    } else {
                        $submission_http_code = wp_remote_retrieve_response_code($submission_response);
                        $submission_response_body = wp_remote_retrieve_body($submission_response);
                        
                        // Log submission response
                        if ($submission_http_code >= 200 && $submission_http_code < 300) {
                            error_log('DocuSeal Submission Success: ' . $submission_response_body);
                        } else {
                            error_log('DocuSeal Submission Error: HTTP ' . $submission_http_code . ' - ' . $submission_response_body);
                        }
                        
                        // Store submission response in entry meta
                        gform_update_meta($entry['id'], 'docuseal_submission_response', $submission_response_body);
                        gform_update_meta($entry['id'], 'docuseal_submission_http_code', $submission_http_code);
                    }
                }
            } else {
                error_log('DocuSeal API Error: HTTP ' . $http_code . ' - ' . $response_body);
            }
            
            // Store the response in entry meta
            gform_update_meta($entry['id'], 'docuseal_response', $response_body);
            gform_update_meta($entry['id'], 'docuseal_http_code', $http_code);
            
        } catch (Exception $e) {
            error_log('DocuSeal Integration Error: ' . $e->getMessage());
        }
    }
}
