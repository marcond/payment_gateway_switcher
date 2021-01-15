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

    <p><label for="file">Arquivo de imagem:
      <input type="file" name="file">
    </label></p>
    <p><label for="wc-store">Nome da loja:
      <input type="text" name="wc-store">
	    </label></p>

    <p><input type="submit" name="enviar" value="Enviar arquivo..." /></p>
  </form>
</body>
</html>

