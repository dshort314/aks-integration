<?php
/**
 * DocuSeal Admin Settings Class
 * Handles the WordPress admin interface for DocuSeal settings
 */

class AKS_DocuSeal_Admin {
    
    private $option_name = 'aks_docuseal_html_template';
    private $guardian_option_name = 'aks_docuseal_guardian_html_template';
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
        // Register settings group
        register_setting(
            'aks_docuseal_settings_group',
            'aks_docuseal_settings',
            array($this, 'sanitize_settings')
        );
        
        register_setting(
            'aks_docuseal_settings_group',
            $this->option_name,
            array($this, 'sanitize_html')
        );
        
        register_setting(
            'aks_docuseal_settings_group',
            $this->guardian_option_name,
            array($this, 'sanitize_html')
        );
        
        // API Token Section
        add_settings_section(
            'aks_docuseal_api_section',
            'API Configuration',
            array($this, 'api_section_callback'),
            'aks-docuseal'
        );
        
        add_settings_field(
            'api_token_field',
            'DocuSeal API Token',
            array($this, 'api_token_callback'),
            'aks-docuseal',
            'aks_docuseal_api_section'
        );
        
        add_settings_field(
            'webhook_secret_field',
            'Webhook Secret',
            array($this, 'webhook_secret_callback'),
            'aks-docuseal',
            'aks_docuseal_api_section'
        );
        
        // Template Section
        add_settings_section(
            'aks_docuseal_template_section',
            'HTML Templates',
            array($this, 'section_callback'),
            'aks-docuseal'
        );
        
        add_settings_field(
            'html_template_field',
            'Standard Document Template',
            array($this, 'html_template_callback'),
            'aks-docuseal',
            'aks_docuseal_template_section'
        );
        
