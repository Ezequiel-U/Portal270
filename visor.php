<?php
session_start();
if (!isset($_SESSION['usuario_id']) || !isset($_GET['archivo'])) {
    die("Acceso denegado o archivo no especificado.");
}

$archivo = basename($_GET['archivo']); // basename por seguridad
$ruta = 'uploads/' . $archivo;

if (!file_exists($ruta)) {
    die("El archivo no existe.");
}

$ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visor de Documentos - <?= htmlspecialchars($archivo) ?></title>
    <style>
        body, html {
            margin: 0; padding: 0; height: 100%; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #201f1e; /* Color oscuro estilo Teams */
            color: white;
            display: flex; flex-direction: column;
        }
        .toolbar {
            background-color: #11100f;
            padding: 15px 20px;
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid #3b3a39;
        }
        .filename { font-weight: 600; font-size: 14px; }
        .btn-close {
            background: transparent; color: white; border: 1px solid #fff;
            padding: 5px 15px; border-radius: 4px; cursor: pointer; text-decoration: none;
            font-size: 13px;
        }
        .btn-close:hover { background: rgba(255,255,255,0.1); }
        
        .content-area {
            flex-grow: 1; display: flex; justify-content: center; align-items: center;
            overflow: auto; padding: 20px;
        }
        
        /* Contenedor tipo hoja blanca para Word */
        #word-container {
            background: white; color: black;
            width: 100%; max-width: 900px;
            min-height: 100%;
            padding: 40px; box-sizing: border-box;
            box-shadow: 0 0 10px rgba(0,0,0,0.5);
            display: none;
        }
        #word-container img { max-width: 100%; height: auto; }
        #word-container table { border-collapse: collapse; width: 100%; }
        #word-container td, #word-container th { border: 1px solid #ccc; padding: 5px; }

        iframe, img { max-width: 100%; max-height: 100%; border: none; }
    </style>
    <!-- Incluir Mammoth.js para archivos Word -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js"></script>
</head>
<body>

    <div class="toolbar">
        <div class="filename">📄 <?= htmlspecialchars($archivo) ?></div>
        <button class="btn-close" onclick="window.close()">Cerrar Vista</button>
    </div>

    <div class="content-area">
        <?php if($ext == 'pdf'): ?>
            <iframe src="<?= htmlspecialchars($ruta) ?>" width="100%" height="100%"></iframe>
        <?php elseif(in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
            <img src="<?= htmlspecialchars($ruta) ?>" alt="Documento">
        <?php elseif($ext == 'docx'): ?>
            <div id="word-container">
                <div style="text-align:center; padding: 50px; color:#666;">Cargando documento...</div>
            </div>
            <script>
                fetch("<?= htmlspecialchars($ruta) ?>")
                    .then(response => response.arrayBuffer())
                    .then(arrayBuffer => {
                        mammoth.convertToHtml({arrayBuffer: arrayBuffer})
                            .then(displayResult)
                            .catch(handleError);
                    });

                function displayResult(result) {
                    document.getElementById("word-container").style.display = "block";
                    document.getElementById("word-container").innerHTML = result.value;
                }
                function handleError(err) {
                    document.getElementById("word-container").style.display = "block";
                    document.getElementById("word-container").innerHTML = "<h3 style='color:red;'>Error al cargar el archivo de Word.</h3><p>Intenta descargarlo directamente.</p>";
                    console.log(err);
                }
            </script>
        <?php else: ?>
            <div style="text-align: center;">
                <h3>Formato de archivo no soportado para vista previa en el navegador (.<?= $ext ?>)</h3>
                <a href="<?= htmlspecialchars($ruta) ?>" download style="color: #4da6ff;">Descargar el archivo</a>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>
