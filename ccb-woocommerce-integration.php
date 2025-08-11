<?php
/**
 * Plugin Name: CCB WooCommerce Integration (Secure)
 * Description: Intégration Cost Calculator Builder avec WooCommerce. Remplace les prix par ceux calculés, sans eval(), avec bannière de debug restreinte.
 * Version: 1.0.0
 * Author: Votre Nom
 * License: GPLv2 or later
 * Text Domain: ccb-woo-integration
 */

if ( ! defined('ABSPATH') ) { exit; }

/**
 * INTÉGRATION COST CALCULATOR BUILDER + WOOCOMMERCE
 * Version optimisée pour récupérer le prix calculé dans le panier
 */

// Helper sans eval: additionne les nombres > 1 trouvés dans une formule CCB
function ccb_safe_sum_from_formula($formula) {
    if (!is_string($formula) || $formula === '') return 0.0;
    $normalized = str_replace('||', '+', $formula);
    preg_match_all('/\d+(?:[.,]\d+)?/', $normalized, $matches);
    $sum = 0.0;
    foreach ($matches[0] as $num) {
        $num = floatval(str_replace(',', '.', $num));
        if ($num > 1) {
            $sum += $num;
        }
    }
    return $sum;
}

// 1. Fonction principale pour récupérer le prix CCB - VERSION CORRIGÉE
function get_ccb_calculated_price($product_id, $cart_item = null) {
    if (!$cart_item) return 0;
    
    // PRIORITÉ 1: Calculer depuis ccb_woo_meta_link_quantity_data + options
    if (isset($cart_item['ccb_calculator']['ccb_woo_meta_link_quantity_data']['total'])) {
        $base_price = floatval($cart_item['ccb_calculator']['ccb_woo_meta_link_quantity_data']['total']);
        
        // Ajouter les options activées
        $options_total = 0;
        if (isset($cart_item['ccb_calculator']['calc_data']) && is_array($cart_item['ccb_calculator']['calc_data'])) {
            foreach ($cart_item['ccb_calculator']['calc_data'] as $field_key => $field_data) {
                // Chercher les champs avec des prix (toggle, checkbox, radio)
                if (isset($field_data['value']) && is_string($field_data['value'])) {
                    // Pattern pour " ()  2.95" ou "(126 x 0.26) 32.76 €" etc.
                    if (preg_match('/[\d.]+/', $field_data['value'], $matches)) {
                        $price = floatval($matches[0]);
                        
                        // Si c'est un toggle/option activé (pas le prix de base)
                        if (strpos($field_key, 'toggle') !== false && $price > 0) {
                            $options_total += $price;
                        }
                        // Si c'est un champ avec un prix qui n'est pas le total à 1€
                        else if (strpos($field_key, 'total') === false && $price > 1) {
                            // Ne pas ajouter les prix de base déjà comptés
                            if ($price != $base_price) {
                                $options_total += $price;
                            }
                        }
                    }
                }
            }
        }
        
        $total_calculated = $base_price + $options_total;
        if ($total_calculated > 0) {
            return $total_calculated;
        }
    }
    
    // PRIORITÉ 2: Analyser la formule woo_totals sans eval
    if (isset($cart_item['ccb_calculator']['woo_totals']) && is_array($cart_item['ccb_calculator']['woo_totals'])) {
        foreach ($cart_item['ccb_calculator']['woo_totals'] as $total_item) {
            if (isset($total_item['formula']) && !empty($total_item['formula'])) {
                $sum = ccb_safe_sum_from_formula($total_item['formula']);
                if ($sum > 0) {
                    return $sum;
                }
            }
        }
    }
    
    // PRIORITÉ 3: Méthodes de fallback (si les méthodes ci-dessus échouent)
    if (isset($cart_item['ccb_total']) && is_numeric($cart_item['ccb_total']) && $cart_item['ccb_total'] > 1) {
        return floatval($cart_item['ccb_total']);
    }
    
    if (isset($cart_item['ccb_calculator']['ccb_total']) && is_numeric($cart_item['ccb_calculator']['ccb_total']) && $cart_item['ccb_calculator']['ccb_total'] > 1) {
        return floatval($cart_item['ccb_calculator']['ccb_total']);
    }
    
    return 0;
}

