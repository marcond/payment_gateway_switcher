<?php

// Informações úteis:
//
// [1]
// Necessario habilitar em wp-includes/functions.php usando:
// require ABSPATH . 'wp-content/payment_gateway_switcher/pgs_functions.php';
//
// [2]
// Sobre o checkout Cielo:
// Foi necessário registrar a opção 'woocommerce_force_ssl_checkout' para
// 'yes', mesmo a Store já estando em HTTPS, caso contrário o pagamento via
// cartão nunca é habilitado devido a uma checagem em:
//      class-wc-cielo-helper.php -> checks_for_webservice()

function pgs_log ($message)
{
    $log_file_path = "/srv/www/store.conscienciologia.org.br/log/pgs.log";
    error_log (date ("d-m-Y, H:i:s") . ": " . $message . "\n", 3, $log_file_path);
}

function pgs_test ()
{
    return ("PGS disponivel");
}

//=================================================================================================
// FILTRAGEM DO CARRINHO POR LOJA ATIVA
// Permite adicionar no carrinho somente produtos da mesma loja.
//=================================================================================================

/* Filtro por marca
 * https://stackoverflow.com/questions/62372430/how-can-i-create-a-cart-for-each-brand-in-woocommerce
 */

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

//=================================================================================================
// CARGA DAS CONFIGURAÇÕES DE PAGAMENTO DO ICNET
//=================================================================================================

function icnet_api ($params)
{
    $ICNET_URL = 'https://icnetapi.azurewebsites.net/api/woocommerce/';

    // Constrói e realiza a chamada remota para API
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $ICNET_URL . '/' . $params);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $output = curl_exec($curl);
    curl_close($curl);

    //debug ("==> output >$output<");

    // Respostas vazias são sinalizadas como ""
    if ($output == '""')
    {
        return (false);
    }
    
    // Decodifica um json válido
    $json = json_decode (trim (stripcslashes ($output), '"'), true);

    // Se o json estiver corrompido json_decode retorna NULL
    if ($json == NULL)
    {
        return (false);
    }
    
    // Retorna os dados da API já decodificados
    return ($json);
}

function icnet_getSettingsPayments ()
{
    return (icnet_api ("getSettingsPayments"));
}

//=================================================================================================
// FILTRAGEM DOS PARÂMETROS DE PAGAMENTO
// Filtra a configuração dos meios de pagamento de acordo com a loja ativa.
//=================================================================================================

// [1] IDENTIFICA QUAL É A LOJA ATIVA E GUARDA NO BANCO DE DADOS
// A loja ativa fica guardada no banco pois o shopping cart só é carregado
// muito tempo depois pelo woocommerce, _após_ a carga dos parâmetros dos
// meios de pagamento. Assim, é importante sabermos _antes_ de carregar os
// parâmetros dos meios de pagamento qual é a loja ativa, e fazemos isso aqui.
//
function pgs_woocommerce_cart_loaded_from_session ()
{
    if (WC()->cart->get_cart_contents_count() != 0)
    {
        // Pega o primeiro produto presente no carrinho de compras
        $cart_item = reset (WC()->cart->get_cart ());
        $product_id = $cart_item ['product_id'];

        // Descobre qual o ID do vendedor do produto
        $vendor_id = wcfm_get_vendor_id_by_post ($product_id);

        // O vendor_id existe porque o produto está associado com uma loja
        if ($vendor_id != 0)
        {
            global $PGS_CURRENT_STORE;

            $PGS_CURRENT_STORE = strtoupper (get_user_meta ($vendor_id, 'store_name', true));
            pgs_log ('Loja ativa: '.$PGS_CURRENT_STORE);

            $cart_hash = WC()->cart->get_cart_hash();
            add_option ("pgs_cart_${cart_hash}", $PGS_CURRENT_STORE, '', false);
            return;
        }
        else
        {
            // Alguém criou o produto e esqueceu de vincular numa loja (Product data/Loja)
            pgs_log ("AVISO: Produto $product_id sem loja vinculada!");
        }
    }

    pgs_log ('Carrinho vazio - nenhuma loja ativa');
}

// Função deprecada
function pgs_woocommerce_cart_loaded_from_session_BY_PRODUCT_BRAND ()
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

function default_woocommerce_gateway_order ()
{
    return (array
    (
        'jrossetto_woo_cielo_webservice' => 0,
        'jrossetto_woo_cielo_webservice_debito' => 1,
        'rede_credit' => 2,
        'ppec_paypal' => 3,
        'pagseguro' => 4,
        'jrossetto_woo_cielo_webservice_boleto' => 5,
        'jrossetto_woo_cielo_webservice_tef' => 6,
        'paypal' => 7,
        'bacs' => 8,
        'cheque' => 9,
        'cod' => 10,
        'yith-paypal-ec' => 11,
        'cielo_credit' => 12,
        'cielo_debit' => 13
    ));
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
        pgs_log ("############################################ Nenhuma loja ativa, retornando default");
        return ($default);
    }
    
    if ($option == 'woocommerce_gateway_order')
    {
        // Retornamos sempre uma ordem padronizada dos meios de pagamento
        return (default_woocommerce_gateway_order ());
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

    pgs_log ("pgs_get_option: ############################################ $PGS_CURRENT_STORE FALSE option=[$option] value=[$value]");

    // Trata ordem dos gateways
    if ($option == 'woocommerce_gateway_order')
    {
        // Precisamos de uma ordem dos gateways válida para fechar o pedido
        pgs_log ("get_option: retornando woocommerce_gateway_order válido");
        return ($value);
    }    
    return (false);
}

