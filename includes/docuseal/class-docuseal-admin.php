<?php
/**
 * DocuSeal Admin Settings Class
 * Handles the WordPress admin interface for DocuSeal settings
 */

class AKS_DocuSeal_Admin {
    
    private $option_name = 'aks_docuseal_settings';
    
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'aks_docuseal_settings_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );
        
        // API Configuration Section
        add_settings_section(
            'aks_docuseal_api_section',
            'API Configuration',
            array($this, 'api_section_callback'),
            'aks-docuseal'
        );
        
        add_settings_field(
            'api_token',
            'DocuSeal API Token',
            array($this, 'api_token_callback'),
            'aks-docuseal',
            'aks_docuseal_api_section'
        );
        
        // Form Mappings Section
        add_settings_section(
            'aks_docuseal_mappings_section',
            'Form Mappings',
            array($this, 'mappings_section_callback'),
            'aks-docuseal'
        );
        
        add_settings_field(
            'form_mappings',
            'Gravity Form Mappings',
            array($this, 'form_mappings_callback'),
            'aks-docuseal',
            'aks_docuseal_mappings_section'
        );
        
        // Template Section
        add_settings_section(
            'aks_docuseal_template_section',
            'HTML Template',
            array($this, 'template_section_callback'),
            'aks-docuseal'
        );
        
