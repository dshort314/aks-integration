<?php
/**
 * Checkout Bump Products
 * Displays products from a specified category on checkout with add-to-cart functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class AKS_Checkout_Bump_Products {
    
    private static $instance = null;
    
    /**
     * Product category slug for bump products
     */
    private $category_slug = 'custom-merch';
    
    public function __construct() {
        // Only run if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Display bump products on checkout
        add_action('woocommerce_review_order_before_payment', array($this, 'display_checkout_bump_products'));
        
        // Enqueue JS and CSS
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handler
        add_action('wp_ajax_bump_add_to_cart', array($this, 'ajax_add_to_cart'));
        add_action('wp_ajax_nopriv_bump_add_to_cart', array($this, 'ajax_add_to_cart'));
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
     * Display bump products on checkout page
     */
    public function display_checkout_bump_products() {
        $products = wc_get_products(array(
            'category' => array($this->category_slug),
            'limit'    => -1,
            'status'   => 'publish',
        ));
        
        if (empty($products)) {
            return;
        }
        
        // Get current cart product IDs and variation IDs
        $cart_product_ids = array();
        $cart_variation_ids = array();
        
        foreach (WC()->cart->get_cart() as $item) {
            $cart_product_ids[] = $item['product_id'];
            if (!empty($item['variation_id'])) {
                $cart_variation_ids[] = $item['variation_id'];
            }
        }
        
        echo '<div class="checkout-bump-products">';
        echo '<h3>' . esc_html__('You might also like', 'aks-integration') . '</h3>';
        
        foreach ($products as $product) {
            $product_id = $product->get_id();
            $is_variable = $product->is_type('variable');
            
            // Skip simple products already in cart
            if (!$is_variable && in_array($product_id, $cart_product_ids)) {
                continue;
            }
            
            $price = $product->get_price_html();
            $image = $product->get_image('thumbnail');
            
            echo '<div class="bump-product" data-product-id="' . esc_attr($product_id) . '" data-is-variable="' . ($is_variable ? '1' : '0') . '">';
            echo '<div class="bump-product-image">' . $image . '</div>';
            echo '<div class="bump-product-info">';
            echo '<span class="bump-product-title">' . esc_html($product->get_name()) . '</span>';
            echo '<span class="bump-product-price">' . $price . '</span>';
            
            // Add variation dropdown for variable products
            if ($is_variable) {
                $variations = $product->get_available_variations();
                $available_variations = array();
                
                foreach ($variations as $variation) {
                    // Skip if this variation is already in cart
                    if (in_array($variation['variation_id'], $cart_variation_ids)) {
                        continue;
                    }
                    
                    if ($variation['is_in_stock']) {
                        $attr_value = reset($variation['attributes']); // Get first (only) attribute value
                        $available_variations[] = array(
                            'id'    => $variation['variation_id'],
                            'label' => $attr_value,
                            'price' => $variation['display_price'],
                        );
                    }
                }
                
                if (!empty($available_variations)) {
                    echo '<select class="bump-variation-select">';
                    echo '<option value="">' . esc_html__('Select Size', 'aks-integration') . '</option>';
                    foreach ($available_variations as $var) {
                        echo '<option value="' . esc_attr($var['id']) . '">' . esc_html(ucfirst($var['label'])) . '</option>';
                    }
                    echo '</select>';
                } else {
                    // All variations in cart or out of stock
                    echo '<span class="bump-unavailable">' . esc_html__('Not available', 'aks-integration') . '</span>';
                }
            }
            
            echo '</div>';
            
            // Only show button if product is available
            if (!$is_variable || !empty($available_variations)) {
                echo '<button type="button" class="button bump-add-to-cart">' . esc_html__('Add', 'aks-integration') . '</button>';
            }
            
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Enqueue scripts and styles for checkout page
     */
    public function enqueue_scripts() {
        if (!is_checkout()) {
            return;
        }
        
        // Inline CSS
        wp_add_inline_style('woocommerce-inline', '
            .checkout-bump-products { margin-bottom: 20px; padding: 15px; background: #f8f8f8; border-radius: 4px; }
            .checkout-bump-products h3 { margin: 0 0 15px; font-size: 1.1em; }
            .bump-product { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #e0e0e0; }
            .bump-product:last-child { border-bottom: none; padding-bottom: 0; }
            .bump-product-image img { width: 50px; height: auto; }
            .bump-product-info { flex: 1; }
            .bump-product-title { display: block; font-weight: 500; }
            .bump-product-price { font-size: 0.9em; color: #666; }
            .bump-variation-select { display: block; margin-top: 5px; padding: 4px 8px; }
            .bump-add-to-cart { white-space: nowrap; }
            .bump-add-to-cart.added { background: #4caf50; color: #fff; }
            .bump-unavailable { font-size: 0.85em; color: #999; font-style: italic; }
        ');
        
        // Inline JavaScript
        $nonce = wp_create_nonce('bump-add-to-cart');
        
        wp_add_inline_script('wc-checkout', '
            jQuery(function($) {
                $(document.body).on("click", ".bump-add-to-cart", function() {
                    var $btn = $(this);
                    var $product = $btn.closest(".bump-product");
                    var productId = $product.data("product-id");
                    var isVariable = $product.data("is-variable") == 1;
                    var variationId = 0;
                    
                    if (isVariable) {
                        var $select = $product.find(".bump-variation-select");
                        variationId = $select.val();
                        
                        if (!variationId) {
                            alert("' . esc_js(__('Please select a size', 'aks-integration')) . '");
                            $select.focus();
                            return;
                        }
                    }
                    
                    $btn.prop("disabled", true).text("' . esc_js(__('Adding...', 'aks-integration')) . '");
                    
                    $.post(wc_checkout_params.ajax_url, {
                        action: "bump_add_to_cart",
                        product_id: productId,
                        variation_id: variationId,
                        security: "' . $nonce . '"
                    }, function(response) {
                        if (response.success) {
                            $btn.addClass("added").text("' . esc_js(__('Added!', 'aks-integration')) . '");
                            $(document.body).trigger("update_checkout");
                            
                            if (isVariable) {
                                // Remove the selected option
                                var $select = $product.find(".bump-variation-select");
                                $select.find("option:selected").remove();
                                $select.val("");
                                
                                // If no more options, hide the product
                                if ($select.find("option").length <= 1) {
                                    setTimeout(function() {
                                        $product.slideUp();
                                    }, 1000);
                                } else {
                                    // Reset button for next selection
                                    setTimeout(function() {
                                        $btn.removeClass("added").prop("disabled", false).text("' . esc_js(__('Add', 'aks-integration')) . '");
                                    }, 1500);
                                }
                            } else {
                                setTimeout(function() {
                                    $product.slideUp();
                                }, 1000);
                            }
                        } else {
                            $btn.prop("disabled", false).text("' . esc_js(__('Add', 'aks-integration')) . '");
                            alert(response.data || "' . esc_js(__('Could not add product', 'aks-integration')) . '");
                        }
                    });
                });
            });
        ');
    }
    
    /**
     * AJAX handler for adding bump products to cart
     */
    public function ajax_add_to_cart() {
        check_ajax_referer('bump-add-to-cart', 'security');
        
        $product_id = absint($_POST['product_id']);
        $variation_id = absint($_POST['variation_id']);
        
        if (!$product_id) {
            wp_send_json_error(__('Invalid product', 'aks-integration'));
        }
        
        if ($variation_id) {
            // Variable product - get variation attributes
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                wp_send_json_error(__('Invalid variation', 'aks-integration'));
            }
            
            $added = WC()->cart->add_to_cart($product_id, 1, $variation_id, $variation->get_variation_attributes());
        } else {
            // Simple product
            $added = WC()->cart->add_to_cart($product_id);
        }
        
        if ($added) {
            wp_send_json_success();
        } else {
            wp_send_json_error(__('Could not add to cart', 'aks-integration'));
        }
    }
}
