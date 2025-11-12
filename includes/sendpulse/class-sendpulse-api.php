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
            error_log('SendPulse API Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            error_log('SendPulse API Error: HTTP ' . $response_code . ' - ' . $response_body);
            return false;
        }
        
        $data = json_decode($response_body, true);
        
        if (!isset($data['access_token'])) {
            error_log('SendPulse API Error: No access token in response');
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
                    error_log('SendPulse: Contact found with both email and phone. ID: ' . $contact_id);
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
                    error_log('SendPulse: Contact found with email only. ID: ' . $contact_id);
                    return array('exists' => true, 'contact_id' => $contact_id, 'has_email' => true, 'has_phone' => false);
                }
            }
        }
        
        // Search by phone only
        if (!empty($phone)) {
            $phone_clean = preg_replace('/[^0-9]/', '', $phone);
            
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
                    error_log('SendPulse: Contact found with phone only. ID: ' . $contact_id);
                    return array('exists' => true, 'contact_id' => $contact_id, 'has_email' => false, 'has_phone' => true);
                }
            }
        }
        
        return array('exists' => false, 'contact_id' => null, 'has_email' => false, 'has_phone' => false);
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
            error_log('SendPulse Add Phone Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        error_log('SendPulse: Phone added to contact ' . $contact_id);
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
            error_log('SendPulse Add Email Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        error_log('SendPulse: Email added to contact ' . $contact_id);
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
            // Remove any non-numeric characters except + at the start
            $phone = preg_replace('/[^0-9]/', '', $contact_data['phone']);
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
            error_log('SendPulse Create Contact Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if ($response_code !== 200 && $response_code !== 201) {
            error_log('SendPulse Create Contact Error: HTTP ' . $response_code . ' - ' . $response_body);
            return false;
        }
        
        // Log successful creation
        if (isset($data['data']['id'])) {
            error_log('SendPulse Contact Created: ID ' . $data['data']['id']);
        }
        
        return $data;
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