// 2. Appliquer le prix CCB dans le panier (HOOK PRINCIPAL)
function update_cart_prices_with_ccb($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (did_action('woocommerce_before_calculate_totals') >= 2) return;

    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        // Vérifier si ce produit a des données CCB
        if (isset($cart_item['ccb_calculator']) || isset($cart_item['ccb_total']) || 
            (isset($_POST) && array_key_exists('ccb_total', $_POST))) {
            
            $product_id = $cart_item['product_id'];
            $calculated_price = get_ccb_calculated_price($product_id, $cart_item);
            
            if ($calculated_price > 0) {
                // Appliquer le prix calculé
                $cart_item['data']->set_price($calculated_price);
                
                // Log pour débogage
                error_log("CCB Price Applied - Product ID: $product_id, Price: $calculated_price");
            }
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'update_cart_prices_with_ccb', 10, 1);

// 3. Hook alternatif pour s'assurer que le prix est appliqué
function force_ccb_price_in_cart($cart) {
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        if (isset($cart_item['ccb_calculator']) || isset($cart_item['ccb_total'])) {
            $calculated_price = get_ccb_calculated_price($cart_item['product_id'], $cart_item);
            
            if ($calculated_price > 0) {
                $current_price = $cart_item['data']->get_price();
                
                // Si le prix n'est pas encore appliqué, le forcer
                if ($current_price != $calculated_price) {
                    $cart_item['data']->set_price($calculated_price);
                }
            }
        }
    }
}
add_action('woocommerce_cart_loaded_from_session', 'force_ccb_price_in_cart');

// 4. Afficher le bon prix dans le panier
function display_ccb_price_in_cart($price_html, $cart_item, $cart_item_key) {
    if (isset($cart_item['ccb_calculator']) || isset($cart_item['ccb_total'])) {
        $calculated_price = get_ccb_calculated_price($cart_item['product_id'], $cart_item);
        
        if ($calculated_price > 0) {
            return wc_price($calculated_price);
        }
    }
    
    return $price_html;
}
add_filter('woocommerce_cart_item_price', 'display_ccb_price_in_cart', 99, 3);

// 5. Afficher le bon sous-total dans le panier
function display_ccb_subtotal_in_cart($subtotal, $cart_item, $cart_item_key) {
    if (isset($cart_item['ccb_calculator']) || isset($cart_item['ccb_total'])) {
        $calculated_price = get_ccb_calculated_price($cart_item['product_id'], $cart_item);
        
        if ($calculated_price > 0) {
            $quantity = $cart_item['quantity'];
            return wc_price($calculated_price * $quantity);
        }
    }
    
    return $subtotal;
}
add_filter('woocommerce_cart_item_subtotal', 'display_ccb_subtotal_in_cart', 99, 3);

// 6. S'assurer que le prix CCB remplace le prix produit
function override_product_price_with_ccb($price, $product) {
    // Seulement sur cart et checkout
    if ((is_cart() || is_checkout()) && WC()->cart && !WC()->cart->is_empty()) {
        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($cart_item['product_id'] == $product->get_id()) {
                if (isset($cart_item['ccb_calculator']) || isset($cart_item['ccb_total'])) {
                    $calculated_price = get_ccb_calculated_price($product->get_id(), $cart_item);
                    if ($calculated_price > 0) {
                        return $calculated_price;
                    }
                }
            }
        }
    }
    return $price;
}
add_filter('woocommerce_product_get_price', 'override_product_price_with_ccb', 99, 2);
add_filter('woocommerce_product_variation_get_price', 'override_product_price_with_ccb', 99, 2);

