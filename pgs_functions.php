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

function only_one_product_store_allowed ($passed, $product_id, $quantity)
{
    // Descobre qual o ID do vendedor do produto
    $add_product_vendor_id = wcfm_get_vendor_id_by_post ($product_id);

    // Se o produto não está vinculado, não permite comprar
    if ($add_product_vendor_id == 0)
    {
        $msg = "O curso não está vinculado com nenhuma IC. Por favor entre em contato com a IC responsável.";
        wc_add_notice (sprintf (__("<span style='font-size:2.8rem;color:red;font-weight:500;'>$msg</span>", "woocommerce")), 'error' );
        return (false);
    }

    // Faz a validação da loja somente se o carrinho já tiver algum produto
    if (WC()->cart->get_cart_contents_count() != 0)
    {
        // Descobre qual o ID do vendedor do primeiro produto do carrinho de compras
        $cart_item = reset (WC()->cart->get_cart ());
        $product_id = $cart_item ['product_id'];
        $cart_vendor_id = wcfm_get_vendor_id_by_post ($product_id);

        // O vendedor do produto para adicionar é o mesmo?
        if ($add_product_vendor_id != $cart_vendor_id)
        {
            // Não, vamos emitir uma mensagem de erro
            $current_store = get_user_meta ($cart_vendor_id, 'store_name', true);
            $msg = "Você já está comprando da <strong>%s</strong>. Por favor, finalize seu pedido antes de comprar em outra Instituição/Loja.";
            wc_add_notice (sprintf (__("<span style='font-size:2.8rem;color:red;font-weight:500;'>$msg</span>", "woocommerce" ), $current_store), 'error');
            return (false);
        }
    }

    // Produto validado para ser adicionado
    return ($passed);
}

/* Filtro por marca
 * https://stackoverflow.com/questions/62372430/how-can-i-create-a-cart-for-each-brand-in-woocommerce
 */
