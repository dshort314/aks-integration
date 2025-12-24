<?php
/**
 * Donated Lessons Handler
 * Manages tracking of donated lessons from product sales
 */

if (!defined('ABSPATH')) {
    exit;
}

class AKS_Donated_Lessons_Handler {
    
    private $option_name = 'aks_donated_lessons_total';
    private $history_option = 'aks_donated_lessons_history';
    private static $instance = null;
    
    public function __construct() {
        // Only run if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Add product meta fields
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_product_fields'));
        add_action('woocommerce_variation_options_pricing', array($this, 'add_variation_fields'), 10, 3);
        
        // Save product meta
        add_action('woocommerce_process_product_meta', array($this, 'save_product_fields'));
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_fields'), 10, 2);
        
        // Track order completions
        add_action('woocommerce_order_status_completed', array($this, 'process_completed_order'));
        
        // Admin settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Shortcode
        add_shortcode('aks_donated_lessons', array($this, 'donated_lessons_shortcode'));
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
        register_setting('aks_donated_lessons_group', $this->option_name);
        register_setting('aks_donated_lessons_group', $this->history_option);
    }
    
    /**
     * Add product meta fields for simple products
     */
    public function add_product_fields() {
        global $post;
        
        echo '<div class="options_group">';
        
        woocommerce_wp_checkbox(array(
            'id' => '_donated_lessons_enabled',
            'label' => __('Includes Donated Lessons', 'aks-integration'),
            'description' => __('Check this if purchasing this product contributes to donated lessons.', 'aks-integration'),
        ));
        
        woocommerce_wp_text_input(array(
            'id' => '_donated_lessons_count',
            'label' => __('Donated Lessons Count', 'aks-integration'),
            'description' => __('Number of lessons donated per item purchased.', 'aks-integration'),
            'type' => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min' => '0',
            ),
        ));
        
