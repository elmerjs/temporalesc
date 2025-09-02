<?php
$active_menu_item = 'video_tutorial';
require('include/headerz.php');

// Obtener tipo de usuario (sanitizado)
$tipo_usuario = isset($_GET['tipo_usuario']) ? (int)$_GET['tipo_usuario'] : 3;

// Definir URLs de video según tipo de usuario
$video_url_aceptacion_envio = "https://www.loom.com/embed/a02cd4cf28a143609283a86bb9deec90"; // Video por defecto
$video_url_devolucion = "https://www.loom.com/embed/f9d1ea31f2e7430e8cc26a3e18b91add"; // Nuevo video de devolución
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Tutorial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .video-container {
            position: relative;
            padding-bottom: 56.25%; /* 16:9 Aspect Ratio */
            height: 0;
            overflow: hidden;
            max-width: 100%; /* Ajuste para columnas */
            margin: 20px auto;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .video-container iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: none;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-10 text-center">
                <h2 class="mb-4">Video Tutorial</h2>

                <?php if ($tipo_usuario == 2): ?>
                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <h4 class="mb-3">Aceptación y Envío</h4>
                            <div class="video-container">
                                <iframe src="<?php echo htmlspecialchars($video_url_aceptacion_envio); ?>"
                                        frameborder="0"
                                        allowfullscreen
                                        loading="lazy">
                                </iframe>
                            </div>
                        </div>
                        <div class="col-md-6 mb-4">
                            <h4 class="mb-3">Devolución (Rechazo)</h4>
                            <div class="video-container">
                                <iframe src="<?php echo htmlspecialchars($video_url_devolucion); ?>"
                                        frameborder="0"
                                        allowfullscreen
                                        loading="lazy">
                                </iframe>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="video-container">
                        <iframe src="<?php echo htmlspecialchars($video_url_aceptacion_envio); ?>"
                                frameborder="0"
                                allowfullscreen
                                loading="lazy">
                        </iframe>
                    </div>
                <?php endif; ?>

               
            </div>
        </div>
    </div>

    <script src="https://kit.fontawesome.com/your-fontawesome-code.js" crossorigin="anonymous"></script>
</body>
</html>