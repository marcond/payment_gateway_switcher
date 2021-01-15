<!DOCTYPE html>
<html>
<head>
  <title>Teste para API/upload-image</title>
</head>
<body>
  <h1>Teste para API/upload-image</h1>

  <form method="POST" action="/wp-content/icnet-api/upload-image/" enctype="multipart/form-data">

    <!-- Parametros para validar o acesso - PREENCHER COM PARAMETROS VALIDOS -->
    <input type="hidden" name="api-id" value="desenvolvimento">
    <input type="hidden" name="api-key" value="12345678-9abc-def0-123456789abcdef01">

    <!-- O arquivo eh enviado no parametro 'file' -->
    <p>
      <label for="file">Arquivo de imagem:</label>
      <input type="file" name="file">
    </p>

    <!-- O nome da loja eh enviado no parametro 'wc-store' -->
    <p>
      <label for="wc-store">Nome da loja:</label>
      <input type="text" name="wc-store">
    </p>

    <p><input type="submit" name="enviar" value="Enviar arquivo..." /></p>
  </form>

  <h2>Informações úteis:</h2>
  <p><b>Código deste teste:</b> <a href='https://github.com/marcond/payment_gateway_switcher/blob/master/upload-image/test-sample.php' target='_blank'>
    https://github.com/marcond/payment_gateway_switcher/blob/master/upload-image/test-sample.php
  </a></p>
  <p><b>Implementação da API:</b> <a href='https://github.com/marcond/payment_gateway_switcher/blob/master/upload-image/index.php' target='_blank'>
    https://github.com/marcond/payment_gateway_switcher/blob/master/upload-image/index.php
  </a></p>
</body>
</html>

