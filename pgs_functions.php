<?php

// Necessario habilitar em wp-includes/functions.php usando:
// require ABSPATH . 'wp-content/payment_gateway_switcher/pgs_functions.php';

/* Filtro por marca
 * https://stackoverflow.com/questions/62372430/how-can-i-create-a-cart-for-each-brand-in-woocommerce
 */
add_filter( 'woocommerce_add_to_cart_validation', 'only_one_product_brand_allowed', 20, 3 );
function only_one_product_brand_allowed( $passed, $product_id, $quantity) {
    $taxonomy    = 'product_brand';
    $field_names = array( 'fields' => 'names');

    // Getting the product brand term name for the current product
    if( $term_name = wp_get_post_terms( $product_id, $taxonomy, $field_names ) ) {
        $term_name = reset($term_name);
    } else return $passed;


    // Loop through cart items
    foreach (WC()->cart->get_cart() as $cart_item ){
        // Get the cart item brand term name
        if( $item_term_name = wp_get_post_terms( $cart_item['product_id'], $taxonomy, $field_names ) ) {
            $item_term_name = reset($item_term_name);
        } else continue;

        // Check if the product brand of the current product exist already in cart items
        if( isset($term_name) && isset($item_term_name) && $item_term_name !== $term_name ){
            // Displaying a custom notice 
            wc_add_notice( sprintf( __("<span style='font-size:2.8rem;color:red;font-weight:500;'>Você já está comprando da <strong>%s</strong>. Por favor, finalize seu pedido antes de comprar em outra IC.</span>", "woocommerce" ), $item_term_name ), 'error' );

            // Avoid add to cart and display message
            return false;
        }
    }
    return $passed;
}

function pgs_log ($message)
{
    $log_file_path = "/srv/www/store.conscienciologia.org.br/log/log-hook-calls.log";
    error_log (date ("d-m-Y, H:i:s") . ": " . $message . "\n", 3, $log_file_path);
}

// [1] IDENTIFICA QUAL É A LOJA ATIVA E GUARDA NO BANCO DE DADOS
function pgs_woocommerce_cart_loaded_from_session ()
{
    if (WC()->cart->get_cart_contents_count() != 0)
    {
        $taxonomy    = 'product_brand';
        $field_names = array( 'fields' => 'names');
        $cart_item = reset (WC()->cart->get_cart ());
        
        if ($item_term_name = wp_get_post_terms ($cart_item ['product_id'], $taxonomy, $field_names))
        {
            global $PGS_CURRENT_STORE;
            
            $PGS_CURRENT_STORE = reset ($item_term_name);
            pgs_log ('Loja ativa: '.$PGS_CURRENT_STORE);
 
            $cart_hash = WC()->cart->get_cart_hash();
            add_option ("pgs_${cart_hash}", $PGS_CURRENT_STORE, '', false);
            return;
        }
    }

    pgs_log ('Carrinho vazio - nenhuma loja ativa');
}

function pgs_hook_pre_option ($value, $option, $default)
{
    global $PGS_CURRENT_STORE;

    // O hook pre_option permite retornar um valor qualquer na função 
    // get_option(), antes mesmo de consultar o banco de dados.
    // Esse mecanismo é usado no aqui para simular ausência de configuração
    // caso não exista nenhuma loja selecionada, para isso retornando
    // sempre os valores informados como $default.
    
    pgs_log ("pgs_hook_pre_option: $PGS_STORE_ADMIN value=[$value] option=[$option] default=[$default]");
    
    if (empty ($PGS_CURRENT_STORE))
    {
        // Se não há nenhuma loja ativa, retorna a opção default,
        // mesmo que exista um registro válido para a opção no banco de dados
        pgs_log ("Nenhuma loja ativa, retornando default");
        return ($default);
    }
    
    // Existe uma loja ativa, então retorna o valor mapeado. No caso especial 
    // do valor retornado ser false, o wordpress vai seguir adiante e poderia
    // retornar um valor incorreto. Isso é tratado retornando false no hook
    // get_option sempre.
    pgs_log ("get_option: pgs_${PGS_CURRENT_STORE}_$option");
    $value = get_option ("pgs_${PGS_CURRENT_STORE}_$option", $default);    
    pgs_log ("get_option: RETURN=".print_r($value, true));    
    return ($value);
}

function pgs_hook_option ($value, $option)
{
    global $PGS_CURRENT_STORE;

    pgs_log ("pgs_get_option: $PGS_CURRENT_STORE FALSE option=[$option] value=[$value]");    
    return (false);
}

function pgs_update_option ()
{

}

function pgs_add_option ()
{

}

