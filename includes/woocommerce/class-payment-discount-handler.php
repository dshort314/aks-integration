<?php
/**
 * Payment Discount Handler
 * Manages payment method discounts in WooCommerce checkout
 */

if (!defined('ABSPATH')) {
    exit;
}

class AKS_Payment_Discount_Handler {
    
    private $option_name = 'aks_payment_discount_settings';
    private static $instance = null;
    
    public function __construct() {
        // Only run if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Hook into checkout to apply discount
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_payment_discount'));
        
        // Update available payment methods when gateways change
        add_action('woocommerce_update_options_payment_gateways', array($this, 'update_available_gateways'));
        
        // Admin settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Enqueue frontend script to trigger cart update on payment method change
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
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
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'aks_payment_discount_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // Sanitize discount percentage
        if (isset($input['discount_percentage'])) {
            $sanitized['discount_percentage'] = floatval($input['discount_percentage']);
            // Ensure it's between 0 and 100
            $sanitized['discount_percentage'] = max(0, min(100, $sanitized['discount_percentage']));
        }
        
        // Sanitize enabled gateways (checkboxes)
        if (isset($input['enabled_gateways']) && is_array($input['enabled_gateways'])) {
            $sanitized['enabled_gateways'] = array_map('sanitize_text_field', $input['enabled_gateways']);
        } else {
            $sanitized['enabled_gateways'] = array();
        }
        
        return $sanitized;
    }
    
    /**
     * Get all available WooCommerce payment gateways
     */
    public function get_available_gateways() {
        if (!function_exists('WC')) {
            return array();
        }
        
        $gateways = WC()->payment_gateways->payment_gateways();
        $available = array();
        
        foreach ($gateways as $gateway) {
            // Only include enabled gateways
            if ($gateway->enabled === 'yes') {
                $available[$gateway->id] = array(
                    'id' => $gateway->id,
                    'title' => $gateway->get_title(),
                    'method_title' => $gateway->get_method_title(),
                );
            }
        }
        
        return $available;
    }
    
