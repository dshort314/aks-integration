<?php
/**
 * SendPulse Admin Settings Class
 * Handles the WordPress admin interface for SendPulse settings
 */

class AKS_SendPulse_Admin {
    
    private $option_name = 'aks_sendpulse_settings';
    
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'aks_sendpulse_settings_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );
        
        // API Credentials Section
        add_settings_section(
            'aks_sendpulse_api_section',
            'API Credentials',
            array($this, 'api_section_callback'),
            'aks-sendpulse'
        );
        
        add_settings_field(
            'api_id',
            'SendPulse API ID',
            array($this, 'api_id_callback'),
            'aks-sendpulse',
            'aks_sendpulse_api_section'
        );
        
        add_settings_field(
            'api_secret',
            'SendPulse API Secret',
            array($this, 'api_secret_callback'),
            'aks-sendpulse',
            'aks_sendpulse_api_section'
        );
        
        add_settings_field(
            'quo_api_key',
            'Quo API Key',
            array($this, 'quo_api_key_callback'),
            'aks-sendpulse',
            'aks_sendpulse_api_section'
        );
        
        // Form Configuration Section
        add_settings_section(
            'aks_sendpulse_form_section',
            'Gravity Forms Configuration',
            array($this, 'form_section_callback'),
            'aks-sendpulse'
        );
        
        add_settings_field(
            'form_id',
            'Gravity Form ID',
            array($this, 'form_id_callback'),
            'aks-sendpulse',
            'aks_sendpulse_form_section'
        );
    }
    
    /**
     * Sanitize settings before saving
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['api_id'])) {
            $sanitized['api_id'] = sanitize_text_field($input['api_id']);
        }
        
        if (isset($input['api_secret'])) {
            $sanitized['api_secret'] = sanitize_text_field($input['api_secret']);
        }
        
        if (isset($input['quo_api_key'])) {
            $sanitized['quo_api_key'] = sanitize_text_field($input['quo_api_key']);
        }
        
        if (isset($input['form_id'])) {
            $sanitized['form_id'] = absint($input['form_id']);
        }
        
        return $sanitized;
    }
    
    /**
     * Section callbacks
     */
    public function api_section_callback() {
        echo '<p>Enter your API credentials for SendPulse and Quo (OpenPhone).</p>';
        echo '<p><a href="https://login.sendpulse.com/settings/#api" target="_blank">Get your SendPulse API credentials here</a></p>';
    }
    
    public function form_section_callback() {
        echo '<p>Configure which Gravity Form to monitor for submissions.</p>';
    }
    
    /**
     * Field callbacks
     */
    public function api_id_callback() {
        $options = get_option($this->option_name);
        $value = isset($options['api_id']) ? $options['api_id'] : '';
        echo '<input type="text" name="' . $this->option_name . '[api_id]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Your SendPulse API ID</p>';
    }
    
    public function api_secret_callback() {
        $options = get_option($this->option_name);
        $value = isset($options['api_secret']) ? $options['api_secret'] : '';
        echo '<input type="password" name="' . $this->option_name . '[api_secret]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Your SendPulse API Secret</p>';
    }
    
    public function quo_api_key_callback() {
        $options = get_option($this->option_name);
        $value = isset($options['quo_api_key']) ? $options['quo_api_key'] : '';
        echo '<input type="password" name="' . $this->option_name . '[quo_api_key]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Your Quo (OpenPhone) API Key</p>';
    }
    
    public function form_id_callback() {
        $options = get_option($this->option_name);
        $value = isset($options['form_id']) ? $options['form_id'] : '';
        echo '<input type="number" name="' . $this->option_name . '[form_id]" value="' . esc_attr($value) . '" class="small-text" />';
        echo '<p class="description">The ID of the Gravity Form to monitor for submissions</p>';
    }
    
    /**
     * Render settings page
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if settings were saved
        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'aks_sendpulse_messages',
                'aks_sendpulse_message',
                'Settings Saved',
                'updated'
            );
        }
        
        settings_errors('aks_sendpulse_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html('SendPulse Integration Settings'); ?></h1>
            
            <div class="aks-admin-content">
                <form method="post" action="options.php">
                    <?php
                    settings_fields('aks_sendpulse_settings_group');
                    do_settings_sections('aks-sendpulse');
                    submit_button('Save Settings');
                    ?>
                </form>
                
                <div class="aks-info-box">
                    <h3>How SendPulse Integration Works</h3>
                    <ol>
                        <li>Enter your SendPulse and Quo API credentials above</li>
                        <li>Specify the Gravity Form ID you want to monitor</li>
                        <li>The plugin expects the following field structure:
                            <ul>
                                <li>Field 3.3 - First Name</li>
                                <li>Field 3.6 - Last Name</li>
                                <li>Field 4 - Email</li>
                                <li>Field 5 - Phone</li>
                                <li>Field 26 - SendPulse Contact ID (auto-populated)</li>
                                <li>Field 27 - SendPulse User ID (auto-populated)</li>
                                <li>Field 28 - SendPulse Phone ID (auto-populated)</li>
                                <li>Field 29 - SendPulse Email ID (auto-populated)</li>
                                <li>Field 30 - Quo Contact ID (auto-populated)</li>
                                <li>Field 31 - Quo Phone ID (auto-populated)</li>
                            </ul>
                        </li>
                        <li>When a user submits the form, contacts will be created or updated in both SendPulse and Quo</li>
                        <li>The API response IDs will be automatically saved to the form entry</li>
                    </ol>
                    
                    <h3>What Happens on Form Submission</h3>
                    <ol>
                        <li>The plugin checks if the contact exists in SendPulse (by email or phone)</li>
                        <li>If the contact exists and is missing email or phone, it adds the missing information</li>
                        <li>If the contact doesn't exist, it creates a new contact in SendPulse</li>
                        <li>The plugin checks if the contact exists in Quo (by phone number)</li>
                        <li>If the contact exists but has no name, it updates with the submitted name</li>
                        <li>If the contact doesn't exist, it creates a new contact in Quo</li>
                        <li>All API response IDs are saved to hidden fields in the form entry</li>
                    </ol>
                </div>
            </div>
        </div>
        <?php
    }
}
