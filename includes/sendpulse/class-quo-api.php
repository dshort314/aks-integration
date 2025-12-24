<?php
/**
 * Quo (OpenPhone) API Client
 * Handles communication with Quo API
 */

class AKS_Quo_API {
    
    private $api_key;
    private $api_base_url = 'https://api.openphone.com/v1';
    
    public function __construct($api_key) {
        $this->api_key = $api_key;
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
     * Get all contacts (paginated)
     * 
     * @return array All contacts
     */
    private function get_all_contacts() {
        $all_contacts = array();
        $limit = 100;
        $offset = 0;
        
        do {
            $url = $this->api_base_url . '/contacts?limit=' . $limit . '&offset=' . $offset;
            
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => $this->api_key,
                    'Content-Type' => 'application/json'
                ),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                break;
            }
            
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body, true);
            
            if (!isset($data['data']) || empty($data['data'])) {
                break;
            }
            
            $all_contacts = array_merge($all_contacts, $data['data']);
            $offset += $limit;
            
            // Continue if we got a full page
        } while (count($data['data']) == $limit);
        
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
        $url = $this->api_base_url . '/contacts/' . $contact_id;
        
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
        
        $response = wp_remote_request($url, array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
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
     * Create a new contact
     * 
     * @param array $contact_data Contact information
     * @return array|false Response data or false on failure
     */
    public function create_contact($contact_data) {
        $url = $this->api_base_url . '/contacts';
        
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
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => $this->api_key,
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
        $data = json_decode($response_body, true);
        
        if ($response_code !== 200 && $response_code !== 201) {
            return false;
        }
        
        return $data;
    }
}