// 7. BANNIÈRE DE DEBUG DANS LE FOOTER - Visible partout
function ccb_footer_debug_banner() {
    // Afficher seulement pour les admins et quand ?ccb_debug=1
    if ( ! current_user_can('manage_options') || empty($_GET['ccb_debug']) ) {
        return;
    }

    // Afficher seulement si il y a un panier avec des données
    if (!WC()->cart || WC()->cart->is_empty()) return;
    
    $has_ccb_products = false;
    $debug_info = [];
    
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $product_name = $cart_item['data']->get_name();
        $has_ccb_data = isset($cart_item['ccb_calculator']) || isset($cart_item['ccb_total']);
        
        if ($has_ccb_data) {
            $has_ccb_products = true;
            
            $current_price = $cart_item['data']->get_price();
            $calculated_price = get_ccb_calculated_price($product_id, $cart_item);
            
            $debug_info[] = [
                'product_name' => $product_name,
                'product_id' => $product_id,
                'current_price' => $current_price,
                'calculated_price' => $calculated_price,
                'cart_item' => $cart_item
            ];
        }
    }
    
    if (!$has_ccb_products) return;
    ?>
    
    <div id="ccb-debug-footer" style="display: none;">
        <div class="ccb-debug-content">
            <div class="debug-header">
                <h3>🔍 CCB Debug - Status du Panier</h3>
                <button onclick="toggleCCBDebug()" class="close-debug">×</button>
            </div>
            
            <?php foreach ($debug_info as $info): ?>
                <div class="debug-product-item">
                    <h4>📦 <?php echo esc_html($info['product_name']); ?> (ID: <?php echo $info['product_id']; ?>)</h4>
                    
                    <div class="price-comparison">
                        <span class="current-price">💰 Prix WC: <?php echo $info['current_price']; ?>€</span>
                        <span class="calculated-price">🧮 Prix CCB: <?php echo $info['calculated_price']; ?>€</span>
                        
                        <?php if ($info['calculated_price'] > 0): ?>
                            <?php if ($info['current_price'] == $info['calculated_price']): ?>
                                <span class="status success">✅ OK</span>
                            <?php else: ?>
                                <span class="status error">❌ PROBLÈME</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="status warning">⚠️ CCB NON TROUVÉ</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="debug-data">
                        <strong>🔍 Données CCB trouvées:</strong><br>
                        
                        <?php if (isset($info['cart_item']['ccb_total'])): ?>
                            <div>ccb_total: <?php echo esc_html($info['cart_item']['ccb_total']); ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($info['cart_item']['ccb_calculator']['ccb_total'])): ?>
                            <div>ccb_calculator.ccb_total: <?php echo esc_html($info['cart_item']['ccb_calculator']['ccb_total']); ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($info['cart_item']['ccb_calculator']['woo_totals'])): ?>
                            <div>woo_totals: <?php echo esc_html(json_encode($info['cart_item']['ccb_calculator']['woo_totals'])); ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($info['cart_item']['ccb_calculator']['calc_data'])): ?>
                            <details>
                                <summary>calc_data (cliquer pour voir tout)</summary>
                                <pre><?php echo esc_html(print_r($info['cart_item']['ccb_calculator']['calc_data'], true)); ?></pre>
                            </details>
                        <?php endif; ?>
                        
                        <details>
                            <summary>Données POST actuelles</summary>
                            <pre><?php echo esc_html(print_r($_POST, true)); ?></pre>
                        </details>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <div class="cart-summary">
                <h4>🛒 Totaux du panier</h4>
                <div>Sous-total: <?php echo WC()->cart->get_subtotal(); ?>€</div>
                <div>Total: <?php echo WC()->cart->get_total(); ?></div>
                <div>Page actuelle: <?php echo is_cart() ? 'Panier' : (is_checkout() ? 'Commande' : (is_product() ? 'Produit' : get_post_type())); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Bouton flottant pour ouvrir le debug -->
    <div id="ccb-debug-trigger" onclick="toggleCCBDebug()">
        🔍 CCB Debug
    </div>
    
    <script>
        function toggleCCBDebug() {
            const debugPanel = document.getElementById('ccb-debug-footer');
            const trigger = document.getElementById('ccb-debug-trigger');
            
            if (debugPanel.style.display === 'none' || debugPanel.style.display === '') {
                debugPanel.style.display = 'block';
                trigger.style.display = 'none';
            } else {
                debugPanel.style.display = 'none';
                trigger.style.display = 'block';
            }
        }
    </script>
    
    <?php
}
add_action('wp_footer', 'ccb_footer_debug_banner');

