<?php
/**
 * DocuSeal Webhook Handler
 * Handles webhooks from DocuSeal when documents are completed
 * Now includes API logging
 */

class AKS_DocuSeal_Webhook_Handler {
    
    public function __construct() {
        // Register REST API endpoint
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
    }
    
    /**
     * Register the webhook endpoint
     */
    public function register_webhook_endpoint() {
        register_rest_route('aks/v1', '/docuseal-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true', // We'll verify the secret manually
        ));
    }
    
    /**
     * Handle incoming webhook from DocuSeal
     */
    public function handle_webhook($request) {
        // Get the raw body
        $body = $request->get_body();
        $data = json_decode($body, true);
        
        // Get headers for logging
        $headers = $request->get_headers();
        
        // Get the webhook secret from settings
        $settings = get_option('aks_docuseal_settings');
        $webhook_secret = isset($settings['webhook_secret']) ? $settings['webhook_secret'] : '';
        
        // Initialize response variables for logging
        $response_code = 200;
        $response_body = '';
        $success = true;
        $error_message = '';
        $user_id = null;
        $user_email = '';
        
        // If secret is configured, verify it
        if (!empty($webhook_secret)) {
            // Get the signature from headers
            $signature_header = $request->get_header('x-docuseal-signature');
            
            if (empty($signature_header)) {
                $response_code = 401;
                $success = false;
                $error_message = 'No signature provided';
                
                $this->log_webhook($headers, $body, $response_code, $error_message, $success, $error_message, $user_id, $user_email);
                
                return new WP_Error('no_signature', 'No signature provided', array('status' => 401));
            }
            
            // DocuSeal sends the secret as a plain value in the header, not an HMAC
            // Simply compare the header value with our configured secret
            if ($signature_header !== $webhook_secret) {
                $response_code = 401;
                $success = false;
                $error_message = 'Invalid signature';
                
                $this->log_webhook($headers, $body, $response_code, $error_message, $success, $error_message, $user_id, $user_email);
                
                return new WP_Error('invalid_signature', 'Invalid signature', array('status' => 401));
            }
        }
        
        // Check if this is a submission completed event
        if (!isset($data['event_type']) || $data['event_type'] !== 'form.completed') {
            $response_body = json_encode(array('message' => 'Event ignored'));
            
            $this->log_webhook($headers, $body, $response_code, $response_body, true, '', $user_id, $user_email);
            
            return new WP_REST_Response(array('message' => 'Event ignored'), 200);
        }
        
        // Extract email from the submission
        $email = '';
        if (isset($data['submission']['submitters'][0]['email'])) {
            $email = $data['submission']['submitters'][0]['email'];
        } elseif (isset($data['data']['email'])) {
            $email = $data['data']['email'];
        }
        
        $user_email = $email;
        
        if (empty($email)) {
            $response_code = 400;
            $success = false;
            $error_message = 'No email found in webhook data';
            
            $this->log_webhook($headers, $body, $response_code, $error_message, $success, $error_message, $user_id, $user_email);
            
            return new WP_Error('no_email', 'No email found in webhook data', array('status' => 400));
        }
        
        // Find the user by email - check both user email AND guardian email
        $user = get_user_by('email', $email);
        
        // If not found by user email, search for user by guardian email in user meta
        if (!$user) {
            global $wpdb;
            $found_user_id = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} 
                WHERE meta_key = 'sr_guardian_email' 
                AND meta_value = %s 
                LIMIT 1",
                $email
            ));
            
            if ($found_user_id) {
                $user = get_userdata($found_user_id);
            }
        }
        
        if (!$user) {
            $response_code = 404;
            $success = false;
            $error_message = 'User not found for email: ' . $email;
            
            $this->log_webhook($headers, $body, $response_code, $error_message, $success, $error_message, $user_id, $user_email);
            
            return new WP_Error('user_not_found', 'User not found', array('status' => 404));
        }
        
        $user_id = $user->ID;
        
        // Only mark waiver as signed if user IS the parent/guardian
        $is_parent = get_user_meta($user_id, 'sr_is_parent_guardian', true);
        $is_parent_normalized = strtolower($is_parent);
        
        $action_taken = '';
        if ($is_parent_normalized === 'yes') {
            update_user_meta($user_id, 'sr_waiver_signed', 'yes');
            $action_taken = 'Waiver marked as signed';
        } else {
            $action_taken = 'Waiver NOT marked (user is not parent/guardian, is_parent_guardian=' . $is_parent . ')';
        }
        
        $response_body = json_encode(array(
            'message' => 'Webhook processed successfully',
            'user_id' => $user_id,
            'action' => $action_taken
        ));
        
        $this->log_webhook($headers, $body, $response_code, $response_body, true, '', $user_id, $user_email);
        
        return new WP_REST_Response(array(
            'message' => 'Webhook processed successfully',
            'user_id' => $user_id
        ), 200);
    }
    
    /**
     * Log webhook to API logger
     */
    private function log_webhook($headers, $body, $response_code, $response_body, $success, $error_message, $user_id, $user_email) {
        // Check if logger is available
        if (!class_exists('AKS_API_Logger')) {
            return;
        }
        
        $logger = AKS_API_Logger::get_instance();
        
        $logger->log_webhook(
            'DocuSeal',
            rest_url('aks/v1/docuseal-webhook'),
            $headers,
            $body,
            $response_code,
            $response_body,
            $success,
            $error_message,
            $user_id,
            $user_email
        );
    }
}