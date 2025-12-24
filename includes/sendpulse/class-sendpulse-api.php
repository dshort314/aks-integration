<?php
/**
 * SendPulse API Client
 * Handles communication with SendPulse CRM API
 */

class AKS_SendPulse_API {
    
    private $api_id;
    private $api_secret;
    private $access_token;
    private $token_expires;
    private $api_base_url = 'https://api.sendpulse.com';
    
    public function __construct($api_id, $api_secret) {
        $this->api_id = $api_id;
        $this->api_secret = $api_secret;
    }
    
    /**
     * Get access token from SendPulse
     * @return string|false Access token or false on failure
     */
    private function get_access_token() {
        // Check if we have a valid cached token
        $cached_token = get_transient('aks_sendpulse_access_token');
        if ($cached_token !== false) {
            return $cached_token;
        }
        
        // Request new token
        $url = $this->api_base_url . '/oauth/access_token';
        
        $body = array(
            'grant_type' => 'client_credentials',
            'client_id' => $this->api_id,
            'client_secret' => $this->api_secret
        );
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            return false;
        }
        
        $data = json_decode($response_body, true);
        
        if (!isset($data['access_token'])) {
            return false;
        }
        
        // Cache the token (expires in 1 hour, cache for 55 minutes to be safe)
        set_transient('aks_sendpulse_access_token', $data['access_token'], 55 * MINUTE_IN_SECONDS);
        