        add_settings_field(
            'guardian_template_field',
            'Parent/Guardian Document Template',
            array($this, 'guardian_template_callback'),
            'aks-docuseal',
            'aks_docuseal_template_section'
        );
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['api_token'])) {
            $sanitized['api_token'] = sanitize_text_field($input['api_token']);
        }
        
        if (isset($input['webhook_secret'])) {
            $sanitized['webhook_secret'] = sanitize_text_field($input['webhook_secret']);
        }
        
        return $sanitized;
    }
    
    /**
     * Section description callback
     */
    public function api_section_callback() {
        echo '<p>Enter your DocuSeal API token and webhook secret.</p>';
        echo '<p><a href="https://www.docuseal.com/settings/api" target="_blank">Get your DocuSeal API token here</a></p>';
        
        // Display webhook URL
        $webhook_url = rest_url('aks/v1/docuseal-webhook');
        echo '<div style="background:#f0f0f1;padding:15px;margin-top:10px;border-left:4px solid #0073aa;">';
        echo '<p><strong>Webhook URL:</strong></p>';
        echo '<p><code style="background:#fff;padding:5px 10px;display:inline-block;font-size:13px;">' . esc_html($webhook_url) . '</code></p>';
        echo '<p class="description">Copy this URL and add it to your DocuSeal webhook settings. Select the <strong>form.completed</strong> event.</p>';
        echo '</div>';
    }
    
    /**
     * API token field callback
     */
    public function api_token_callback() {
        $settings = get_option('aks_docuseal_settings');
        $value = isset($settings['api_token']) ? $settings['api_token'] : '';
        echo '<input type="password" name="aks_docuseal_settings[api_token]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Your DocuSeal API authentication token</p>';
    }
    
    /**
     * Webhook secret field callback
     */
    public function webhook_secret_callback() {
        $settings = get_option('aks_docuseal_settings');
        $value = isset($settings['webhook_secret']) ? $settings['webhook_secret'] : '';
        echo '<input type="text" name="aks_docuseal_settings[webhook_secret]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Secret key for webhook signature verification. Configure the same secret in your DocuSeal webhook settings.</p>';
    }
    
    /**
     * Section description callback
     */
    public function section_callback() {
        echo '<p>Edit the HTML templates that will be used to create DocuSeal documents. Use the following placeholders:</p>';
        echo '<ul>';
        echo '<li><strong>STUDENT-LOOP</strong> - Will be replaced with student names and birthdates</li>';
        echo '<li><strong>ACCOUNT-OWNER</strong> - Will be replaced with the account owner\'s full name</li>';
        echo '<li><strong>ACCOUNT-EMAIL</strong> - Will be replaced with the account owner\'s email</li>';
        echo '<li><strong>PARENT-EMAIL</strong> - Will be replaced with the parent/guardian\'s email (guardian template only)</li>';
        echo '<li><strong>PARENT-NAME</strong> - Will be replaced with the parent/guardian\'s name (guardian template only)</li>';
        echo '</ul>';
        echo '<p><strong>Note:</strong> The Standard Template is used when the registrant IS the parent/guardian. The Parent/Guardian Template is used when someone else needs to sign.</p>';
    }
    
    /**
     * HTML template field callback
     */
    public function html_template_callback() {
        $content = get_option($this->option_name, $this->get_default_template());
        
        wp_editor($content, 'aks_docuseal_html_template', array(
            'textarea_name' => $this->option_name,
            'textarea_rows' => 25,
            'media_buttons' => false,
            'teeny' => false,
            'quicktags' => true,
            'wpautop' => false,
            'tinymce' => array(
                'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
                'toolbar2' => '',
                'forced_root_block' => false,
                'force_br_newlines' => true,
                'force_p_newlines' => false,
                'convert_newlines_to_brs' => true,
            ),
        ));
        
        echo '<p class="description">You can switch to the "Text" tab to edit raw HTML and preserve custom DocuSeal tags like &lt;radio-field&gt; and &lt;signature-field&gt;.</p>';
    }
    
    /**
     * Guardian template field callback
     */
    public function guardian_template_callback() {
        $content = get_option($this->guardian_option_name, $this->get_default_guardian_template());
        
        wp_editor($content, 'aks_docuseal_guardian_html_template', array(
            'textarea_name' => $this->guardian_option_name,
            'textarea_rows' => 25,
            'media_buttons' => false,
            'teeny' => false,
            'quicktags' => true,
            'wpautop' => false,
            'tinymce' => array(
                'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
                'toolbar2' => '',
                'forced_root_block' => false,
                'force_br_newlines' => true,
                'force_p_newlines' => false,
                'convert_newlines_to_brs' => true,
            ),
        ));
        
        echo '<p class="description">This template is used when the registrant is NOT the parent/guardian. You can switch to the "Text" tab to edit raw HTML and preserve custom DocuSeal tags.</p>';
    }
    
    /**
     * Sanitize HTML - preserve HTML tags including custom DocuSeal tags
     */
    public function sanitize_html($input) {
        $allowed_tags = wp_kses_allowed_html('post');
        
        $allowed_tags['radio-field'] = array(
            'options' => true,
            'style' => true,
        );
        
        $allowed_tags['signature-field'] = array(
            'style' => true,
        );
        
        $allowed_tags['text-field'] = array(
            'style' => true,
        );
        
        foreach ($allowed_tags as $tag => $attributes) {
            $allowed_tags[$tag]['class'] = true;
            $allowed_tags[$tag]['id'] = true;
            $allowed_tags[$tag]['style'] = true;
        }
        
        return wp_kses($input, $allowed_tags);
    }
    
    /**
     * Get default template
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
     * Get default guardian template
     */
    private function get_default_guardian_template() {
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
     * Render settings page
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'aks_docuseal_messages',
                'aks_docuseal_message',
                'Settings Saved',
                'updated'
            );
        }
        
        settings_errors('aks_docuseal_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html('DocuSeal Integration Settings'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('aks_docuseal_settings_group');
                do_settings_sections('aks-docuseal');
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }
}