        echo '</div>';
    }
    
    /**
     * Add variation meta fields
     */
    public function add_variation_fields($loop, $variation_data, $variation) {
        woocommerce_wp_checkbox(array(
            'id' => '_donated_lessons_enabled[' . $loop . ']',
            'name' => '_donated_lessons_enabled[' . $loop . ']',
            'label' => __('Includes Donated Lessons', 'aks-integration'),
            'value' => get_post_meta($variation->ID, '_donated_lessons_enabled', true),
            'wrapper_class' => 'form-row form-row-first',
        ));
        
        woocommerce_wp_text_input(array(
            'id' => '_donated_lessons_count[' . $loop . ']',
            'name' => '_donated_lessons_count[' . $loop . ']',
            'label' => __('Donated Lessons Count', 'aks-integration'),
            'value' => get_post_meta($variation->ID, '_donated_lessons_count', true),
            'type' => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min' => '0',
            ),
            'wrapper_class' => 'form-row form-row-last',
        ));
    }
    
    /**
     * Save product meta fields
     */
    public function save_product_fields($post_id) {
        $enabled = isset($_POST['_donated_lessons_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_donated_lessons_enabled', $enabled);
        
        if (isset($_POST['_donated_lessons_count'])) {
            $count = absint($_POST['_donated_lessons_count']);
            update_post_meta($post_id, '_donated_lessons_count', $count);
        }
    }
    
    /**
     * Save variation meta fields
     */
    public function save_variation_fields($variation_id, $i) {
        if (isset($_POST['_donated_lessons_enabled'][$i])) {
            update_post_meta($variation_id, '_donated_lessons_enabled', 'yes');
        } else {
            update_post_meta($variation_id, '_donated_lessons_enabled', 'no');
        }
        
        if (isset($_POST['_donated_lessons_count'][$i])) {
            $count = absint($_POST['_donated_lessons_count'][$i]);
            update_post_meta($variation_id, '_donated_lessons_count', $count);
        }
    }
    
    /**
     * Process completed order and update donated lessons counter
     */
    public function process_completed_order($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // Check if already processed
        if ($order->get_meta('_donated_lessons_processed')) {
            return;
        }
        
        $total_donated = 0;
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $quantity = $item->get_quantity();
            
            if (!$product) {
                continue;
            }
            
            $donated_count = 0;
            
            // Check if it's a variation
            if ($product->is_type('variation')) {
                $enabled = get_post_meta($product->get_id(), '_donated_lessons_enabled', true);
                if ($enabled === 'yes') {
                    $donated_count = absint(get_post_meta($product->get_id(), '_donated_lessons_count', true));
                }
            } else {
                // Simple product or bundle
                $enabled = get_post_meta($product->get_id(), '_donated_lessons_enabled', true);
                if ($enabled === 'yes') {
                    $donated_count = absint(get_post_meta($product->get_id(), '_donated_lessons_count', true));
                }
            }
            
            if ($donated_count > 0) {
                $item_total = $quantity * $donated_count;
                $total_donated += $item_total;
            }
        }
        
        if ($total_donated > 0) {
            // Update global counter
            $current_total = get_option($this->option_name, 0);
            $new_total = $current_total + $total_donated;
            update_option($this->option_name, $new_total);
            
            // Mark order as processed
            $order->update_meta_data('_donated_lessons_processed', 'yes');
            $order->update_meta_data('_donated_lessons_amount', $total_donated);
            $order->save();
            
            // Log to history
            $this->add_to_history('order', $total_donated, $order_id);
        }
    }
    
    /**
     * Add entry to adjustment history
     */
    private function add_to_history($type, $amount, $reference = '') {
        $history = get_option($this->history_option, array());
        
        $current_user = wp_get_current_user();
        $user_name = $current_user->ID ? $current_user->display_name : 'System';
        
        $entry = array(
            'timestamp' => current_time('mysql'),
            'type' => $type, // 'order', 'add', 'subtract'
            'amount' => $amount,
            'reference' => $reference,
            'user' => $user_name,
        );
        
        // Add to beginning of array
        array_unshift($history, $entry);
        
        // Keep only last 50 entries
        $history = array_slice($history, 0, 50);
        
        update_option($this->history_option, $history);
    }
    
    /**
     * Manual adjustment of counter
     */
    public function adjust_counter($operation, $amount) {
        $amount = absint($amount);
        if ($amount === 0) {
            return false;
        }
        
        $current_total = get_option($this->option_name, 0);
        
        if ($operation === 'add') {
            $new_total = $current_total + $amount;
        } elseif ($operation === 'subtract') {
            $new_total = max(0, $current_total - $amount);
        } else {
            return false;
        }
        
        update_option($this->option_name, $new_total);
        $this->add_to_history($operation, $amount, 'manual');
        
        return true;
    }
    
    /**
     * Get current total
     */
    public function get_total() {
        return absint(get_option($this->option_name, 0));
    }
    
    /**
     * Get history
     */
    public function get_history($limit = 10) {
        $history = get_option($this->history_option, array());
        return array_slice($history, 0, $limit);
    }
    
    /**
     * Shortcode to display donated lessons total
     */
    public function donated_lessons_shortcode($atts) {
        $atts = shortcode_atts(array(
            'prefix' => '',
            'suffix' => '',
            'format' => 'number', // 'number' or 'text'
        ), $atts, 'aks_donated_lessons');
        
        $total = $this->get_total();
        
        if ($atts['format'] === 'text') {
            $total = number_format($total);
        }
        
        $output = $atts['prefix'] . $total . $atts['suffix'];
        
        return esc_html($output);
    }
    
    /**
     * Render admin settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle manual adjustment
        if (isset($_POST['aks_adjust_counter']) && isset($_POST['aks_adjust_nonce'])) {
            if (wp_verify_nonce($_POST['aks_adjust_nonce'], 'aks_adjust_counter')) {
                $operation = sanitize_text_field($_POST['adjustment_operation']);
                $amount = absint($_POST['adjustment_amount']);
                
                if ($this->adjust_counter($operation, $amount)) {
                    echo '<div class="notice notice-success is-dismissible"><p>Counter adjusted successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>Invalid adjustment parameters.</p></div>';
                }
            }
        }
        
        $total = $this->get_total();
        $history = $this->get_history(10);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Donated Lessons Tracker', 'aks-integration'); ?></h1>
            
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; border-radius: 4px;">
                <h2 style="margin-top: 0;"><?php echo esc_html__('Current Total', 'aks-integration'); ?></h2>
                <p style="font-size: 48px; font-weight: bold; margin: 10px 0; color: #2271b1;">
                    <?php echo number_format($total); ?>
                </p>
                <p style="color: #666; margin: 0;">
                    <?php echo esc_html__('Total lessons donated through product sales', 'aks-integration'); ?>
                </p>
            </div>
            
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; border-radius: 4px;">
                <h2 style="margin-top: 0;"><?php echo esc_html__('Manual Adjustment', 'aks-integration'); ?></h2>
                <p><?php echo esc_html__('Use this to manually add or subtract from the counter if needed.', 'aks-integration'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('aks_adjust_counter', 'aks_adjust_nonce'); ?>
                    
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <?php echo esc_html__('Operation', 'aks-integration'); ?>
                                </th>
                                <td>
                                    <label style="display: inline-block; margin-right: 20px;">
                                        <input type="radio" name="adjustment_operation" value="add" checked />
                                        <?php echo esc_html__('Add', 'aks-integration'); ?>
                                    </label>
                                    <label style="display: inline-block;">
                                        <input type="radio" name="adjustment_operation" value="subtract" />
                                        <?php echo esc_html__('Subtract', 'aks-integration'); ?>
                                    </label>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="adjustment_amount">
                                        <?php echo esc_html__('Amount', 'aks-integration'); ?>
                                    </label>
                                </th>
                                <td>
                                    <input 
                                        type="number" 
                                        name="adjustment_amount" 
                                        id="adjustment_amount" 
                                        value="0" 
                                        min="0" 
                                        step="1" 
                                        class="regular-text"
                                        required
                                    />
                                    <p class="description">
                                        <?php echo esc_html__('Number of lessons to add or subtract', 'aks-integration'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <p class="submit">
                        <input 
                            type="submit" 
                            name="aks_adjust_counter" 
                            class="button button-primary" 
                            value="<?php echo esc_attr__('Update Counter', 'aks-integration'); ?>"
                        />
                    </p>
                </form>
            </div>
            
            <?php if (!empty($history)): ?>
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; border-radius: 4px;">
                <h2 style="margin-top: 0;"><?php echo esc_html__('Recent History', 'aks-integration'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Date/Time', 'aks-integration'); ?></th>
                            <th><?php echo esc_html__('Type', 'aks-integration'); ?></th>
                            <th><?php echo esc_html__('Amount', 'aks-integration'); ?></th>
                            <th><?php echo esc_html__('Reference', 'aks-integration'); ?></th>
                            <th><?php echo esc_html__('User', 'aks-integration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry): ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry['timestamp']))); ?></td>
                            <td>
                                <?php 
                                $type_labels = array(
                                    'order' => __('Order', 'aks-integration'),
                                    'add' => __('Manual Add', 'aks-integration'),
                                    'subtract' => __('Manual Subtract', 'aks-integration'),
                                );
                                echo esc_html($type_labels[$entry['type']] ?? $entry['type']);
                                ?>
                            </td>
                            <td>
                                <?php 
                                if ($entry['type'] === 'subtract') {
                                    echo '<span style="color: #dc3232;">-' . number_format($entry['amount']) . '</span>';
                                } else {
                                    echo '<span style="color: #46b450;">+' . number_format($entry['amount']) . '</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                if ($entry['type'] === 'order' && !empty($entry['reference'])) {
                                    echo '<a href="' . esc_url(admin_url('post.php?post=' . $entry['reference'] . '&action=edit')) . '">';
                                    echo esc_html__('Order #', 'aks-integration') . $entry['reference'];
                                    echo '</a>';
                                } else {
                                    echo esc_html($entry['reference']);
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html($entry['user']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <div style="background: #f0f0f1; border-left: 4px solid #2271b1; padding: 15px; margin-top: 30px;">
                <h3 style="margin-top: 0;"><?php echo esc_html__('How It Works', 'aks-integration'); ?></h3>
                <ol style="margin-left: 20px;">
                    <li><?php echo esc_html__('Edit any product and check "Includes Donated Lessons" in the Product Data section.', 'aks-integration'); ?></li>
                    <li><?php echo esc_html__('Set the number of lessons donated per item purchased.', 'aks-integration'); ?></li>
                    <li><?php echo esc_html__('For variable products, set the donated lesson count for each variation.', 'aks-integration'); ?></li>
                    <li><?php echo esc_html__('When an order is completed, the counter automatically updates.', 'aks-integration'); ?></li>
                    <li><?php echo esc_html__('Use the shortcode [aks_donated_lessons] to display the counter on your site.', 'aks-integration'); ?></li>
                </ol>
                
                <h3><?php echo esc_html__('Shortcode Usage', 'aks-integration'); ?></h3>
                <p><strong><?php echo esc_html__('Basic:', 'aks-integration'); ?></strong> <code>[aks_donated_lessons]</code></p>
                <p><strong><?php echo esc_html__('With text:', 'aks-integration'); ?></strong> <code>[aks_donated_lessons prefix="We've donated " suffix=" lessons!"]</code></p>
                <p><strong><?php echo esc_html__('Formatted:', 'aks-integration'); ?></strong> <code>[aks_donated_lessons format="text"]</code></p>
            </div>
        </div>
        <?php
    }
}

// Note: This class should be initialized in the main plugin file