        return $data['access_token'];
    }
    
    /**
     * Search for existing contact by email or phone
     * 
     * @param string $email Email to search
     * @param string $phone Phone to search
     * @return array ['exists' => bool, 'contact_id' => int|null, 'has_email' => bool, 'has_phone' => bool]
     */
    public function search_contact($email = '', $phone = '') {
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return array('exists' => false, 'contact_id' => null, 'has_email' => false, 'has_phone' => false);
        }
        
        $url = $this->api_base_url . '/crm/v1/contacts/get-list';
        
        // Search with both email AND phone first
        if (!empty($email) && !empty($phone)) {
            $phone_clean = preg_replace('/[^0-9]/', '', $phone);
            // Get last 10 digits for search (to match regardless of country code)
            if (strlen($phone_clean) > 10) {
                $phone_clean = substr($phone_clean, -10);
            }
            
            $body = array(
                'email' => $email,
                'phone' => $phone_clean,
                'limit' => 1,
                'offset' => 0
            );
            
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $access_token
                ),
                'body' => json_encode($body),
                'timeout' => 30
            ));
            
            if (!is_wp_error($response)) {
                $response_body = wp_remote_retrieve_body($response);
                $data = json_decode($response_body, true);
                
                if (isset($data['data']['total']) && $data['data']['total'] > 0) {
                    $contact_id = $data['data']['list'][0]['id'];
                    return array('exists' => true, 'contact_id' => $contact_id, 'has_email' => true, 'has_phone' => true);
                }
            }
        }
        
        // Search by email only
        if (!empty($email)) {
            $body = array(
                'email' => $email,
                'isEmailFullSearch' => false,
                'limit' => 1,
                'offset' => 0
            );
            
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $access_token
                ),
                'body' => json_encode($body),
                'timeout' => 30
            ));
            
            if (!is_wp_error($response)) {
                $response_body = wp_remote_retrieve_body($response);
                $data = json_decode($response_body, true);
                
                if (isset($data['data']['total']) && $data['data']['total'] > 0) {
                    $contact_id = $data['data']['list'][0]['id'];
                    return array('exists' => true, 'contact_id' => $contact_id, 'has_email' => true, 'has_phone' => false);
                }
            }
        }
        
        // Search by phone only
        if (!empty($phone)) {
            $phone_clean = preg_replace('/[^0-9]/', '', $phone);
            // Get last 10 digits for search (to match regardless of country code)
            if (strlen($phone_clean) > 10) {
                $phone_clean = substr($phone_clean, -10);
            }
            
            $body = array(
                'phone' => $phone_clean,
                'limit' => 1,
                'offset' => 0
            );
            
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $access_token
                ),
                'body' => json_encode($body),
                'timeout' => 30
            ));
            
            if (!is_wp_error($response)) {
                $response_body = wp_remote_retrieve_body($response);
                $data = json_decode($response_body, true);
                
                if (isset($data['data']['total']) && $data['data']['total'] > 0) {
                    $contact_id = $data['data']['list'][0]['id'];
                    return array('exists' => true, 'contact_id' => $contact_id, 'has_email' => false, 'has_phone' => true);
                }
            }
        }
        
        return array('exists' => false, 'contact_id' => null, 'has_email' => false, 'has_phone' => false);
    }
    
    /**
     * Get contact details by ID
     * 
     * @param int $contact_id Contact ID
     * @return array|false Contact data or false on failure
     */
    public function get_contact($contact_id) {
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return false;
        }
        
        $url = $this->api_base_url . '/crm/v1/contacts/' . $contact_id;
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        return $data;
    }
    
    /**
     * Update contact first and last name
     * 
     * @param int $contact_id Contact ID
     * @param string $first_name First name
     * @param string $last_name Last name
     * @return array|false Response data or false on failure
     */
    public function update_contact_name($contact_id, $first_name, $last_name) {
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return false;
        }
        
        $url = $this->api_base_url . '/crm/v1/contacts/' . $contact_id;
        
        $body = array(
            'responsibleId' => 0,
            'firstName' => $first_name,
            'lastName' => $last_name
        );
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token
            ),
        ));
        
        $response_body = curl_exec($curl);
        $response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);
        
        if ($curl_error) {
            return false;
        }
        
        if ($response_code !== 200) {
            return false;
        }
        
        $data = json_decode($response_body, true);
        
        return $data;
    }
    
    /**
     * Add phone to existing contact
     * 
     * @param int $contact_id Contact ID
     * @param string $phone Phone number to add
     * @return array|false Response data or false on failure
     */
    public function add_phone_to_contact($contact_id, $phone) {
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return false;
        }
        
        $url = $this->api_base_url . '/crm/v1/contacts/' . $contact_id . '/phones';
        
        $phone_clean = preg_replace('/[^0-9]/', '', $phone);
        // Add "1" prefix for US numbers
        if (strlen($phone_clean) === 10) {
            $phone_clean = '1' . $phone_clean;
        }
        
        $body = array(
            'phone' => $phone_clean
        );
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200 && $response_code !== 201) {
            return false;
        }
        
        $data = json_decode($response_body, true);
        
        return $data;
    }
    
    /**
     * Add email to existing contact
     * 
     * @param int $contact_id Contact ID
     * @param string $email Email address to add
     * @return array|false Response data or false on failure
     */
    public function add_email_to_contact($contact_id, $email) {
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return false;
        }
        
        $url = $this->api_base_url . '/crm/v1/contacts/' . $contact_id . '/emails';
        
        $body = array(
            'emails' => array(
                array(
                    'email' => $email,
                    'isMain' => false
                )
            )
        );
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200 && $response_code !== 201) {
            return false;
        }
        
        $data = json_decode($response_body, true);
        
        return $data;
    }
    
    /**
     * Create a contact in SendPulse CRM
     * 
     * @param array $contact_data Contact information
     * @return array|false Response data or false on failure
     */
    public function create_contact($contact_data) {
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return false;
        }
        
        $url = $this->api_base_url . '/crm/v1/contacts/';
        
        // Prepare the contact data according to SendPulse API format
        $body = array();
        
        if (!empty($contact_data['firstName'])) {
            $body['firstName'] = $contact_data['firstName'];
        }
        
        if (!empty($contact_data['lastName'])) {
            $body['lastName'] = $contact_data['lastName'];
        }
        
        if (!empty($contact_data['email'])) {
            $body['emails'] = array($contact_data['email']);
        }
        
        if (!empty($contact_data['phone'])) {
            // Remove any non-numeric characters
            $phone = preg_replace('/[^0-9]/', '', $contact_data['phone']);
            // Add "1" prefix for US numbers
            if (strlen($phone) === 10) {
                $phone = '1' . $phone;
            }
            $body['phones'] = array($phone);
        }
        
        // Make the API request
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if ($response_code !== 200 && $response_code !== 201) {
            return false;
        }
        
        return $data;
    }
    
    /**
     * Add contact to email mailing list (addressbook)
     * 
     * @param string $email Email address
     * @param int $list_id Mailing list (addressbook) ID
     * @return array|false Response data or false on failure
     */
    public function add_to_mailing_list($email, $list_id) {
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return false;
        }
        
        $url = $this->api_base_url . '/addressbooks/' . $list_id . '/emails';
        
        $body = array(
            'emails' => array($email)
        );
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200 && $response_code !== 201) {
            return false;
        }
        
        $data = json_decode($response_body, true);
        
        return $data;
    }
    
    /**
     * Add tag to contact
     * 
     * @param int $contact_id Contact ID
     * @param int $tag_id Tag ID (55139 for "SMS opted-in")
     * @return array|false Response data or false on failure
     */
    public function add_tag_to_contact($contact_id, $tag_id) {
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return false;
        }
        
        $url = $this->api_base_url . '/crm/v1/contact-tags/' . $tag_id . '/contact/' . $contact_id;
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200 && $response_code !== 201) {
            return false;
        }
        
        $data = json_decode($response_body, true);
        
        return $data;
    }
    
    /**
     * Add note/comment to contact
     * 
     * @param int $contact_id Contact ID
     * @param string $message Note message
     * @return array|false Response data or false on failure
     */
    public function add_note_to_contact($contact_id, $message) {
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return false;
        }
        
        $url = $this->api_base_url . '/crm/v1/contacts/' . $contact_id . '/comments';
        
        $body = array(
            'message' => $message
        );
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200 && $response_code !== 201) {
            return false;
        }
        
        $data = json_decode($response_body, true);
        
        return $data;
    }
    
    /**
     * Add tags to contact (legacy method - kept for compatibility)
     * 
     * @param int $contact_id Contact ID
     * @param array $tags Array of tag names (ignored, uses fixed tag ID)
     * @return array|false Response data or false on failure
     */
    public function add_tags($contact_id, $tags) {
        // Use the specific tag ID for "SMS opted-in" (55139)
        return $this->add_tag_to_contact($contact_id, 55139);
    }
    
    /**
     * Test API connection
     * 
     * @return bool True if connection is successful
     */
    public function test_connection() {
        $access_token = $this->get_access_token();
        return $access_token !== false;
    }
}