// 8. CSS pour la bannière de debug flottante
function ccb_debug_footer_styles() {
    ?>
    <style>
        /* Bouton flottant de debug */
        #ccb-debug-trigger {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #007cba;
            color: white;
            padding: 12px 16px;
            border-radius: 25px;
            cursor: pointer;
            z-index: 9998;
            font-size: 12px;
            font-weight: bold;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            transition: all 0.3s ease;
        }
        
        #ccb-debug-trigger:hover {
            background: #005a87;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.4);
        }
        
        /* Panneau de debug */
        #ccb-debug-footer {
            position: fixed;
            bottom: 0;
            right: 0;
            width: 100%;
            max-width: 800px;
            max-height: 80vh;
            background: white;
            border: 2px solid #007cba;
            border-bottom: none;
            border-radius: 8px 0 0 0;
            z-index: 9999;
            font-family: 'Courier New', monospace;
            font-size: 11px;
            box-shadow: -5px -5px 20px rgba(0,0,0,0.3);
        }
        
        .ccb-debug-content {
            height: 100%;
            overflow-y: auto;
            padding: 0;
        }
        
        .debug-header {
            background: #007cba;
            color: white;
            padding: 10px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        
        .debug-header h3 {
            margin: 0;
            font-size: 14px;
        }
        
        .close-debug {
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            padding: 0;
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .close-debug:hover {
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
        }
        
        .debug-product-item {
            border-bottom: 1px solid #eee;
            padding: 15px;
            background: #f9f9f9;
        }
        
        .debug-product-item:nth-child(even) {
            background: #f5f5f5;
        }
        
        .debug-product-item h4 {
            margin: 0 0 10px 0;
            color: #333;
            font-size: 13px;
        }
        
        .price-comparison {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
            margin-bottom: 10px;
            padding: 8px;
            background: white;
            border-radius: 4px;
        }
        
        .current-price {
            background: #e3f2fd;
            padding: 4px 8px;
            border-radius: 3px;
            border-left: 3px solid #2196f3;
        }
        
        .calculated-price {
            background: #f3e5f5;
            padding: 4px 8px;
            border-radius: 3px;
            border-left: 3px solid #9c27b0;
        }
        
        .status {
            padding: 4px 8px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .status.success {
            background: #c8e6c9;
            color: #2e7d32;
        }
        
        .status.error {
            background: #ffcdd2;
            color: #c62828;
        }
        
        .status.warning {
            background: #fff3e0;
            color: #ef6c00;
        }
        
        .debug-data {
            background: white;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
        }
        
        .debug-data > div {
            margin: 5px 0;
            padding: 3px 6px;
            background: #f8f9fa;
            border-left: 2px solid #dee2e6;
        }
        
        .debug-data details {
            margin: 8px 0;
        }
        
        .debug-data details summary {
            cursor: pointer;
            background: #e9ecef;
            padding: 5px 8px;
            border-radius: 3px;
        }
        
        .debug-data details summary:hover {
            background: #dee2e6;
        }
        
        .debug-data pre {
            background: #f1f3f4;
            padding: 10px;
            border-radius: 3px;
            font-size: 10px;
            line-height: 1.3;
            overflow-x: auto;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .cart-summary {
            background: #e8f5e8;
            padding: 15px;
            border-top: 2px solid #4caf50;
        }
        
        .cart-summary h4 {
            margin: 0 0 10px 0;
            color: #2e7d32;
        }
        
        .cart-summary > div {
            margin: 5px 0;
            font-weight: bold;
        }
        
        /* Mobile responsive */
        @media (max-width: 768px) {
            #ccb-debug-footer {
                width: 100%;
                max-width: 100%;
                right: 0;
            }
            
            .price-comparison {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
        }
    </style>
    <?php
}
add_action('wp_head', 'ccb_debug_footer_styles');

// 9. Hook de secours pour forcer la recalculation
function force_cart_totals_recalculation() {
    if (is_cart() || is_checkout()) {
        add_action('wp_loaded', function() {
            if (WC()->cart && !WC()->cart->is_empty()) {
                WC()->cart->calculate_totals();
            }
        }, 20);
    }
}
add_action('template_redirect', 'force_cart_totals_recalculation');

// 10. Log les données CCB lors de l'ajout au panier (pour debug)
function log_ccb_data_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    if (isset($cart_item_data['ccb_calculator']) || isset($_POST['ccb_total'])) {
        $log_data = [
            'product_id' => $product_id,
            'post_data' => $_POST,
            'cart_item_data' => $cart_item_data
        ];
        error_log('CCB Add to Cart Data: ' . print_r($log_data, true));
    }
}
add_action('woocommerce_add_to_cart', 'log_ccb_data_on_add_to_cart', 10, 6);

?>