function pgs_pre_update_option ($value, $old_value, $option)
{
    global $PGS_CURRENT_STORE;
    
    pgs_log ("pgs_pre_update_option: $PGS_STORE_ADMIN value=[$value] option=[$option] old_value=[$old_value]");
    
    if (empty ($PGS_CURRENT_STORE))
    {
        pgs_log ("############################################ Nenhuma loja ativa, atualizando normalmente");
        return ($value);
    }
    
    // Grava o valor vinculado à loja
    update_option ("pgs_${PGS_CURRENT_STORE}_${option}", $value);
    
    // Retorna o valor antigo, efetivamente evitando gravar o valor normal
    return ($old_value);
}

// Aparentemente add_option() não é utilizada no woocommerce nem nos plugins
//
function pgs_add_option ($option, $value)
{
    global $PGS_CURRENT_STORE;
    
    pgs_log ("pgs_add_option: $PGS_STORE_ADMIN value=[$value] option=[$option] ##EXCEPTION##");
    
    if (empty ($PGS_CURRENT_STORE))
    {
        pgs_log ("############################################ Nenhuma loja ativa, atualizando normalmente");
        return ($value);
    }
    
    // Grava o valor vinculado à loja
    update_option ("pgs_${PGS_CURRENT_STORE}_${option}", $value);
}

function pgs_setup_option_filters ($store_name)
{
    $option_list = array 
    (
        'woocommerce_cielo_debit_settings',     // Cielo - Cartão de débito
        'woocommerce_cielo_credit_settings',    // Cielo - Cartão de crédito
        'woocommerce_pagseguro_settings',       // PagSeguro
        'woocommerce_rede_credit_settings',     // Pague com a Rede
        'woocommerce_paypal_settings',          // PayPal Standard
        'woocommerce_ppec_paypal_settings',     // PayPal Checkout
        'woocommerce_jrossetto_woo_cielo_webservice_settings', // Cielo - API 3.0
        'pp_woo_liveApiCredentials',
        'woocommerce_gateway_order'
    );

    foreach ($option_list as $option)
    {
        // Estes são os filtros de leitura
        add_filter ('pre_option_'.$option, 'pgs_hook_pre_option', 10, 3);
        add_filter ('option_'.$option, 'pgs_hook_option', 10, 2);
        
        // Este é o filtro de gravação
        add_filter ('pre_update_option_'.$option, 'pgs_pre_update_option', 10, 3);
        
        // Este filtro NÃO é usado, mas é suportado
        add_filter ('add_option'.$option, 'pgs_add_option', 10, 2);
        
        wp_cache_delete ($option, 'options');
    }
    
    pgs_log ("pgs_setup_option_filters - Pagamentos configurados: ".$PGS_CURRENT_STORE);
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
        $PGS_CURRENT_STORE = get_option ("pgs_cart_${cart_hash}");
    }
    else
    {
        // Nenhuma loja ativa
        $PGS_CURRENT_STORE = false;
    }

    pgs_log ("pgs_woocommerce_loaded - CURRENT_STORE: ".$PGS_CURRENT_STORE);

    if (!empty ($PGS_CURRENT_STORE))
    {
        // Existe uma loja ativa, portanto carrega as configurações de pagamento
        // existentes para a loja. Aqui é onde a mágica efetivamente entra em ação.
        pgs_setup_option_filters ($PGS_CURRENT_STORE);
    }    
}

function pgs_pre_http_request ($response, array $args, $url )
{
    pgs_log ("-------------- CALL: $url");
    return (false);
}

// Main processing: https://roots.io/routing-wp-requests/
function pgs_do_parse_request ($continue, $wp, $extra_query_vars)
{
    $request_path = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    pgs_log ("############################################ REQUEST: $request_path");
    return ($continue);
}

//=================================================================================================
// TOP SIDEBAR
//=================================================================================================

