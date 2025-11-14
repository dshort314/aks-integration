<?php
/**
 * SendPulse Admin Settings Class
 * Handles the WordPress admin interface for SendPulse settings
 */

class AKS_SendPulse_Admin {
    
    private $option_name = 'aks_sendpulse_settings';
    private static $instance = null;
    
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
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
    }
    
    public function api_secret_callback() {
        $options = get_option($this->option_name);
        $value = isset($options['api_secret']) ? $options['api_secret'] : '';
        echo '<input type="password" name="' . $this->option_name . '[api_secret]" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    public function quo_api_key_callback() {
        $options = get_option($this->option_name);
        $value = isset($options['quo_api_key']) ? $options['quo_api_key'] : '';
        echo '<input type="password" name="' . $this->option_name . '[quo_api_key]" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    public function form_id_callback() {
        $options = get_option($this->option_name);
        $value = isset($options['form_id']) ? $options['form_id'] : '';
        echo '<input type="number" name="' . $this->option_name . '[form_id]" value="' . esc_attr($value) . '" class="small-text" />';
    }
    
    /**
     * Render settings page
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
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
            <form method="post" action="options.php">
                <?php
                settings_fields('aks_sendpulse_settings_group');
                do_settings_sections('aks-sendpulse');
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }
}