function only_one_product_brand_allowed_BY_PRODUCT_BRAND( $passed, $product_id, $quantity) {
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
    // Se já definimos a loja ativa em outro hook, preserva
    if (!empty ($GLOBALS['PGS_CURRENT_STORE']))
    {
        global $PGS_CURRENT_STORE;
        pgs_log ('* Loja ativada: '.$PGS_CURRENT_STORE);
        //pgs_log ('* Loja ativada: '.$GLOBALS['PGS_CURRENT_STORE']);
        return;
    }

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

function payment_method_disabled ()
{
    return (array
    (
        'enabled' => 'no'
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
    $value = get_option ("pgs_${PGS_CURRENT_STORE}_$option", false);
    pgs_log ("get_option: RETURN=".print_r($value, true));

    // Se a opção não existe, o método retornado volta sempre desabilitado
    if ($value === false)
    {
        return (payment_method_disabled ());
    }
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
        'woocommerce_bacs_settings',            // Transferência Bancária
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

    $pedido_recebido = '/checkout/pedido-recebido/';

    // Processa exceção: rastrear order na página de confirmação do pedido.
    // Isso é necessário porque o pagamento feito via Transferência Bancária
    // mostra os dados de pagamento _após_ esvaziar o carrinho, então não
    // é possível inferir a loja usando o método tradicional. Contudo, a loja
    // fica registrada na Order e portanto é de lá que extraimos esse dado.
    // SE HOUVER OUTRA LOJA ATIVA, SOBRESCREVE. Esse comportamento é intencional
    // pois não queremos mostrar dados de pagamento da instituição errada.
    if (isset ($_SERVER['REQUEST_URI'])
        && strstr ($_SERVER['REQUEST_URI'], $pedido_recebido) !== false)
    {
        global $wpdb;

        $request_path = $_SERVER['REQUEST_URI'];
        $order_id = (int)substr ($request_path, strlen ($pedido_recebido));

        pgs_log ("* Rastreando order #$order_id");

        // O woocommerce ainda não inicializou 100%, então usamos sql direto
        $sql_vendor_id =
            "SELECT vendor_id FROM wp_wcfm_marketplace_orders WHERE order_id = $order_id";
        $vendor_id = absint ($wpdb->get_var ($sql_vendor_id));

        if ($vendor_id == 0)
        {
            pgs_log ("* Vendor id não encontrado");
        }
        else
        {
            pgs_log ("* Order vendor_id $vendor_id");

            // Recupera o profile da loja
            $store_profile_settings = get_user_meta ($vendor_id, 'wcfmmp_profile_settings', true);

            // Utilizamos o store_slug pois não contem acentuação ou carateres especiais,
            // além de permitir mais de um usuário administrando a loja
            if (!empty ($store_profile_settings)
                && array_key_exists ('store_slug', $store_profile_settings))
            {
                // Recuperamos a loja atual a partir do pedido!
                $PGS_CURRENT_STORE = strtoupper ($store_profile_settings ['store_slug']);
            }
        }
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
// GERENCIAMENTO DOS ATRIBUTOS DOS PRODUTOS
//=================================================================================================

function scan_products ()
{
    $TAXONOMY = 'product_brand';

    echo "------------ Processando produtos -------------\n";

    $produtos_atualizados = 0;

    $brand_product_args = array
    (
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'order' => 'desc',
        'orderby' => 'date'
    );

    $query_result = new WP_Query ($brand_product_args);

    if (empty ($query_result->posts))
    {
        echo "Nenhum produto encontrado\n";
        return;
    }

    $product_array = $query_result->posts;

    for ($i = 0; $i < count ($product_array); $i++)
    {
        $product = $product_array [$i];

        // Descobre qual o ID do vendedor do produto
        $vendor_id = wcfm_get_vendor_id_by_post ($product->ID);

        // O vendor_id existe porque o produto está associado com uma loja
        if ($vendor_id == 0)
        {
            continue;
        }

        // Recupera o profile da loja
        $store_profile_settings = get_user_meta ($vendor_id, 'wcfmmp_profile_settings', true);

        // Utilizamos o store_slug pois não contem acentuação ou carateres especiais,
        // além de permitir mais de um usuário administrando a loja
        if (empty ($store_profile_settings)
            || !array_key_exists ('store_slug', $store_profile_settings))
        {
            // A configuração está incompleta
            continue;
        }

        $vendor_name_slug = $store_profile_settings ['store_slug'];
        $brands = wp_get_post_terms ($product->ID, $TAXONOMY);
        $brand_ok = false;

        // Verifica se a marca associada a loja já está setada
        foreach ($brands as $brand)
        {
            if ($brand->slug == $vendor_name_slug)
            {
                $brand_ok = true;
                break;
            }
        }

        // Sim, não precisamos vincular o produto com a marca da loja
        if ($brand_ok)
        {
            continue;
        }

        echo "Processando produto #$product->ID ($vendor_name_slug): $product->post_title\n";

        $the_brand = get_term_by ('slug', $vendor_name_slug, $TAXONOMY);

        if (empty ($the_brand))
        {
            echo "Erro: Marca não encontrada - produto não será atualizado\n";
            continue;
        }

        echo "* Vinculando marca #$the_brand->term_id ($the_brand->name) no produto #$product->ID\n";
        wp_add_object_terms ($product->ID, $the_brand->term_id, $TAXONOMY);
        $produtos_atualizados++;
    }

    echo "------------ Produtos processados, $produtos_atualizados atualizados -------------\n";
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
    
//     echo "------------ IC: $IC -------------\n";
//     print_r ($settings);

    // Inicializa alguns campos opcionais
    if (!array_key_exists ('cielo_credit_interest', $settings)
        || empty ($settings ['cielo_credit_interest']))
    {
        if (array_key_exists ('cielo_credit_installments', $settings))
        {
            $settings ['cielo_credit_interest'] = $settings ['cielo_credit_installments'];
        }
        else
        {
            $settings ['cielo_credit_interest'] = '1';
        }
    }
    if (!array_key_exists ('cielo_credit_interest_rate', $settings)
        || empty ($settings ['cielo_credit_interest_rate']))
    {
        $settings ['cielo_credit_interest_rate'] = '0';
    }
    if (!array_key_exists ('cielo_credit_smallest_installment', $settings)
        || empty ($settings ['cielo_credit_smallest_installment']))
    {
        $settings ['cielo_credit_smallest_installment'] = '1';
    }

//     echo "------------ Registro ajustado  -------------\n";
//    print_r ($settings);

    foreach ($fields as $field)
    {
        if (!array_key_exists ($field, $settings))
        {
            echo "AVISO: Configuração $IC não possui campo '$field', ignorando\n";
            return;
        }
    }

    // Imprime o registro que está sendo sincronizado
//    echo "------------ Registro ajustado  -------------\n";
//    print_r ($settings);

    // Mágica: cria o registro compatível com o plugin
    $opt_value = array 
    (
        'imagem' => '',
        'enabled' => $settings ['cielo_credit_enabled'] == 1? 'yes': 'no',
        'title' => $settings ['cielo_credit_title'],
        'testmode' => strncasecmp ($settings ['cielo_credit_environment'], 'prod', 4)? 'yes': 'no',
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
        'debug' => 'yes' // debug é necessário para guardar os json das transações
    );

    // Nome da opção
    $opt_name = 'pgs_'.$IC.'_woocommerce_jrossetto_woo_cielo_webservice_settings';

    // Imprime o registro que está sendo gravado
    echo "------------ Registro gravado  -------------\n";
    print_r ($opt_value);

    // Grava
    update_option ($opt_name, $opt_value);
    echo "update_option $opt_name => ".serialize ($opt_value)."\n";
}

function grava_parametrizacao_paypal ($IC, $settings)
{
    $fields = array
    (
        'paypal_enable',
        'paypal_environment',
        'paypal_api_username',
        'paypal_api_password',
        'paypal_api_signature'
    );

    foreach ($fields as $field)
    {
        if (!array_key_exists ($field, $settings))
        {
            echo "AVISO: Configuração $IC não possui campo '$field', ignorando\n";
            return;
        }
    }

    // Mágica: cria o registro compatível com o plugin
    $opt_value = array
    (
        'enabled' => $settings ['paypal_enable'] == 1? 'yes': 'no',
        'reroute_requests' => 'no',
        'title' => 'PayPal',
        'description' => 'Pague com PayPal',
        'environment' => strtolower ($settings ['paypal_environment']),
        'api_username' => $settings ['paypal_api_username'],
        'api_password' => $settings ['paypal_api_password'],
        'api_signature' => $settings ['paypal_api_signature'],
        'api_certificate' => '',
        'api_subject' => '',
        'sandbox_api_username' => '',
        'sandbox_api_password' => '',
        'sandbox_api_signature' => '',
        'sandbox_api_certificate' => '',
        'sandbox_api_subject' => '',
        'brand_name' => $IC,
        'logo_image_url' => '',
        'header_image_url' => '',
        'page_style' => '',
        'landing_page' => 'Billing',
        'debug' => 'yes', // debug é necessário para guardar os json das transações
        'invoice_prefix' => $IC,
        'require_billing' => 'no',
        'require_phone_number' => 'no',
        'paymentaction' => 'sale',
        'instant_payments' => 'yes',
        'subtotal_mismatch_behavior' => 'add',
        'use_spb' => 'yes',
        'button_color' => 'gold',
        'button_shape' => 'rect',
        'button_label' => 'paypal',
        'button_layout' => 'vertical',
        'button_size' => 'responsive',
        'hide_funding_methods' => Array
        (
            0 => 'CARD'
        ),
        'credit_enabled' => 'no',
        'cart_checkout_enabled' => 'yes',
        'mini_cart_settings_toggle' => 'no',
        'mini_cart_button_layout' => 'vertical',
        'mini_cart_button_size' => 'responsive',
        'mini_cart_button_label' => 'paypal',
        'mini_cart_hide_funding_methods' => Array
        (
            0 => 'CARD'
        ),
        'mini_cart_credit_enabled' => 'no',
        'checkout_on_single_product_enabled' => 'no',
        'single_product_settings_toggle' => 'yes',
        'single_product_button_layout' => 'horizontal',
        'single_product_button_size' => 'responsive',
        'single_product_button_label' => 'paypal',
        'single_product_hide_funding_methods' => Array
        (
            0 => 'CARD'
        ),
        'single_product_credit_enabled' => 'no',
        'mark_enabled' => 'yes',
        'mark_settings_toggle' => 'no',
        'mark_button_layout' => 'vertical',
        'mark_button_size' => 'responsive',
        'mark_button_label' => 'paypal',
        'mark_hide_funding_methods' => Array
        (
            0 => 'CARD'
        ),
        'mark_credit_enabled' => 'no'
    );

    // Nome da opção
    $opt_name = 'pgs_'.$IC.'_woocommerce_ppec_paypal_settings';

    // Imprime o registro que está sendo gravado
    echo "------------ Registro gravado  -------------\n";
    print_r ($opt_value);

    // Grava
    update_option ($opt_name, $opt_value);
    echo "update_option $opt_name => ".serialize ($opt_value)."\n";
}

function grava_parametrizacao_transferencia_bancaria ($IC, $settings)
{
    $fields = array
    (
        'bacs_enable',
        'bacs_description'
    );

    foreach ($fields as $field)
    {
        if (!array_key_exists ($field, $settings))
        {
            echo "AVISO: Configuração $IC não possui campo '$field', ignorando\n";
            return;
        }
    }

    // Mágica: cria o registro compatível com o plugin
    $opt_value = array
    (
        'enabled' => $settings ['bacs_enable'] == 1? 'yes': 'no',
        'title' => 'Tranferência Bancária',
        'description' => 'Faça o pagamento diretamente na nossa conta bancária. Por favor use o número do pedido como identificação, se for possível. Seu pagamento será confirmado assim que o valor depositado for liberado pelo banco.',
        'instructions' => $settings ['bacs_description'],
        'account_details' => ''
    );

    // Nome da opção
    $opt_name = 'pgs_'.$IC.'_woocommerce_bacs_settings';

    // Imprime o registro que está sendo gravado
    echo "------------ Registro gravado  -------------\n";
    print_r ($opt_value);

    // Grava
    update_option ($opt_name, $opt_value);
    echo "update_option $opt_name => ".serialize ($opt_value)."\n";
}

function grava_parametrizacao ($IC, $settings)
{
    echo "============= Gravando parâmetros de Pagamento =============\n";
    print_r ($settings);

    grava_parametrizacao_cielo_credito ($IC, $settings);
    grava_parametrizacao_paypal ($IC, $settings);
    grava_parametrizacao_transferencia_bancaria ($IC, $settings);
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

    // Verifica quando uma mensagem de erro é retornada
    if (count ($payment_settings) == 1
        && array_key_exists ('Message', $payment_settings))
    {
        echo 'ERRO: API ICNet retornou: '.$payment_settings ['Message']."\n";
        print_r ($payment_settings);
        echo "---//---\n";
        return;
    }

    foreach ($payment_settings as $ic_settings)
    {
        if (!array_key_exists ('IC', $ic_settings))
        {
            echo "AVISO: Trecho de configuração não possui chave 'IC'\n";
            print_r ($ic_settings);
            echo "---//---\n";
            continue;
        }

        // Extrai a IC e as configurações
        $IC = $ic_settings ['IC'];

        if (!array_key_exists ('settings', $ic_settings))
        {
            echo "AVISO: Trecho de configuração não possui chave 'settings'\n";
            print_r ($ic_settings);
            echo "---//---\n";
            continue;
        }

        $settings = $ic_settings ['settings'][0];
    
        echo "Sincronizando IC: $IC\n";
    
        // Grava os parametros
        grava_parametrizacao ($IC, $settings);
    }

    echo "Sincronização ICNet - Finalizada\n";

    // Atualiza marcas e outros parâmetros nos produtos
    // NOTA: ATUALIZAÇÃO DE MARCAS VIA CRON DESATIVADA EM FAVOR DO HOOK
    // scan_products ();
}

//=================================================================================================
// PRODUTOS POR SKU
//=================================================================================================

function pgs_rewrite_rules ()
{
    add_rewrite_rule
    (
        '^sku/([^/]*)/?',   // store.conscienciologia.org.br/sku/999999
        'index.php?post_type=product&pgs_product_sku=$matches[1]',  // o link interno gerado
        'top'
    );
}

function pgs_register_query_vars ($vars)
{
    $vars[] = 'pgs_product_sku';
    return ($vars);
}

function pgs_get_product_by_sku ($wp)
{
    if (is_admin() || !$wp->is_main_query ())
    {
        return;
    }

    // Se tiver nossa variavel interna, é a busca pelo sku
    if (get_query_var ('pgs_product_sku' ))
    {
        $wp->query_vars['post_type'] = 'product';
        $wp->query_vars['is_single'] = true ;
        $wp->query_vars['is_singular'] = true;
        $wp->query_vars['is_archive'] = false;

        $product_sku = get_query_var ('pgs_product_sku' ) ;

        // Conscienciograma sem Drama :)
        if (function_exists ('wc_get_product_id_by_sku'))
        {
            $post_id = wc_get_product_id_by_sku ($product_sku);
            pgs_log ("### SKU $product_sku mapeado para $post_id");
        }
        else // SKU não encontrado
        {
            $post_id = 0;
        }

        // Set the post ID here. This makes the magic happen.
        $wp->query_vars['p'] = $post_id;

        // This also makes the magic happen. It forces the template I need to be selected.
        $wp->is_single = true ;
        $wp->is_singular = true ;
        $wp->is_archive = false ;
        $wp->is_post_type_archive = false ;
    }
}

function enable_product_by_sku ()
{
    //pgs_log ("* Mapeamento SKU-produto Habilitado");
    add_action ('init', 'pgs_rewrite_rules', 10, 0);
    add_filter ('query_vars', 'pgs_register_query_vars');
    add_action ('pre_get_posts', 'pgs_get_product_by_sku', 0, 2);
}

//=================================================================================================
// TRIGGER/HOOK PARA AJUSTAR LOJA E MARCA DOS PRODUTOS CRIADOS
//=================================================================================================

function update_product_brand ($product_id, $vendor_user)
{
    // A marca está vinculada diretamente no login do Vendor
    $vendor_name_slug = strtolower ($vendor_user->user_login);

    // Obtém a lista das marcas disponíveis para atribuir
    $brand_taxonomy = 'product_brand';
    $brands = wp_get_post_terms ($product_id, $brand_taxonomy);

    // Verifica se a marca associada a loja já está setada
    foreach ($brands as $brand)
    {
        if ($brand->slug == $vendor_name_slug)
        {
            // Retorna pois a marca já está configurada
            return;
        }
    }

    // Obtem a marca que será configurada no produto usando Vendor login
    $the_brand = get_term_by ('slug', $vendor_name_slug, $brand_taxonomy);

    if (empty ($the_brand))
    {
        pgs_log ("Erro: Marca não encontrada - produto não será atualizado");
        return;
    }

    pgs_log ("* Vinculando marca #$the_brand->term_id ($the_brand->name) no produto #$product_id");
    wp_add_object_terms ($product_id, $the_brand->term_id, $brand_taxonomy);
}

function verifica_loja_marca ($product)
{
    $product_id = $product->get_id ();
    $product_title = $product->get_name ();

    pgs_log ("### Verificando produto #$product_id: '$product_title'");

    $meta_shop_name = get_post_meta ($product_id, 'shop_name', true);

    // Confirma se o produto contem o metadado certo
    if (empty ($meta_shop_name))
    {
        pgs_log ("* Ignorando produto #$product_id - falta metadado 'shop_name'");
        return;
    }

    // Corrige espaços em branco para underline
    $meta_shop_name = str_replace (' ', '_', trim ($meta_shop_name));

    // Busca o usuario associado com a loja (Vendor)
    if (!empty ($user = get_user_by ('login', $meta_shop_name)))
    {
        // Identifica qual loja (Vendor) é dona do produto (Post)
        $post_author = get_post_field ('post_author', $product_id);

        // Se forem diferentes, é preciso gravar o Vendor certo no produto
        if ($post_author != $user->ID)
        {
            pgs_log ("* Ajustando produto #$product_id para loja #$post_author/$user->ID: $user->user_login");

            // Atualiza o autor do post para o respectivo Vendor
            $author_update = array
            (
                'ID' => $product_id,
                'post_author' => $user->ID
            );
            wp_update_post ($author_update);
        }

        // Nós temos a loja, vamos verificar a marca
        update_product_brand ($product_id, $user);
    }
    else
    {
        pgs_log ("Erro: Usuário da loja não encontrado: $meta_shop_name");
    }
}

function pgs_woocommerce_rest_prepare_product_object_hook ($response, $product, $request)
{
    verifica_loja_marca ($product);

    //#######################################################
    // Importante! Esta resposta é o retorno da chamada REST
    //#######################################################
    return ($response);
}

function XXX_pgs_woocommerce_rest_prepare_product_object_hook ($response, $product, $request)
{
    pgs_log ("############# HOOK ##############");
    pgs_log ("### RESPONSE".print_r ($response, true));
    pgs_log ("### PRODUCT".print_r ($product, true));
    pgs_log ("### REQUEST".print_r ($request, true));
    $product_id = $product->get_id ();
    pgs_log ("### PRODUCT ID = ".$product_id);
    pgs_log ("### META = ".get_metadata ('post', $product_id, 'shop_name', true));    
    pgs_log ("### META: ".get_post_meta ($product_id, 'shop_name', true));
    
    $meta_shop_name = get_post_meta ($product_id, 'shop_name', true);

    // Confirma se o produto contem o metadado certo
    if (empty ($meta_shop_name))
    {
        pgs_log ("* Ignorando produto #$product_id - falta metadado 'shop_name'");
        return;
    }
    
    return ($response);
}

function enable_woocommerce_rest_prepare_product_object_hook ()
{
    add_filter ('woocommerce_rest_prepare_product_object', 
        'pgs_woocommerce_rest_prepare_product_object_hook', 10, 3 );
}

//=================================================================================================
// AUTO-COMPLETE ORDERS
//=================================================================================================

// Fonte: https://docs.woocommerce.com/document/automatically-complete-orders/
function custom_woocommerce_auto_complete_order ($order_id)
{
    if (!$order_id)
    {
        return;
    }

    $order = wc_get_order ($order_id);
    $order->update_status ('completed');
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

    // Adiciona os hooks para ativar o pagamento inteligente
    add_filter ('woocommerce_add_to_cart_validation', 'only_one_product_store_allowed', 20, 3);
    add_action ('woocommerce_cart_loaded_from_session', 'pgs_woocommerce_cart_loaded_from_session');
    add_action ('woocommerce_loaded', 'pgs_woocommerce_loaded');

    // Adiciona filtro para url de produtos usando SKU
    enable_product_by_sku ();

    // Adiciona os hooks para criação de produto via ICNet
    enable_woocommerce_rest_prepare_product_object_hook ();

    // Adiciona action para finalizar automaticamente os pedidos
    add_action ('woocommerce_thankyou', 'custom_woocommerce_auto_complete_order');

    // Endereços de teste
    if ($_SERVER['REMOTE_ADDR'] == "177.66.73.167" ||
        $_SERVER['REMOTE_ADDR'] == "177.66.75.234")
    {
        if (WP_DEBUG_LOG)
        {
            // Codigo deixado aqui para permitir testes especificos no futuro
            //add_filter ('woocommerce_add_to_cart_validation', 'only_one_product_store_allowed', 20, 3);
            //add_action ('woocommerce_cart_loaded_from_session', 'pgs_woocommerce_cart_loaded_from_session');
            //add_action ('woocommerce_loaded', 'pgs_woocommerce_loaded');
        }
    }
}
enable_hooks();