function pgs_wid_content_html ()
{
    global $PGS_CURRENT_STORE;
    
    if (empty ($PGS_CURRENT_STORE))
    {        
        // Retorna um globo sem mais informações
        return ('<span title="Pagamento Inteligente ativo">'.
                '<img draggable="false" role="img" class="emoji" alt="&#127760;" src="https://s.w.org/images/core/emoji/13.0.0/svg/1f310.svg">'.
                '</span>');
    }
    
    // Retorna um cartãozinho e o nome da loja
    return ('<span title="Pagamentos para '.$PGS_CURRENT_STORE.'">' .
            '<img draggable="false" role="img" class="emoji" alt="&#128179;" src="https://s.w.org/images/core/emoji/13.0.0/svg/1f4b3.svg">' .
            $PGS_CURRENT_STORE.
            '</span>');
}

//=================================================================================================
// CRON
//=================================================================================================

function grava_parametrizacao_cielo_credito ($IC, $settings)
{
    $fields = array
    (
        'cielo_credit_enabled',
        'cielo_credit_title',
        'cielo_credit_environment',
        'cielo_credit_number',
        'cielo_credit_key',
        'cielo_credit_methods',
        'cielo_credit_installments',
        'cielo_credit_interest',
        'cielo_credit_interest_rate',
        'cielo_credit_smallest_installment',
        'cielo_credit_installment_type'
    );
    
    foreach ($fields as $field)
    {
        if (!array_key_exists ($field, $settings))
        {
            echo "AVISO: Configuração $IC não possui campo '$field', ignorando\n";
            return;
        }
    }

    $opt_value = array 
    (
        'imagem' => '',
        'enabled' => $settings ['cielo_credit_enabled'] == 1? 'yes': 'no',
        'title' => $settings ['cielo_credit_title'],
        'testmode' => $settings ['cielo_credit_environment'] == 'Produção'? 'no': 'yes',
        'afiliacao_details' => '',
        'afiliacao' => $settings ['cielo_credit_number'], // Merchant ID
        'chave' => $settings ['cielo_credit_key'], // Merchant Key
        'softdescriptor' => substr ('MEGA '.$IC, 0, 13), // Máximo 13 alfabeticos
        'meios' => preg_split ('/[\s]+/', 
            str_replace (array ('[', ']'), " ", $settings ['cielo_credit_methods']),
            -1, PREG_SPLIT_NO_EMPTY),
        'autenticacao' => 'false',
        'antifraude_details' => '',
        'antifraude' => 'nao',
        'verificaip' => '3',
        'div' => ''.$settings ['cielo_credit_installments'],
        'sem' => ''.$settings ['cielo_credit_interest'],
        'juros' => ''.$settings ['cielo_credit_interest_rate'],
        'minimo' => ''.$settings ['cielo_credit_smallest_installment'],
        'parcelamento' => $settings ['cielo_credit_installment_type'] == 'client'? 'operadora': 'loja',
        'captura' => 'automatica',
        'aguardando' => 'wc-pending',
        'pago' => 'wc-completed',
        'autorizado' => 'wc-processing',
        'cancelado' => 'wc-cancelled',
        'negado' => 'wc-failed',
        'debug' => 'no'
    );

    // Nome da opção
    $opt_name = 'pgs_'.$IC.'_woocommerce_jrossetto_woo_cielo_webservice_settings';
    
    // Grava
    update_option ($opt_name, $opt_value);
    echo "update_option $opt_name => ".serialize ($opt_value)."\n";
}

function grava_parametrizacao ($IC, $settings)
{
    grava_parametrizacao_cielo_credito ($IC, $settings);
}

function pgs_cron ()
{
    echo "Sincronização ICNet - Pagamento Inteligente\n";
    
    $payment_settings = icnet_getSettingsPayments ();

    if (empty ($payment_settings))
    {
        echo "AVISO: Retorno do ICNet vazio";
        return;
    }
    
    foreach ($payment_settings as $ic_settings)
    {
        // Extrai a IC e as configurações
        $IC = $ic_settings ['IC'];
        $settings = $ic_settings ['settings'][0];
    
        echo "Sincronizando IC: $IC\n";
    
        // Grava os parametros
        grava_parametrizacao ($IC, $settings);
    }

    echo "Sincronização ICNet - Finalizada\n";
}

//=================================================================================================
// INICIALIZAÇÃO
//=================================================================================================

function enable_hooks ()
{
    if (php_sapi_name () === 'cli')
    {
        // Rodando localmente, PGS desativado mas Cron ativo
        add_action ('pgs_cron', 'pgs_cron');
        return;
    }

    // O icone do PGS sempre fica ativo, independente de testes
    add_action ('pgs_wid_content', 'pgs_wid_content_html');

    // Endereços de teste
    if ($_SERVER['REMOTE_ADDR'] == "177.66.73.167" ||
        $_SERVER['REMOTE_ADDR'] == "177.66.75.234")
    {
        if (WP_DEBUG_LOG)
        {
            add_filter ('woocommerce_add_to_cart_validation', 'only_one_product_brand_allowed', 20, 3);
            add_action ('woocommerce_cart_loaded_from_session', 'pgs_woocommerce_cart_loaded_from_session');
            add_action ('woocommerce_loaded', 'pgs_woocommerce_loaded');
        }
    }
}
enable_hooks();
