<?php
/**
 * SendPulse API Client
 * Handles communication with SendPulse CRM API
 * Now includes API logging
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
     * Get the API logger instance
     */
    private function get_logger() {
        if (class_exists('AKS_API_Logger')) {
            return AKS_API_Logger::get_instance();
        }
        return null;
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
        
        $logger = $this->get_logger();
        $log_data = null;
        
        if ($logger) {
            $log_data = $logger->log_request(
                'SendPulse',
                $url,
                'POST',
                array('Content-Type' => 'application/json'),
                $body,
                'Get access token'
            );
        }
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            if ($logger && $log_data) {
                $logger->log_response($log_data, 0, array(), $response->get_error_message(), false, $response->get_error_message());
            }
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response)->getAll();
        
        if ($logger && $log_data) {
            $logger->log_response($log_data, $response_code, $response_headers, $response_body, $response_code === 200);
        }
        
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
     * Make an API request with logging
     */
    private function make_request($method, $endpoint, $body = null, $context = '', $user_id = null, $user_email = '') {
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return array('success' => false, 'error' => 'Failed to get access token');
        }
        
        $url = $this->api_base_url . $endpoint;
        $logger = $this->get_logger();
        $log_data = null;
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $access_token
        );
        
        // For logging, mask the token
        $log_headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer [REDACTED]'
        );
        
        if ($logger) {
            $log_data = $logger->log_request(
                'SendPulse',
                $url,
                $method,
                $log_headers,
                $body,
                $context,
                $user_id,
                $user_email
            );
        }
        
        $args = array(
            'headers' => $headers,
            'timeout' => 30
        );
        
        if ($body !== null) {
            $args['body'] = json_encode($body);
        }
        
        switch ($method) {
            case 'GET':
                $response = wp_remote_get($url, $args);
                break;
            case 'POST':
                $response = wp_remote_post($url, $args);
                break;
            case 'PUT':
            case 'DELETE':
                $args['method'] = $method;
                $response = wp_remote_request($url, $args);
                break;
            default:
                $response = wp_remote_post($url, $args);
        }
        
        if (is_wp_error($response)) {
            if ($logger && $log_data) {
                $logger->log_response($log_data, 0, array(), $response->get_error_message(), false, $response->get_error_message());
            }
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response)->getAll();
        $success = ($response_code >= 200 && $response_code < 300);
        
        if ($logger && $log_data) {
            $logger->log_response($log_data, $response_code, $response_headers, $response_body, $success);
        }
        
        return array(
            'success' => $success,
            'code' => $response_code,
            'body' => $response_body,
            'data' => json_decode($response_body, true)
        );
    }
    
    /**
     * Search for existing contact by email or phone
     * 
     * @param string $email Email to search
     * @param string $phone Phone to search
     * @return array ['exists' => bool, 'contact_id' => int|null, 'has_email' => bool, 'has_phone' => bool]
     */
    public function search_contact($email = '', $phone = '') {
        // Search with both email AND phone first
        if (!empty($email) && !empty($phone)) {
            $phone_clean = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($phone_clean) > 10) {
                $phone_clean = substr($phone_clean, -10);
            }
            
            $body = array(
                'email' => $email,
                'phone' => $phone_clean,
                'limit' => 1,
                'offset' => 0
            );
            
            $result = $this->make_request('POST', '/crm/v1/contacts/get-list', $body, 'Search contact by email+phone', null, $email);
            
            if ($result['success'] && isset($result['data']['data']['total']) && $result['data']['data']['total'] > 0) {
                $contact_id = $result['data']['data']['list'][0]['id'];
                return array('exists' => true, 'contact_id' => $contact_id, 'has_email' => true, 'has_phone' => true);
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
            
            $result = $this->make_request('POST', '/crm/v1/contacts/get-list', $body, 'Search contact by email', null, $email);
            
            if ($result['success'] && isset($result['data']['data']['total']) && $result['data']['data']['total'] > 0) {
                $contact_id = $result['data']['data']['list'][0]['id'];
                return array('exists' => true, 'contact_id' => $contact_id, 'has_email' => true, 'has_phone' => false);
            }
        }
        
        // Search by phone only
        if (!empty($phone)) {
            $phone_clean = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($phone_clean) > 10) {
                $phone_clean = substr($phone_clean, -10);
            }
            
            $body = array(
                'phone' => $phone_clean,
                'limit' => 1,
                'offset' => 0
            );
            
            $result = $this->make_request('POST', '/crm/v1/contacts/get-list', $body, 'Search contact by phone');
            
            if ($result['success'] && isset($result['data']['data']['total']) && $result['data']['data']['total'] > 0) {
                $contact_id = $result['data']['data']['list'][0]['id'];
                return array('exists' => true, 'contact_id' => $contact_id, 'has_email' => false, 'has_phone' => true);
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
        $result = $this->make_request('GET', '/crm/v1/contacts/' . $contact_id, null, 'Get contact details');
        
        if ($result['success']) {
            return $result['data'];
        }
        
        return false;
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
        
        $logger = $this->get_logger();
        $log_data = null;
        
        if ($logger) {
            $log_data = $logger->log_request(
                'SendPulse',
                $url,
                'PUT',
                array('Content-Type' => 'application/json', 'Authorization' => 'Bearer [REDACTED]'),
                $body,
                'Update contact name'
            );
        }
        
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
            if ($logger && $log_data) {
                $logger->log_response($log_data, 0, array(), $curl_error, false, $curl_error);
            }
            return false;
        }
        
        $success = ($response_code === 200);
        
        if ($logger && $log_data) {
            $logger->log_response($log_data, $response_code, array(), $response_body, $success);
        }
        
        if (!$success) {
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
        $phone_clean = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone_clean) === 10) {
            $phone_clean = '1' . $phone_clean;
        }
        
        $body = array(
            'phone' => $phone_clean
        );
        
        $result = $this->make_request('POST', '/crm/v1/contacts/' . $contact_id . '/phones', $body, 'Add phone to contact');
        
        if ($result['success']) {
            return $result['data'];
        }
        
        return false;
    }
    
    /**
     * Add email to existing contact
     * 
     * @param int $contact_id Contact ID
     * @param string $email Email address to add
     * @return array|false Response data or false on failure
     */
    public function add_email_to_contact($contact_id, $email) {
        $body = array(
            'emails' => array(
                array(
                    'email' => $email,
                    'isMain' => false
                )
            )
        );
        
        $result = $this->make_request('POST', '/crm/v1/contacts/' . $contact_id . '/emails', $body, 'Add email to contact', null, $email);
        
        if ($result['success']) {
            return $result['data'];
        }
        
        return false;
    }
    
    /**
     * Create a contact in SendPulse CRM
     * 
     * @param array $contact_data Contact information
     * @return array|false Response data or false on failure
     */
    public function create_contact($contact_data) {
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
            $phone = preg_replace('/[^0-9]/', '', $contact_data['phone']);
            if (strlen($phone) === 10) {
                $phone = '1' . $phone;
            }
            $body['phones'] = array($phone);
        }
        
        $email = isset($contact_data['email']) ? $contact_data['email'] : '';
        
        $result = $this->make_request('POST', '/crm/v1/contacts/', $body, 'Create contact', null, $email);
        
        if ($result['success']) {
            return $result['data'];
        }
        
        return false;
    }
    
    /**
     * Add contact to email mailing list (addressbook)
     * 
     * @param string $email Email address
     * @param int $list_id Mailing list (addressbook) ID
     * @return array|false Response data or false on failure
     */
    public function add_to_mailing_list($email, $list_id) {
        $body = array(
            'emails' => array($email)
        );
        
        $result = $this->make_request('POST', '/addressbooks/' . $list_id . '/emails', $body, 'Add to mailing list', null, $email);
        
        if ($result['success']) {
            return $result['data'];
        }
        
        return false;
    }
    
    /**
     * Add tag to contact
     * 
     * @param int $contact_id Contact ID
     * @param int $tag_id Tag ID (55139 for "SMS opted-in")
     * @return array|false Response data or false on failure
     */
    public function add_tag_to_contact($contact_id, $tag_id) {
        $result = $this->make_request('POST', '/crm/v1/contact-tags/' . $tag_id . '/contact/' . $contact_id, null, 'Add tag to contact');
        
        if ($result['success']) {
            return $result['data'];
        }
        
        return false;
    }
    
    /**
     * Add note/comment to contact
     * 
     * @param int $contact_id Contact ID
     * @param string $message Note message
     * @return array|false Response data or false on failure
     */
    public function add_note_to_contact($contact_id, $message) {
        $body = array(
            'message' => $message
        );
        
        $result = $this->make_request('POST', '/crm/v1/contacts/' . $contact_id . '/comments', $body, 'Add note to contact');
        
        if ($result['success']) {
            return $result['data'];
        }
        
        return false;
    }
    
    /**
     * Add tags to contact (legacy method - kept for compatibility)
     * 
     * @param int $contact_id Contact ID
     * @param array $tags Array of tag names (ignored, uses fixed tag ID)
     * @return array|false Response data or false on failure
     */
    public function add_tags($contact_id, $tags) {
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