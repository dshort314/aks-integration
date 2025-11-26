<?php
/**
 * DocuSeal Integration Handler
 * Handles form submissions and creates DocuSeal documents
 */

class AKS_DocuSeal_Integration {
    
    private $option_name = 'aks_docuseal_html_template';
    
    public function __construct() {
        // Hook into Gravity Forms submission for form 3
        add_action('gform_after_submission_3', array($this, 'send_to_docuseal'), 10, 2);
        
        // Allow custom DocuSeal tags in TinyMCE
        add_filter('tiny_mce_before_init', array($this, 'allow_custom_tags'));
    }
    
    /**
     * Get redirect URL based on parent/guardian status
     * Hardcoded for test environment - update manually when moving to production
     * 
     * @param bool $is_parent Whether user is parent/guardian
     * @return string Complete redirect URL
     */
    private function get_redirect_url($is_parent) {
        if ($is_parent) {
            // Parent/guardian redirects to /account/
            return 'https://allknoxswim.com/test/account/';
        } else {
            // Non-parent redirects to homepage
            return 'https://allknoxswim.com/test/';
        }
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
     * Get default template (same as admin class)
     */
    private function get_default_template() {
        return '<h1>All Knox Swim, LLC</h1>
<h2>Service Agreement</h2>
<p>As a participant in the swim lesson program of All Knox Swim, LLC, including its owners, instructors, employees, and agents, I recognize and acknowledge that there are certain risks of physical injury, and I agree to assume the full risk of any injuries, damages, or loss which I may sustain as a result of participating in any manner, in any and all activities connected with or associated with such program. I further recognize and acknowledge that all activities involving competitive or recreational swimming in a pool environment involve strenuous exertions of strength using various muscle groups, and are hazardous, regardless of the care taken by All Knox Swim, LLC, and I, willingly, and knowingly assume full responsibility for the risk of bodily injury, death or property damage due to negligence of All Knox Swim, LLC or otherwise while participating in swim lesson program activities or while on pool or other premises used by the program.</p>
<p>I acknowledge I am responsible for dressing myself and all family members appropriately for swim lessons. I am responsible for my health and my family members\' health and consulting with a physician for any concerns.</p>
<h3>Medical Conditions</h3>
<p>In addition, I do hereby fully release and discharge All Knox Swim, LLC from any and all claims from injuries, damages, or loss, which I may have or which may accrue to me on account of my participation in the swim lesson program. I understand that the swim lesson instructors and supervisory personnel have difficult jobs to perform. They seek cooperation and understanding from all participants, which will help ensure that the swim lesson programs are conducted in a safe manner. I will assist the instructors and supervisory personnel in supervising the participants by being watchful for unsafe behavior, promptly reporting such behavior to an instructor or supervisory personnel, and personally refrain from such behavior.</p>
<p>All Knox Swim instructors have been advised of conducting the swim lesson programs in a safe manner, and it is expected that all participants will obey the safety rules and proper behavior. I am aware that any participant who does not conform to such rules and behaviors may be asked to leave the program.</p>
<h3>Communicable Diseases</h3>
<p>While All Knox Swim, LLC takes all reasonable precautions, I acknowledge that being around others involves a certain degree of risk of exposure to infectious and communicable diseases including but not limited to COVID-19, influenza, MRSA, and other diseases, viruses, or bacteria. By signing below, I acknowledge, and fully assume the risk of illness or other health related issues that might result from either me or my child(ren) or ward(s) participating in the services of All Knox Swim, LLC.</p>
<h3>Photos</h3>
<p>I give permission for All Knox Swim, LLC to use, without limitation or obligation, photograph/s, film footage, or tape recordings, which may include myself and/or family member\'s image or voice for purposes of promotion or interpreting All Knox Swim, LLC programs.</p>
<h3>Payment and Cancellation Policy</h3>
<p>Payment for swim lessons is due at registration. No student will be allowed to participate in swim lessons if swim lesson fees are outstanding. I understand swim lesson tuition is non-refundable. I understand I cannot transfer nor credit my swim lessons to another person. I understand that if All Knox Swim has to cancel lessons for reasons that they can control (such as instructor illness), then I will be offered a make-up lesson. All Knox Swim is not responsible if lessons have to be cancelled for reasons out of their control (including but not limited to inclement weather, unsafe pool conditions, utility loss, pandemic restrictions, etc.). If the student decides not to attend a scheduled lesson, there is no credit nor refund and All Knox Swim, LLC is not obligated to find another lesson time.</p>
<p>I have read and fully understand the above program details and waiver and release all claims.</p>
<radio-field options="I agree to the service agreement." style="font-size: 20px; width: 360px; height: 25px; display: inline-block;"></radio-field>
<h2>Consent and Liability Waiver for Participation in All Knox Swim, LLC Activities</h2>
<p>By agreeing below, I understand and acknowledge that swimming and water activities have inherent risks, including but not limited to, personal injury, disability, and drowning. I agree to follow all instructions and safety guidelines provided by All Knox Swim, LLC staff.</p>
<p>I understand that while All Knox Swim, LLC staff are trained in water safety and rescue techniques, there are still risks that cannot be eliminated.</p>
<p>I also release and hold All Knox Swim, LLC harmless from any liability if injury, harm, or damages occur to me or my child/ward while going to or from or while participating in any All Knox Swim, LLC activities, unless such injury is due to gross negligence or willful misconduct by All Knox Swim, LLC or its staff.</p>
<radio-field options="I agree to the Consent and Liability Waiver" style="font-size: 20px; width: 460px; height: 25px; display: inline-block;"></radio-field>
<h4>If any parts of these Agreements, Waivers, or Releases are found to be invalid, illegal, or unenforceable the rest of these Agreements, Waivers, and Releases will still be enforceable.</h4>
<radio-field options=" I agree" style="font-size: 18px; width: 160px; height: 25px; display: inline-block;"></radio-field>
<p>These Agreements, Waivers, and Releases apply to ALL activities with All Knox Swim, LLC.</p>
<p>List all Children/Wards that are in your account:</p>
<p>Student Names and Birthdays:<br />STUDENT-LOOP</p>
<p>Name of account owner: ACCOUNT-OWNER</p>
<p>Account email: ACCOUNT-EMAIL</p>
<signature-field style="width: 250px; height: 120px; display: inline-block;"></signature-field>';
    }
    
    /**
     * Get guardian template (for when registrant is NOT the parent/guardian)
     */
    private function get_guardian_template() {
        return '<h1>All Knox Swim, LLC</h1>
<h2>Service Agreement</h2>
<p>As a participant in the swim lesson program of All Knox Swim, LLC, including its owners, instructors, employees, and agents, I recognize and acknowledge that there are certain risks of physical injury, and I agree to assume the full risk of any injuries, damages, or loss which I may sustain as a result of participating in any manner, in any and all activities connected with or associated with such program. I further recognize and acknowledge that all activities involving competitive or recreational swimming in a pool environment involve strenuous exertions of strength using various muscle groups, and are hazardous, regardless of the care taken by All Knox Swim, LLC, and I, willingly, and knowingly assume full responsibility for the risk of bodily injury, death or property damage due to negligence of All Knox Swim, LLC or otherwise while participating in swim lesson program activities or while on pool or other premises used by the program.</p>
<p>I acknowledge I am responsible for dressing myself and all family members appropriately for swim lessons. I am responsible for my health and my family members\' health and consulting with a physician for any concerns.</p>
<h3>Medical Conditions</h3>
<p>In addition, I do hereby fully release and discharge All Knox Swim, LLC from any and all claims from injuries, damages, or loss, which I may have or which may accrue to me on account of my participation in the swim lesson program. I understand that the swim lesson instructors and supervisory personnel have difficult jobs to perform. They seek cooperation and understanding from all participants, which will help ensure that the swim lesson programs are conducted in a safe manner. I will assist the instructors and supervisory personnel in supervising the participants by being watchful for unsafe behavior, promptly reporting such behavior to an instructor or supervisory personnel, and personally refrain from such behavior.</p>
<p>All Knox Swim instructors have been advised of conducting the swim lesson programs in a safe manner, and it is expected that all participants will obey the safety rules and proper behavior. I am aware that any participant who does not conform to such rules and behaviors may be asked to leave the program.</p>
<h3>Communicable Diseases</h3>
<p>While All Knox Swim, LLC takes all reasonable precautions, I acknowledge that being around others involves a certain degree of risk of exposure to infectious and communicable diseases including but not limited to COVID-19, influenza, MRSA, and other diseases, viruses, or bacteria. By signing below, I acknowledge, and fully assume the risk of illness or other health related issues that might result from either me or my child(ren) or ward(s) participating in the services of All Knox Swim, LLC.</p>
<h3>Photos</h3>
<p>I give permission for All Knox Swim, LLC to use, without limitation or obligation, photograph/s, film footage, or tape recordings, which may include myself and/or family member\'s image or voice for purposes of promotion or interpreting All Knox Swim, LLC programs.</p>
<h3>Payment and Cancellation Policy</h3>
<p>Payment for swim lessons is due at registration. No student will be allowed to participate in swim lessons if swim lesson fees are outstanding. I understand swim lesson tuition is non-refundable. I understand I cannot transfer nor credit my swim lessons to another person. I understand that if All Knox Swim has to cancel lessons for reasons that they can control (such as instructor illness), then I will be offered a make-up lesson. All Knox Swim is not responsible if lessons have to be cancelled for reasons out of their control (including but not limited to inclement weather, unsafe pool conditions, utility loss, pandemic restrictions, etc.). If the student decides not to attend a scheduled lesson, there is no credit nor refund and All Knox Swim, LLC is not obligated to find another lesson time.</p>
<p>I have read and fully understand the above program details and waiver and release all claims.</p>
<radio-field options="I agree to the service agreement." style="font-size: 20px; width: 360px; height: 25px; display: inline-block;"></radio-field>
<h2>Consent and Liability Waiver for Participation in All Knox Swim, LLC Activities</h2>
<p>By agreeing below, I understand and acknowledge that swimming and water activities have inherent risks, including but not limited to, personal injury, disability, and drowning. I agree to follow all instructions and safety guidelines provided by All Knox Swim, LLC staff.</p>
<p>I understand that while All Knox Swim, LLC staff are trained in water safety and rescue techniques, there are still risks that cannot be eliminated.</p>
<p>I also release and hold All Knox Swim, LLC harmless from any liability if injury, harm, or damages occur to me or my child/ward while going to or from or while participating in any All Knox Swim, LLC activities, unless such injury is due to gross negligence or willful misconduct by All Knox Swim, LLC or its staff.</p>
<radio-field options="I agree to the Consent and Liability Waiver" style="font-size: 20px; width: 460px; height: 25px; display: inline-block;"></radio-field>
<h4>If any parts of these Agreements, Waivers, or Releases are found to be invalid, illegal, or unenforceable the rest of these Agreements, Waivers, and Releases will still be enforceable.</h4>
<radio-field options=" I agree" style="font-size: 18px; width: 160px; height: 25px; display: inline-block;"></radio-field>
<p>These Agreements, Waivers, and Releases apply to ALL activities with All Knox Swim, LLC.</p>
<p>List all Children/Wards that are in your account:</p>
<p>Student Names and Birthdays:<br /><text-field style="width: 250px; height: 120px; display: inline-block;"></text-field></p>
<p>Name of account owner: ACCOUNT-OWNER</p>
<p>Account email: ACCOUNT-EMAIL</p>
<p>Parent/Guardian\'s Name: <text-field style="width: 250px; height:50px; display: inline-block;"></text-field><br />
Parent/Guardian\'s Email: PARENT-EMAIL</p>
<signature-field style="width: 250px; height: 120px; display: inline-block;"></signature-field>';
    }
    
    /**
     * Send to DocuSeal on form submission
     */
    public function send_to_docuseal($entry, $form) {
        try {
            // Get API token from settings
            $settings = get_option('aks_docuseal_settings');
            $api_token = isset($settings['api_token']) ? $settings['api_token'] : '';
            
            if (empty($api_token)) {
                error_log('DocuSeal: API token not configured in settings');
                return;
            }
            
            // Get parent form data
            $first_name = rgar($entry, '27');
            $last_name = rgar($entry, '28');
            $email = rgar($entry, '29');
            
            // Combine first and last name
            $account_owner = trim($first_name . ' ' . $last_name);
            
            // Get user ID from email
            $user = get_user_by('email', $email);
            $user_id = $user ? $user->ID : 0;
            
            // Get parent/guardian fields from form
            $is_parent_guardian = rgar($entry, '18'); // Radio: Yes/No
            $guardian_first_name = rgar($entry, '19.3'); // Guardian First Name
            $guardian_last_name = rgar($entry, '19.6'); // Guardian Last Name
            $guardian_email = rgar($entry, '20'); // Guardian Email
            
            // Combine guardian name
            $guardian_full_name = trim($guardian_first_name . ' ' . $guardian_last_name);
            
            // Update shipping address from form data if user exists
            if ($user_id) {
                $this->update_user_shipping_address($user_id, $entry);
                
                // Set Registration Form 2 Complete to "Yes"
                update_user_meta($user_id, 'sr_registration_form_complete', 'yes');
                
                // Update parent/guardian fields
                if (!empty($is_parent_guardian)) {
                    // Convert "Yes"/"No" to "yes"/"no" for consistency
                    $is_parent_value = ($is_parent_guardian === 'Yes') ? 'yes' : 'no';
                    update_user_meta($user_id, 'sr_is_parent_guardian', $is_parent_value);
                }
                
                if (!empty($guardian_email)) {
                    update_user_meta($user_id, 'sr_guardian_email', sanitize_email($guardian_email));
                }
                
                if (!empty($guardian_full_name)) {
                    update_user_meta($user_id, 'sr_guardian_name', sanitize_text_field($guardian_full_name));
                }
            }
            
            // Get nested form entries (Students) from field 21
            $student_list = '';
            
            // Step 1: Get the nested entry IDs from field 21 (comma-separated string)
            $child_entry_ids_string = rgar($entry, '21');
            
            if (!empty($child_entry_ids_string)) {
                // Step 2: Parse the comma-separated string into an array
                $child_entry_ids = explode(',', $child_entry_ids_string);
                
                // Step 3: Retrieve each child entry
                $child_entries = array();
                foreach ($child_entry_ids as $child_entry_id) {
                    $child_entries[] = GFAPI::get_entry($child_entry_id);
                }
                
                // Step 4: Process and build the student list
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
            
            // Determine if parent/guardian and set template accordingly
            $is_parent = ($is_parent_guardian === 'Yes');
            
            // Get HTML template from settings
            if (!$is_parent && !empty($guardian_email)) {
                $html_template = $this->get_guardian_template();
                $send_to_email = $guardian_email;
                $template_name_suffix = 'Guardian';
            } else {
                $html_template = get_option($this->option_name, $this->get_default_template());
                $send_to_email = $email;
                $template_name_suffix = 'Initial';
            }
            
            // Replace placeholders
            $html_template = str_replace('STUDENT-LOOP', $student_list, $html_template);
            $html_template = str_replace('ACCOUNT-OWNER', $account_owner, $html_template);
            $html_template = str_replace('ACCOUNT-EMAIL', $email, $html_template);
            $html_template = str_replace('PARENT-EMAIL', !empty($guardian_email) ? $guardian_email : '', $html_template);
            $html_template = str_replace('PARENT-NAME', !empty($guardian_full_name) ? $guardian_full_name : '', $html_template);
            
            // Prepare the payload
            $payload = array(
                'html' => $html_template,
                'folder_name' => 'Unsigned Templates',
                'name' => $email . ' ' . $account_owner . ' ' . $template_name_suffix
            );
            
            // Initialize cURL
            $curl = curl_init();
            
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api.docuseal.com/templates/html',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'X-Auth-Token: ' . $api_token
                ),
            ));
            
            $response = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($curl);
            
            curl_close($curl);
            
            // Log the response for debugging
            if ($http_code >= 200 && $http_code < 300) {
                error_log('DocuSeal API Success: ' . $response);
                
                // Decode the response to get the template ID
                $response_data = json_decode($response, true);
                
                if (isset($response_data['id'])) {
                    $template_id = $response_data['id'];
                    
                    // Get the redirect URL based on parent/guardian status
                    $redirect_url = $this->get_redirect_url($is_parent);
                    
                    // Create a submission to send the document for signing
                    $submission_payload = array(
                        'template_id' => $template_id,
                        'send_email' => true,
                        'completed_redirect_url' => $redirect_url,
                        'submitters' => array(
                            array(
                                'role' => 'First Party',
                                'email' => $send_to_email,
                                'name' => $account_owner
                            )
                        )
                    );
                    
                    error_log('DocuSeal: Creating submission with redirect URL: ' . $redirect_url);
                    
                    // Initialize new cURL request for submission
                    $curl_submission = curl_init();
                    
                    curl_setopt_array($curl_submission, array(
                        CURLOPT_URL => 'https://api.docuseal.com/submissions',
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING => '',
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST => 'POST',
                        CURLOPT_POSTFIELDS => json_encode($submission_payload),
                        CURLOPT_HTTPHEADER => array(
                            'Content-Type: application/json',
                            'X-Auth-Token: ' . $api_token
                        ),
                    ));
                    
                    $submission_response = curl_exec($curl_submission);
                    $submission_http_code = curl_getinfo($curl_submission, CURLINFO_HTTP_CODE);
                    $submission_curl_error = curl_error($curl_submission);
                    
                    curl_close($curl_submission);
                    
                    // Log submission response
                    if ($submission_http_code >= 200 && $submission_http_code < 300) {
                        // Decode submission response
                        $submission_data = json_decode($submission_response, true);

                        // Validate structure and extract embed_src
                        if (
                            $user_id &&
                            is_array($submission_data) &&
                            isset($submission_data[0]['embed_src'])
                        ) {
                            $embed_src = $submission_data[0]['embed_src'];

                            // Save embed_src to user meta
                            update_user_meta($user_id, 'docuseal_url', esc_url_raw($embed_src));
                        }

                    } else {
                        // Error logging for failed HTTP codes
                        error_log('DocuSeal Submission Error: HTTP ' . $submission_http_code . ' - ' . $submission_response);

                        if ($submission_curl_error) {
                            error_log('cURL Submission Error: ' . $submission_curl_error);
                        }
                    }

                    
                    // Store submission response in entry meta
                    gform_update_meta($entry['id'], 'docuseal_submission_response', $submission_response);
                    gform_update_meta($entry['id'], 'docuseal_submission_http_code', $submission_http_code);
                }
            } else {
                error_log('DocuSeal API Error: HTTP ' . $http_code . ' - ' . $response);
                if ($curl_error) {
                    error_log('cURL Error: ' . $curl_error);
                }
            }
            
            // Optional: Store the response in entry meta
            gform_update_meta($entry['id'], 'docuseal_response', $response);
            gform_update_meta($entry['id'], 'docuseal_http_code', $http_code);
            
        } catch (Exception $e) {
            error_log('DocuSeal Integration Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Update user's shipping address from form entry
     * 
     * @param int $user_id WordPress user ID
     * @param array $entry Gravity Forms entry
     */
    private function update_user_shipping_address($user_id, $entry) {
        // Get shipping address fields from form 3
        $shipping_phone = rgar($entry, '16');      // Shipping Phone
        $shipping_email = rgar($entry, '17');      // Shipping Email
        $shipping_address_1 = rgar($entry, '15.1'); // Street Address
        $shipping_address_2 = rgar($entry, '15.2'); // Address Line 2
        $shipping_city = rgar($entry, '15.3');      // City
        $shipping_state = rgar($entry, '15.4');     // State/Province
        $shipping_postcode = rgar($entry, '15.5');  // ZIP/Postal Code
        $shipping_country = rgar($entry, '15.6');   // Country
        
        // Only update if we have at least the main address field
        if (!empty($shipping_address_1)) {
            // Update WooCommerce shipping address fields
            update_user_meta($user_id, 'shipping_address_1', sanitize_text_field($shipping_address_1));
            
            if (!empty($shipping_address_2)) {
                update_user_meta($user_id, 'shipping_address_2', sanitize_text_field($shipping_address_2));
            }
            
            if (!empty($shipping_city)) {
                update_user_meta($user_id, 'shipping_city', sanitize_text_field($shipping_city));
            }
            
            if (!empty($shipping_state)) {
                update_user_meta($user_id, 'shipping_state', sanitize_text_field($shipping_state));
            }
            
            if (!empty($shipping_postcode)) {
                update_user_meta($user_id, 'shipping_postcode', sanitize_text_field($shipping_postcode));
            }
            
            if (!empty($shipping_country)) {
                update_user_meta($user_id, 'shipping_country', sanitize_text_field($shipping_country));
            }
            
            if (!empty($shipping_phone)) {
                update_user_meta($user_id, 'shipping_phone', sanitize_text_field($shipping_phone));
            }
            
            if (!empty($shipping_email)) {
                update_user_meta($user_id, 'shipping_email', sanitize_email($shipping_email));
            }
        } else {
            error_log('DocuSeal: No shipping address found in form entry');
        }
    }
}