function pgs_setup_option_filters ($store_name)
{
    $option_list = array 
    (
        'woocommerce_ppec_paypal_settings',
        'woocommerce_rede_credit_settings',
        'pp_woo_liveApiCredentials'
    );

    foreach ($option_list as $option)
    {
        add_filter ('pre_option_'.$option, pgs_hook_pre_option, 10, 3);
        add_filter ('option_'.$option, pgs_hook_option, 10, 2);
        
        wp_cache_delete ($option, 'options');
    }
}

// [2] TENTA CARREGAR DO BANCO DE DADOS A LOJA ATIVA, O MAIS CEDO POSSIVEL
function pgs_woocommerce_loaded ()
{
    global $PGS_CURRENT_STORE;

    // O hook woocommerce_loaded é processado no momento que o woocommerce 
    // está inicializando, antes de varrer os gateways de pagamento, portanto
    // é o momento ideal para determinar se há uma loja ativa ou não.
    
    // Precisamos do usuário para tratar corretamente os administradores
    $user = wp_get_current_user ();

    //pgs_log ("pgs_woocommerce_loaded - USERDATA: ".print_r ($user, true));

    // Quando o usuario é administrador da loja nós recuperamos
    // o nome da loja administrada de 'display_name', ao invés do
    // carrinho de compras no caso de um usuário comum.
    if (!empty ($user) && $user->caps ['shop_manager'] == 1)
    {
        $user_email = $user->data->user_email;
    
        // Usamos o DisplayName para indicar a loja administrada
        $PGS_CURRENT_STORE = $user->data->display_name;
        pgs_log ("USER $user_email IS WOOCOMMERCE ADMIN FOR $PGS_CURRENT_STORE");
        
        // Ativa os filtros
        pgs_setup_option_filters ($PGS_CURRENT_STORE);
        return;
    }

    // No caso de um usuário comum ou não logado, nós determinamos a loja
    // ativa a partir dos produtos no carrinho de compras e deixamos ela 
    // gravada no banco de dados usando o próprio hash do carrinho de compras.    
    if (isset ($_COOKIE['woocommerce_cart_hash']))
    {
        // Obtém o hash do carrinho de compras
        $cart_hash = $_COOKIE['woocommerce_cart_hash'];
        
        // E tenta recuperar a loja ativa
        $PGS_CURRENT_STORE = get_option ("pgs_${cart_hash}");
    }
    else
    {
        // Nenhuma loja ativa
        $PGS_CURRENT_STORE = false;
    }
    
    if (!empty ($PGS_CURRENT_STORE))
    {
        // Ativa os filtros para compra
        pgs_setup_option_filters ($PGS_CURRENT_STORE);
    }
    
    pgs_log ("pgs_woocommerce_loaded - CURRENT_STORE: ".$PGS_CURRENT_STORE);
}

// [3] INFORMA QUAL É A LOJA ATIVA CONFORME GRAVADO NO BANCO DE DADOS
// function pgs_option_woocommerce_ppec_paypal_settings ($value)
// {
//     global $PGS_CURRENT_STORE;
//     
//     pgs_log ('woocommerce_ppec_paypal_settings: CURRENT_STORE = '.$PGS_CURRENT_STORE);
//     return ($value);
// }

function seleciona_meios_pagamento ()
{
    $taxonomy    = 'product_brand';
    $field_names = array( 'fields' => 'names');

    $cart_item = reset (WC()->cart->get_cart ());
    pgs_log ('select: '.print_r($cart_item, true));
    if( $item_term_name = wp_get_post_terms( $cart_item['product_id'], $taxonomy, $field_names ) ) {
        $item_term_name = reset($item_term_name);
    }

    pgs_log ('seleciona_meios_pagamento: '.$item_term_name);
}

function pgs_pre_http_request ($response, array $args, $url )
{
    pgs_log ("-------------- CALL: $url");
    return (false);
}

function log_hook_calls()
{
    pgs_log (current_filter ());
}

// Main processing: https://roots.io/routing-wp-requests/
function pgs_do_parse_request ($continue, $wp, $extra_query_vars)
{
    $request_path = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    pgs_log ("############################################ REQUEST: $request_path");
    return ($continue);
}

function enable_hooks ()
{
    $client_ip = "177.66.75.171";
    
    if ($_SERVER['REMOTE_ADDR'] == $client_ip) 
    {
        if ( WP_DEBUG_LOG ) 
        {
            add_action ('woocommerce_cart_loaded_from_session', 'pgs_woocommerce_cart_loaded_from_session');
            add_action ('woocommerce_loaded', 'pgs_woocommerce_loaded');
        }
    }
}
enable_hooks();
