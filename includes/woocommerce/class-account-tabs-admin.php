<?php
/**
 * Account Tabs Admin Settings Class
 * Handles the WordPress admin interface for managing account tab content
 */

class AKS_Account_Tabs_Admin {
    
    private static $instance = null;
    
    // Option names for each tab content
    private $option_names = array(
        'evaluation_training' => 'aks_account_tab_evaluation_training',
        'purchase_bundle' => 'aks_account_tab_purchase_bundle',
        'manage_lessons' => 'aks_account_tab_manage_lessons',
        'videos' => 'aks_account_tab_videos',
    );
    
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
        // Register each content option
        foreach ($this->option_names as $key => $option_name) {
            register_setting(
                'aks_account_tabs_settings_group',
                $option_name,
                array($this, 'sanitize_content')
            );
        }
    }
    
    /**
     * Sanitize content - minimal sanitization to preserve shortcodes and formatting
     */
    public function sanitize_content($input) {
        // Remove any span tags that wrap shortcodes (TinyMCE sometimes adds these)
        $input = preg_replace('/<span[^>]*>(\[.*?\])<\/span>/i', '$1', $input);
        
        // Use wp_kses_post which allows most HTML and preserves shortcodes
        // This is the same sanitization WordPress uses for post content
        return wp_kses_post($input);
    }
    
    /**
     * Render Evaluation & Training Lessons page
     */
    public function render_evaluation_training_page() {
        $this->render_editor_page(
            'Evaluation & Training Lessons',
            'evaluation_training',
            'Content displayed in the Evaluation & Training Lessons sub-tab under Lessons.'
        );
    }
    
    /**
     * Render Purchase Bundle page
     */
    public function render_purchase_bundle_page() {
        $this->render_editor_page(
            'Purchase Bundle',
            'purchase_bundle',
            'Content displayed in the Purchase Bundle sub-tab under Lessons.'
        );
    }
    
    /**
     * Render Manage Lessons page
     */
    public function render_manage_lessons_page() {
        $this->render_editor_page(
            'Manage Lessons',
            'manage_lessons',
            'Content displayed in the Manage Lessons sub-tab under Lessons.'
        );
    }
    
    /**
     * Render Videos page
     */
    public function render_videos_page() {
        $this->render_editor_page(
            'Videos Tab Content',
            'videos',
            'Content displayed in the Videos tab. This will replace the current Video Library page content.'
        );
    }
    
    /**
     * Generic editor page renderer
     */
    private function render_editor_page($title, $key, $description) {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $option_name = $this->option_names[$key];
        
        // Handle form submission
        if (isset($_POST['aks_account_tab_submit']) && isset($_POST['aks_account_tab_nonce'])) {
            if (wp_verify_nonce($_POST['aks_account_tab_nonce'], 'aks_account_tab_save_' . $key)) {
                // Get raw content without WordPress adding slashes
                $content = isset($_POST[$option_name]) ? wp_unslash($_POST[$option_name]) : '';
                update_option($option_name, $content);
                
                echo '<div class="notice notice-success is-dismissible"><p>Content saved successfully!</p></div>';
            }
        }
        
        $content = get_option($option_name, '');
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($title); ?></h1>
            <p class="description"><?php echo esc_html($description); ?></p>
            
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <h3 style="margin-top: 0;">Using Shortcodes</h3>
                <p><strong>To add a shortcode:</strong> Simply type it directly in the editor (Visual or Text tab), like: <code>[gravityview id="123"]</code></p>
                <p><strong>Common shortcodes:</strong></p>
                <ul style="margin-left: 20px;">
                    <li><code>[gravityview id="YOUR_ID"]</code> - Display a GravityView</li>
                    <li><code>[gravityform id="YOUR_ID"]</code> - Display a Gravity Form</li>
                    <li><code>[latepoint_book_form]</code> - Display LatePoint booking form</li>
                </ul>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('aks_account_tab_save_' . $key, 'aks_account_tab_nonce'); ?>
                
                <div style="margin-top: 20px;">
                    <?php
                    wp_editor($content, $option_name, array(
                        'textarea_name' => $option_name,
                        'textarea_rows' => 20,
                        'media_buttons' => true,
                        'teeny' => false,
                        'quicktags' => true,
                        'wpautop' => false, // Don't auto-add paragraphs
                        'tinymce' => array(
                            'toolbar1' => 'formatselect,bold,italic,underline,strikethrough,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,wp_more,spellchecker,fullscreen,wp_adv',
                            'toolbar2' => 'styleselect,forecolor,backcolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help',
                            'toolbar3' => '',
                            'toolbar4' => '',
                            'wpautop' => false,
                            'wptextpattern' => false, // Disable text pattern replacement
                            'remove_linebreaks' => false,
                            'convert_newlines_to_brs' => false,
                        ),
                    ));
                    ?>
                    <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        // Remove span wrapping from shortcodes when switching between Visual/Text
                        if (typeof tinymce !== 'undefined') {
                            tinymce.on('AddEditor', function(e) {
                                if (e.editor.id === '<?php echo esc_js($option_name); ?>') {
                                    e.editor.on('BeforeSetContent', function(ed) {
                                        // Protect shortcodes from being wrapped
                                        if (ed.content) {
                                            ed.content = ed.content.replace(/\[([^\]]+)\]/g, function(match) {
                                                return '<!--mce-shortcode-->' + match + '<!--/mce-shortcode-->';
                                            });
                                        }
                                    });
                                    
                                    e.editor.on('PostProcess', function(ed) {
                                        // Restore shortcodes
                                        if (ed.content) {
                                            ed.content = ed.content.replace(/<!--mce-shortcode-->/g, '');
                                            ed.content = ed.content.replace(/<!--\/mce-shortcode-->/g, '');
                                        }
                                    });
                                }
                            });
                        }
                    });
                    </script>
                </div>
                
                <p class="submit">
                    <input type="submit" name="aks_account_tab_submit" class="button button-primary" value="Save Content" />
                </p>
            </form>
        </div>
        <?php
    }
}