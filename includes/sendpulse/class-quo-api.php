<?php
/**
 * Quo (OpenPhone) API Client
 * Handles communication with Quo API
 * Now includes API logging
 */

class AKS_Quo_API {
    
    private $api_key;
    private $api_base_url = 'https://api.openphone.com/v1';
    
    public function __construct($api_key) {
        $this->api_key = $api_key;
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
     * Format phone number to E.164 format (+1XXXXXXXXXX for US numbers)
     * 
     * @param string $phone Raw phone number
     * @return string E.164 formatted phone number
     */
    private function format_phone_e164($phone) {
        if (empty($phone)) {
            return '';
        }
        
        // Remove all non-numeric characters
        $digits = preg_replace('/[^0-9]/', '', $phone);
        
        // If already has country code (11 digits starting with 1), format it
        if (strlen($digits) === 11 && substr($digits, 0, 1) === '1') {
            return '+' . $digits;
        }
        
        // If 10 digits, assume US and add +1 prefix
        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }
        
        // If something else, return with + prefix
        return '+' . $digits;
    }
    
    /**
     * Make an API request with logging
     */
    private function make_request($method, $endpoint, $body = null, $context = '', $user_email = '') {
        $url = $this->api_base_url . $endpoint;
        $logger = $this->get_logger();
        $log_data = null;
        
        $headers = array(
            'Authorization' => $this->api_key,
            'Content-Type' => 'application/json'
        );
        
        // For logging, mask the API key
        $log_headers = array(
            'Authorization' => '[REDACTED]',
            'Content-Type' => 'application/json'
        );
        
        if ($logger) {
            $log_data = $logger->log_request(
                'Quo',
                $url,
                $method,
                $log_headers,
                $body,
                $context,
                null,
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
            case 'PATCH':
            case 'PUT':
            case 'DELETE':
                $args['method'] = $method;
                $response = wp_remote_request($url, $args);
                break;
            default:
                $response = wp_remote_get($url, $args);
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
     * Get all contacts (paginated)
     * 
     * @return array All contacts
     */
    private function get_all_contacts() {
        $all_contacts = array();
        $limit = 100;
        $offset = 0;
        
        do {
            $result = $this->make_request('GET', '/contacts?limit=' . $limit . '&offset=' . $offset, null, 'Get contacts (offset: ' . $offset . ')');
            
            if (!$result['success'] || !isset($result['data']['data']) || empty($result['data']['data'])) {
                break;
            }
            
            $all_contacts = array_merge($all_contacts, $result['data']['data']);
            $offset += $limit;
            
        } while (count($result['data']['data']) == $limit);
        
        return $all_contacts;
    }
    
    /**
     * Search for contact by phone number
     * 
     * @param string $phone Phone number to search
     * @return array|null Contact data or null if not found
     */
    public function search_contact_by_phone($phone) {
        // Format phone to E.164 before searching
        $phone_e164 = $this->format_phone_e164($phone);
        
        $contacts = $this->get_all_contacts();
        
        foreach ($contacts as $contact) {
            if (isset($contact['defaultFields']['phoneNumbers'])) {
                foreach ($contact['defaultFields']['phoneNumbers'] as $phone_number) {
                    // Format the contact's phone number to E.164 for comparison
                    $contact_phone_e164 = $this->format_phone_e164($phone_number['value']);
                    
                    if ($contact_phone_e164 === $phone_e164) {
                        return $contact;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Update contact with first name and last name
     * 
     * @param string $contact_id Contact ID
     * @param string $first_name First name
     * @param string $last_name Last name
     * @param string $phone Phone number
     * @return array|false Response data or false on failure
     */
    public function update_contact_names($contact_id, $first_name, $last_name, $phone) {
        // Format phone to E.164
        $phone_e164 = $this->format_phone_e164($phone);
        
        $body = array(
            'defaultFields' => array(
                'firstName' => $first_name,
                'lastName' => $last_name,
                'phoneNumbers' => array(
                    array(
                        'name' => 'mobile',
                        'value' => $phone_e164
                    )
                )
            )
        );
        
        $result = $this->make_request('PATCH', '/contacts/' . $contact_id, $body, 'Update contact names');
        
        if ($result['success']) {
            return $result['data'];
        }
        
        return false;
    }
    
    /**
     * Create a new contact
     * 
     * @param array $contact_data Contact information
     * @return array|false Response data or false on failure
     */
    public function create_contact($contact_data) {
        $body = array(
            'defaultFields' => array(
                'firstName' => $contact_data['firstName'],
                'lastName' => $contact_data['lastName']
            )
        );
        
        if (!empty($contact_data['email'])) {
            $body['defaultFields']['emails'] = array(
                array(
                    'name' => 'primary',
                    'value' => $contact_data['email']
                )
            );
        }
        
        if (!empty($contact_data['phone'])) {
            // Format phone to E.164
            $phone_e164 = $this->format_phone_e164($contact_data['phone']);
            
            $body['defaultFields']['phoneNumbers'] = array(
                array(
                    'name' => 'mobile',
                    'value' => $phone_e164
                )
            );
        }
        
        $email = isset($contact_data['email']) ? $contact_data['email'] : '';
        
        $result = $this->make_request('POST', '/contacts', $body, 'Create contact', $email);
        
        if ($result['success']) {
            return $result['data'];
        }
        
        return false;
    }
}