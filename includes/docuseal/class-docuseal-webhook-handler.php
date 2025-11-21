<?php
/**
 * DocuSeal Webhook Handler
 * Handles webhooks from DocuSeal when documents are completed
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
        // Get the raw body for logging
        $body = $request->get_body();
        $data = json_decode($body, true);
        
        // Log webhook received
        error_log('DocuSeal Webhook: Received webhook');
        error_log('DocuSeal Webhook: Data - ' . print_r($data, true));
        
        // Get the webhook secret from settings
        $settings = get_option('aks_docuseal_settings');
        $webhook_secret = isset($settings['webhook_secret']) ? $settings['webhook_secret'] : '';
        
        // If secret is configured, verify it
        if (!empty($webhook_secret)) {
            // Get the signature from headers
            $signature_header = $request->get_header('x-docuseal-signature');
            
            if (empty($signature_header)) {
                error_log('DocuSeal Webhook: No signature header found');
                error_log('DocuSeal Webhook: Available headers - ' . print_r($request->get_headers(), true));
                return new WP_Error('no_signature', 'No signature provided', array('status' => 401));
            }
            
            // DocuSeal sends the secret as a plain value in the header, not an HMAC
            // Simply compare the header value with our configured secret
            if ($signature_header !== $webhook_secret) {
                error_log('DocuSeal Webhook: Invalid signature');
                error_log('DocuSeal Webhook: Expected - ' . $webhook_secret);
                error_log('DocuSeal Webhook: Received - ' . $signature_header);
                return new WP_Error('invalid_signature', 'Invalid signature', array('status' => 401));
            }
            
            error_log('DocuSeal Webhook: Signature verified successfully');
        }
        
        // Check if this is a submission completed event
        if (!isset($data['event_type']) || $data['event_type'] !== 'form.completed') {
            error_log('DocuSeal Webhook: Not a form.completed event');
            return new WP_REST_Response(array('message' => 'Event ignored'), 200);
        }
        
        // Extract email from the submission
        $email = '';
        if (isset($data['submission']['submitters'][0]['email'])) {
            $email = $data['submission']['submitters'][0]['email'];
        } elseif (isset($data['data']['email'])) {
            $email = $data['data']['email'];
        }
        
        if (empty($email)) {
            error_log('DocuSeal Webhook: No email found in webhook data');
            return new WP_Error('no_email', 'No email found in webhook data', array('status' => 400));
        }
        
        error_log('DocuSeal Webhook: Processing for email: ' . $email);
        
        // Find the user by email - check both user email AND guardian email
        $user = get_user_by('email', $email);
        
        // If not found by user email, search for user by guardian email in user meta
        if (!$user) {
            error_log('DocuSeal Webhook: User not found by direct email, searching user meta for guardian email');
            
            global $wpdb;
            $user_id = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} 
                WHERE meta_key = 'sr_guardian_email' 
                AND meta_value = %s 
                LIMIT 1",
                $email
            ));
            
            if ($user_id) {
                $user = get_userdata($user_id);
                error_log('DocuSeal Webhook: Found user ' . $user_id . ' by guardian email');
            }
        } else {
            error_log('DocuSeal Webhook: Found user ' . $user->ID . ' by direct email match');
        }
        
        if (!$user) {
            error_log('DocuSeal Webhook: User not found for email: ' . $email . ' (checked both user email and guardian email)');
            return new WP_Error('user_not_found', 'User not found', array('status' => 404));
        }
        
        $user_id = $user->ID;
        
        // Mark waiver as signed
        update_user_meta($user_id, 'sr_waiver_signed', 'yes');
        error_log('DocuSeal Webhook: Marked waiver as signed for user ' . $user_id);
        
        return new WP_REST_Response(array(
            'message' => 'Webhook processed successfully',
            'user_id' => $user_id
        ), 200);
    }
}