<?php

header ('Content-Type: text/plain; charset=utf-8');
require_once(dirname(__FILE__) . '/../../../wp-config.php');
require_once (dirname (__FILE__) . '/api-keys.conf');
require_once (dirname (__FILE__) . '/api-settings.conf');

// Estes sao os formatos de arquivo aceitos, se necessario
// basta modificar esta tabela para adicionar ou retirar tipos
// 
$FORMATOS_ACEITOS = array
(
    'image/jpeg',
    'image/png',
    'image/gif'
);

// Possiveis erros
$ERR_PARAMETROS_INVALIDOS = 1;
$ERR_CHAVE_INVALIDA = 2;
$ERR_WC_STORE_INVALIDO = 3;
$ERR_POST_SEM_ARQUIVO = 4;
$ERR_POST_TAMANHO_EXCEDIDO = 5;
$ERR_POST_ERRO_DESCONHECIDO = 6;
$ERR_TAMANHO_IMAGEM_EXCEDIDO = 7;
$ERR_TIPO_ARQUIVO_INVALIDO = 8;
$ERR_FALHA_CRIACAO_DIRETORIO = 9;
$ERR_FALHA_COPIA_ARQUIVO = 10;

try
{
    // Nao sabemos mime ou nome ainda
    $mime_type = "";
    $file_size = -1;
    $file_name = "";

    // Valida os parametros necessarios
    if (!isset ($_POST['api-id'])
      	|| !isset ($_POST['api-key'])
        || !isset ($_POST['wc-store'])
        || !isset($_FILES ['file']['error'])
        || is_array($_FILES ['file']['error']))
    {
        throw new RuntimeException ('Parametros invalidos', $ERR_PARAMETROS_INVALIDOS);
    }

    // Verifica se a chave esta cadastrada
    $identity = array_search ($_POST ['api-key'], $API_KEYS, true);

    // Valida a identidade
    if ($identity === false || $identity != $_POST ['api-id'])
    {
        throw new RuntimeException ('Parametros invalidos', $ERR_CHAVE_INVALIDA);
    }

    $wc_store = strtolower ($_POST ['wc-store']);

    // Valida wc_store (apenas alfanumericos permitidos)
    if (!ctype_alnum ($wc_store))
    {
        throw new RuntimeException ("Caracteres invalidos em 'wc-store'", $ERR_WC_STORE_INVALIDO);
    }

    // $file tem os dados do arquivo enviado
    $file = $_FILES ['file'];
    $file_name = $file ['name'];
    $file_tmp_name = $file ['tmp_name'];
    $file_size = $file ['size'];
    $file_error = $file ['error'];

    // Verifica se ocorreram erros no upload
    switch ($file_error)
    {
        case UPLOAD_ERR_OK:
        {
            // Upload bem sucedido
            break;
        }
        case UPLOAD_ERR_NO_FILE:
        {
            throw new RuntimeException ('Nenhum arquivo enviado', $ERR_POST_SEM_ARQUIVO);
        }
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
        {
            throw new RuntimeException ('Tamanho maximo de upload excedido', $ERR_POST_TAMANHO_EXCEDIDO);
        }
        default:
        {
            throw new RuntimeException ("Falha desconhecida: $file_error", $ERR_POST_ERRO_DESCONHECIDO);
        }
    }

    // Como sao imagens para o site, limita o arquivo em 5Mb
    if ($file_size > 5000000)
    {
        throw new RuntimeException ('Tamanho maximo de armazenamento excedido', $ERR_TAMANHO_IMAGEM_EXCEDIDO);
    }

    $file_mime_type = mime_content_type ($file_tmp_name);

    // Verifica se eh um arquivo com tipo valido
    if (!in_array ($file_mime_type, $FORMATOS_ACEITOS))
    {
        throw new RuntimeException ('Tipo de arquivo invalido', $ERR_TIPO_ARQUIVO_INVALIDO);
    }

    // Diretorios onde o arquivo sera armazenado
    $dest_file_path = "$identity/$wc_store";
    $dest_file_dir  = "$API_BASE_DIR/$API_DATA_DIR/$dest_file_path";

    // Garante que o diretorio exista
    if (!is_dir ($dest_file_dir)
        && !mkdir ($dest_file_dir, 0775, true))
    {
        throw new RuntimeException ('Falha criando diretorios', $ERR_FALHA_CRIACAO_DIRETORIO);
    }

    // Nome final do arquivo
    if (($dot = strrpos ($file_name, '.')) !== false)
    {
        $just_name = substr ($file_name, 0, $dot);
    }
    else
    {
        $just_name = $file_name;
    }
    $file_ext = substr ($file_mime_type, strpos ($file_mime_type, '/') + 1);
    $dest_file_name = sanitize_title ($just_name) . '.' . $file_ext;
    $dest_file = "$dest_file_dir/$dest_file_name";

    if (!move_uploaded_file ($file_tmp_name, $dest_file))
    {
        throw new RuntimeException ('Falha ao armazenar o arquivo', $ERR_FALHA_COPIA_ARQUIVO);
    }

    // Arquivo armazenado com sucesso
    $result_code = 0;
    $result_message = "Sucesso";
    $imagem_url = "$API_BASE_URL/$API_DATA_DIR/$dest_file_path/$dest_file_name";
    $imagem_path = $dest_file;
}
catch (RuntimeException $e)
{
    // Houve algum erro armazenando o arquivo
    $result_message = 'Erro: '.$e->getMessage ();
    $result_code = $e->getCode ();
    $imagem_path = "";

    // Se tivermos uma imagem padrao para erro, retorna a url dela
    if (strstr (dirname (__FILE__), $API_BASE_DIR) !== false)
    {
        $error_file_path = substr (dirname (__FILE__), strlen ($API_BASE_DIR));
        $imagem_url = "$API_BASE_URL$error_file_path/image_error.png";
    }
    else
    {
        $imagem_url = "";
    }
}

// Gera o resultado da chamada
printf ("[{\n");
printf ("  \"%s\": \"%d\",\n", "resultCode", $result_code);
printf ("  \"%s\": \"%s\",\n", "resultMessage", $result_message);
printf ("  \"%s\": \"%s\",\n", "fileUrl", $imagem_url);
printf ("  \"%s\": \"%s\",\n", "filePath", $imagem_path);
printf ("  \"%s\": %s,\n", "fileName", json_encode ($file_name));
printf ("  \"%s\": \"%s\",\n", "fileSize", $file_size);
printf ("  \"%s\": \"%s\",\n", "mimeType", $file_mime_type);
printf ("}]\n");

// EOF