    /**
     * Update available gateways when settings change
     */
    public function update_available_gateways() {
        // This is called when payment gateway settings are saved
        // We don't need to do anything special here as get_available_gateways()
        // will always fetch the current list
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts() {
        // Only load on checkout page
        if (!is_checkout()) {
            return;
        }
        
        // Inline script to trigger cart update when payment method changes
        $script = "
        jQuery(function($) {
            // Trigger update_checkout when payment method changes
            $('form.checkout').on('change', 'input[name=\"payment_method\"]', function() {
                $('body').trigger('update_checkout');
            });
        });
        ";
        
        wp_add_inline_script('jquery', $script);
    }
    
    /**
     * Apply payment discount at checkout
     */
    public function apply_payment_discount($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        // Get settings
        $settings = get_option($this->option_name, array());
        
        if (empty($settings['discount_percentage']) || empty($settings['enabled_gateways'])) {
            return;
        }
        
        // Get chosen payment method
        $chosen_payment_method = WC()->session->get('chosen_payment_method');
        
        if (empty($chosen_payment_method)) {
            return;
        }
        
        // Check if chosen payment method has discount enabled
        if (!in_array($chosen_payment_method, $settings['enabled_gateways'])) {
            return;
        }
        
        // Calculate discount
        $discount_percentage = floatval($settings['discount_percentage']);
        $cart_total = $cart->get_subtotal();
        $discount_amount = ($cart_total * $discount_percentage) / 100;
        
        // Get payment gateway title for display
        $gateways = WC()->payment_gateways->payment_gateways();
        $gateway_title = isset($gateways[$chosen_payment_method]) ? $gateways[$chosen_payment_method]->get_title() : 'Payment Method';
        
        // Apply discount as negative fee
        $cart->add_fee(
            sprintf(__('%s Discount (%s%%)', 'aks-integration'), $gateway_title, number_format($discount_percentage, 2)),
            -$discount_amount,
            false
        );
    }
    
    /**
     * Render admin settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle form submission
        if (isset($_POST['aks_payment_discount_submit']) && isset($_POST['aks_payment_discount_nonce'])) {
            if (wp_verify_nonce($_POST['aks_payment_discount_nonce'], 'aks_payment_discount_save')) {
                $settings = array(
                    'discount_percentage' => isset($_POST['discount_percentage']) ? floatval($_POST['discount_percentage']) : 0,
                    'enabled_gateways' => isset($_POST['enabled_gateways']) ? array_map('sanitize_text_field', $_POST['enabled_gateways']) : array(),
                );
                
                update_option($this->option_name, $settings);
                
                echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
            }
        }
        
        // Get current settings
        $settings = get_option($this->option_name, array(
            'discount_percentage' => 0,
            'enabled_gateways' => array(),
        ));
        
        // Get available payment gateways
        $gateways = $this->get_available_gateways();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Payment Method Discount Settings', 'aks-integration'); ?></h1>
            
            <p class="description">
                <?php echo esc_html__('Configure automatic discounts for specific payment methods. When a customer selects an enabled payment method at checkout, the specified percentage discount will be applied to their order total.', 'aks-integration'); ?>
            </p>
            
            <?php if (empty($gateways)): ?>
                <div class="notice notice-warning">
                    <p><?php echo esc_html__('No active payment methods found. Please enable payment methods in WooCommerce > Settings > Payments.', 'aks-integration'); ?></p>
                </div>
            <?php else: ?>
                <form method="post" action="">
                    <?php wp_nonce_field('aks_payment_discount_save', 'aks_payment_discount_nonce'); ?>
                    
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="discount_percentage">
                                        <?php echo esc_html__('Discount Percentage', 'aks-integration'); ?>
                                    </label>
                                </th>
                                <td>
                                    <input 
                                        type="number" 
                                        name="discount_percentage" 
                                        id="discount_percentage" 
                                        value="<?php echo esc_attr($settings['discount_percentage']); ?>" 
                                        min="0" 
                                        max="100" 
                                        step="0.01" 
                                        class="regular-text"
                                    />
                                    <p class="description">
                                        <?php echo esc_html__('Enter the discount percentage (e.g., 2 for 2% discount). This will be calculated as a decimal (2 = 0.02).', 'aks-integration'); ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <?php echo esc_html__('Enabled Payment Methods', 'aks-integration'); ?>
                                </th>
                                <td>
                                    <fieldset>
                                        <legend class="screen-reader-text">
                                            <span><?php echo esc_html__('Select payment methods to apply discount', 'aks-integration'); ?></span>
                                        </legend>
                                        <?php foreach ($gateways as $gateway): ?>
                                            <label style="display: block; margin-bottom: 8px;">
                                                <input 
                                                    type="checkbox" 
                                                    name="enabled_gateways[]" 
                                                    value="<?php echo esc_attr($gateway['id']); ?>"
                                                    <?php checked(in_array($gateway['id'], $settings['enabled_gateways'])); ?>
                                                />
                                                <?php echo esc_html($gateway['title']); ?>
                                                <span style="color: #666; font-size: 13px;">
                                                    (<?php echo esc_html($gateway['method_title']); ?>)
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                        <p class="description">
                                            <?php echo esc_html__('Check the payment methods that should receive the discount. Only active payment methods are shown.', 'aks-integration'); ?>
                                        </p>
                                    </fieldset>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <p class="submit">
                        <input 
                            type="submit" 
                            name="aks_payment_discount_submit" 
                            class="button button-primary" 
                            value="<?php echo esc_attr__('Save Settings', 'aks-integration'); ?>"
                        />
                    </p>
                </form>
                
                <div style="background: #f0f0f1; border-left: 4px solid #2271b1; padding: 15px; margin-top: 30px;">
                    <h3 style="margin-top: 0;"><?php echo esc_html__('How It Works', 'aks-integration'); ?></h3>
                    <ol style="margin-left: 20px;">
                        <li><?php echo esc_html__('Set the discount percentage you want to offer.', 'aks-integration'); ?></li>
                        <li><?php echo esc_html__('Select which payment methods should receive the discount.', 'aks-integration'); ?></li>
                        <li><?php echo esc_html__('When a customer selects an enabled payment method at checkout, the discount will be automatically applied.', 'aks-integration'); ?></li>
                        <li><?php echo esc_html__('The discount appears as a line item in the cart/checkout totals.', 'aks-integration'); ?></li>
                    </ol>
                    <p><strong><?php echo esc_html__('Note:', 'aks-integration'); ?></strong> <?php echo esc_html__('Only payment methods that are currently active in WooCommerce will appear in this list. If you activate or deactivate payment methods, this list will update automatically.', 'aks-integration'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

// Note: This class should be initialized in the main plugin file, not here
// Add this to your main plugin file (aks-integration.php or similar):
// if (file_exists(AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-payment-discount-handler.php')) {
//     require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/woocommerce/class-payment-discount-handler.php';
//     AKS_Payment_Discount_Handler::get_instance();
// }