        add_settings_field(
            'html_template',
            'Document Template',
            array($this, 'html_template_callback'),
            'aks-docuseal',
            'aks_docuseal_template_section'
        );
    }
    
    /**
     * Sanitize settings before saving
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['api_token'])) {
            $sanitized['api_token'] = sanitize_text_field($input['api_token']);
        }
        
        if (isset($input['form_mappings'])) {
            $sanitized['form_mappings'] = $input['form_mappings']; // Array of mappings
        }
        
        if (isset($input['html_template'])) {
            $sanitized['html_template'] = $this->sanitize_html($input['html_template']);
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize HTML - preserve HTML tags including custom DocuSeal tags
     */
    private function sanitize_html($input) {
        // Get all allowed HTML tags from WordPress
        $allowed_tags = wp_kses_allowed_html('post');
        
        // Add custom DocuSeal tags
        $allowed_tags['radio-field'] = array(
            'options' => true,
            'style' => true,
        );
        
        $allowed_tags['signature-field'] = array(
            'style' => true,
        );
        
        // Allow all standard HTML attributes for all tags
        foreach ($allowed_tags as $tag => $attributes) {
            $allowed_tags[$tag]['class'] = true;
            $allowed_tags[$tag]['id'] = true;
            $allowed_tags[$tag]['style'] = true;
        }
        
        // Use wp_kses to sanitize while preserving allowed tags
        return wp_kses($input, $allowed_tags);
    }
    
    /**
     * Section callbacks
     */
    public function api_section_callback() {
        echo '<p>Enter your DocuSeal API credentials.</p>';
        echo '<p><a href="https://www.docuseal.com/settings/api" target="_blank">Get your DocuSeal API token here</a></p>';
    }
    
    public function mappings_section_callback() {
        echo '<p>Configure which Gravity Forms should trigger DocuSeal document creation.</p>';
    }
    
    public function template_section_callback() {
        echo '<p>Edit the HTML template that will be used to create DocuSeal documents. Use the following placeholders:</p>';
        echo '<ul>';
        echo '<li><strong>STUDENT-LOOP</strong> - Will be replaced with student names and birthdates</li>';
        echo '<li><strong>ACCOUNT-OWNER</strong> - Will be replaced with the account owner\'s full name</li>';
        echo '<li><strong>ACCOUNT-EMAIL</strong> - Will be replaced with the account owner\'s email</li>';
        echo '</ul>';
    }
    
    /**
     * Field callbacks
     */
    public function api_token_callback() {
        $options = get_option($this->option_name);
        $value = isset($options['api_token']) ? $options['api_token'] : '';
        echo '<input type="password" name="' . $this->option_name . '[api_token]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Your DocuSeal API authentication token</p>';
    }
    
    public function form_mappings_callback() {
        $options = get_option($this->option_name);
        $mappings = isset($options['form_mappings']) ? $options['form_mappings'] : array();
        
        // Get all Gravity Forms
        if (class_exists('GFAPI')) {
            $forms = GFAPI::get_forms();
            ?>
            <div id="aks-docuseal-mappings">
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Form</th>
                            <th>Enable DocuSeal</th>
                            <th>First Name Field</th>
                            <th>Last Name Field</th>
                            <th>Email Field</th>
                            <th>Student Entries Field</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($forms as $form): ?>
                            <?php 
                            $form_id = $form['id'];
                            $mapping = isset($mappings[$form_id]) ? $mappings[$form_id] : array();
                            ?>
                            <tr>
                                <td><?php echo esc_html($form['title']); ?></td>
                                <td>
                                    <input type="checkbox" 
                                           name="<?php echo $this->option_name; ?>[form_mappings][<?php echo $form_id; ?>][enabled]" 
                                           value="1" 
                                           <?php checked(isset($mapping['enabled']) && $mapping['enabled']); ?> />
                                </td>
                                <td>
                                    <input type="text" 
                                           name="<?php echo $this->option_name; ?>[form_mappings][<?php echo $form_id; ?>][first_name_field]" 
                                           value="<?php echo isset($mapping['first_name_field']) ? esc_attr($mapping['first_name_field']) : ''; ?>" 
                                           class="small-text" />
                                </td>
                                <td>
                                    <input type="text" 
                                           name="<?php echo $this->option_name; ?>[form_mappings][<?php echo $form_id; ?>][last_name_field]" 
                                           value="<?php echo isset($mapping['last_name_field']) ? esc_attr($mapping['last_name_field']) : ''; ?>" 
                                           class="small-text" />
                                </td>
                                <td>
                                    <input type="text" 
                                           name="<?php echo $this->option_name; ?>[form_mappings][<?php echo $form_id; ?>][email_field]" 
                                           value="<?php echo isset($mapping['email_field']) ? esc_attr($mapping['email_field']) : ''; ?>" 
                                           class="small-text" />
                                </td>
                                <td>
                                    <input type="text" 
                                           name="<?php echo $this->option_name; ?>[form_mappings][<?php echo $form_id; ?>][students_field]" 
                                           value="<?php echo isset($mapping['students_field']) ? esc_attr($mapping['students_field']) : ''; ?>" 
                                           class="small-text" />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">Enter the field IDs for each form. For nested forms, use the parent field ID for the Student Entries Field.</p>
            </div>
            <?php
        } else {
            echo '<p>Gravity Forms is not available.</p>';
        }
    }
    
    public function html_template_callback() {
        $options = get_option($this->option_name);
        $content = isset($options['html_template']) ? $options['html_template'] : $this->get_default_template();
        
        wp_editor($content, 'aks_docuseal_html_template', array(
            'textarea_name' => $this->option_name . '[html_template]',
            'textarea_rows' => 25,
            'media_buttons' => false,
            'teeny' => false,
            'quicktags' => true,  // Enable text editor tab
            'wpautop' => false,   // Don't auto-add paragraphs
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
     * Render settings page
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if settings were saved
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
            
            <div class="aks-admin-content">
                <form method="post" action="options.php">
                    <?php
                    settings_fields('aks_docuseal_settings_group');
                    do_settings_sections('aks-docuseal');
                    submit_button('Save Settings');
                    ?>
                </form>
                
                <div class="aks-info-box">
                    <h3>How DocuSeal Integration Works</h3>
                    <ol>
                        <li>Configure your DocuSeal API token above</li>
                        <li>Enable DocuSeal for specific Gravity Forms and map the fields</li>
                        <li>Customize the HTML template for your documents</li>
                        <li>When a form is submitted:
                            <ul>
                                <li>A document is created from the HTML template</li>
                                <li>Placeholders are replaced with form data</li>
                                <li>The document is sent to the submitter for signing</li>
                            </ul>
                        </li>
                    </ol>
                    
                    <h3>Field Mapping</h3>
                    <p>Enter the field IDs from your Gravity Forms:</p>
                    <ul>
                        <li><strong>First Name Field</strong>: The field ID for first name (e.g., "27" or "1.3")</li>
                        <li><strong>Last Name Field</strong>: The field ID for last name (e.g., "28" or "1.6")</li>
                        <li><strong>Email Field</strong>: The field ID for email address</li>
                        <li><strong>Student Entries Field</strong>: For nested forms, the parent field ID containing student entries</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
}
