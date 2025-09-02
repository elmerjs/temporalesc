<?php

$active_menu_item = 'gestion_depto';

require('include/headerz.php');
require 'funciones.php';
//require 'actualizar_usuario.php'; // <-- Incluir aquí
 if (!isset($_SESSION['name']) || empty($_SESSION['name'])) {
    // Si no hay sesión activa, muestra un mensaje y redirige
    echo "<span style='color: red; text-align: left; font-weight: bold;'>
              <a href='index.html'>inicie sesión</a>
              </span>";
    exit(); // Detener toda la ejecución del script
}

    // Obtener los parámetros de la URL
$facultad_id = isset($_POST['facultad_id']) ? $_POST['facultad_id'] : null;
$anio_semestre = $_POST['anio_semestre'] ?? $_GET['anio_semestre'] ?? null;
$departamento_id = $_POST['departamento_id'] ?? $_GET['departamento_id'] ?? null;

// --- INICIO DEPURACIÓN ---
echo "<h3>Valores de Parámetros Recibidos:</h3>";
echo "Año/Semestre: **" . htmlspecialchars($anio_semestre) . "** (Tipo: " . gettype($anio_semestre) . ")<br>";
echo "Departamento ID: **" . htmlspecialchars($departamento_id) . "** (Tipo: " . gettype($departamento_id) . ")<br><br>";
// --- FIN DEPURACIÓN ---

 $aniose= $anio_semestre;
        $cierreperiodo = obtenerperiodo($anio_semestre);


require_once('conn.php'); // Asegúrate de que 'conn.php' esté en la ruta correcta

// El objeto $conn ya está disponible aquí gracias a conn.php
// Verificar si la conexión falló (aunque conn.php ya lo maneja con die(), es buena práctica)
if ($conn->connect_error) {
    die("Conexión fallida al incluir conn.php: " . $conn->connect_error);
}
// Validar que los parámetros esenciales estén presentes
if ($anio_semestre === null || $departamento_id === null) {
    echo "Error: Parámetros de año/semestre o departamento no proporcionados.";
    exit();
}

// --- Nombre de la tabla de trabajo (clon permanente) ---
$working_table_name = "solicitudes_working_copy";

// 1. Crear la tabla de trabajo si no existe
$create_working_table_sql = "
CREATE TABLE IF NOT EXISTS `$working_table_name` (
    `id_solicitud` int(11) NOT NULL,
    `anio_semestre` varchar(10) NOT NULL,
    `facultad_id` int(11) NOT NULL,
    `departamento_id` int(11) NOT NULL,
    `tipo_docente` enum('Ocasional','Catedra') NOT NULL,
    `cedula` varchar(60) NOT NULL,
    `nombre` varchar(120) NOT NULL,
    `tipo_dedicacion` enum('TC','MT','') DEFAULT NULL,
    `tipo_dedicacion_r` varchar(10) DEFAULT NULL,
    `horas` decimal(11,1) DEFAULT NULL,
    `horas_r` decimal(11,1) DEFAULT NULL,
    `sede` varchar(150) DEFAULT NULL,
    `anexa_hv_docente_nuevo` enum('si','no','no aplica') DEFAULT NULL,
    `actualiza_hv_antiguo` enum('si','no','no aplica') DEFAULT NULL,
    `visado` tinyint(4) DEFAULT NULL,
    `estado` varchar(2) DEFAULT NULL,
    `novedad` text DEFAULT NULL,
    `puntos` decimal(10,2) DEFAULT NULL,
    `s_observacion` text DEFAULT NULL,
    `tipo_reemplazo` varchar(255) DEFAULT NULL,
    `costo` decimal(10,2) DEFAULT NULL,
    `anexos` varchar(255) DEFAULT NULL,
    `pregrado` varchar(255) DEFAULT NULL,
    `especializacion` varchar(255) DEFAULT NULL,
    `maestria` varchar(255) DEFAULT NULL,
    `doctorado` varchar(255) DEFAULT NULL,
    `otro_estudio` varchar(255) DEFAULT NULL,
    `experiencia_docente` varchar(255) DEFAULT NULL,
    `experiencia_profesional` varchar(255) DEFAULT NULL,
    `otra_experiencia` varchar(255) DEFAULT NULL,
    PRIMARY KEY (`id_solicitud`, `anio_semestre`, `departamento_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

if ($conn->query($create_working_table_sql) === TRUE) {
    echo "✔️ Tabla de trabajo '$working_table_name' verificada/creada exitosamente.<br>";

    // 2. Verificar si ya existen datos para esta combinación de anio_semestre y departamento_id
    $check_existence_sql = "
    SELECT COUNT(*) FROM `$working_table_name`
    WHERE anio_semestre = ? AND departamento_id = ?;
    ";
    $stmt_check = $conn->prepare($check_existence_sql);
    if ($stmt_check === false) {
        echo "❌ Error al preparar la consulta de verificación: " . $conn->error . "<br>";
        $conn->close();
        exit();
    }
    $stmt_check->bind_param("si", $anio_semestre, $departamento_id);
    $stmt_check->execute();
    $stmt_check->bind_result($count);
    $stmt_check->fetch();
    $stmt_check->close();

    echo "🔍 Verificando datos existentes en '$working_table_name' para Año/Semestre: **$anio_semestre**, Departamento ID: **$departamento_id**.<br>";
    echo "📊 Registros encontrados en '$working_table_name': **$count**<br>";

    if ($count == 0) {
        echo "✅ No se encontraron datos existentes. Procediendo a insertar.<br>";
        // 3. Si no existen datos, insertar los datos de la tabla original 'solicitudes'
        //    Excluyendo 'novedad', 's_observacion', 'tipo_reemplazo' (poniendo NULL)
        //    Y excluyendo registros donde 'estado' sea 'an' PERO incluyendo NULLs.
        $insert_data_sql = "
        INSERT INTO `$working_table_name` (
            `id_solicitud`, `anio_semestre`, `facultad_id`, `departamento_id`, `tipo_docente`,
            `cedula`, `nombre`, `tipo_dedicacion`, `tipo_dedicacion_r`, `horas`,
            `horas_r`, `sede`, `anexa_hv_docente_nuevo`, `actualiza_hv_antiguo`, `visado`,
            `estado`, `puntos`, `costo`, `anexos`, `pregrado`, `especializacion`,
            `maestria`, `doctorado`, `otro_estudio`, `experiencia_docente`,
            `experiencia_profesional`, `otra_experiencia`
        )
        SELECT
            `id_solicitud`, `anio_semestre`, `facultad_id`, `departamento_id`, `tipo_docente`,
            `cedula`, `nombre`, `tipo_dedicacion`, `tipo_dedicacion_r`, `horas`,
            `horas_r`, `sede`, `anexa_hv_docente_nuevo`, `actualiza_hv_antiguo`, `visado`,
            `estado`, `puntos`, `costo`, `anexos`, `pregrado`, `especializacion`,
            `maestria`, `doctorado`, `otro_estudio`, `experiencia_docente`,
            `experiencia_profesional`, `otra_experiencia`
        FROM solicitudes
        WHERE anio_semestre = ? AND departamento_id = ? AND (estado != 'an' OR estado IS NULL);
        ";
        $stmt_insert = $conn->prepare($insert_data_sql);
        if ($stmt_insert === false) {
            echo "❌ Error al preparar la consulta de inserción: " . $conn->error . "<br>";
            $conn->close();
            exit();
        }
        $stmt_insert->bind_param("si", $anio_semestre, $departamento_id);

        if ($stmt_insert->execute()) {
            $rows_inserted = $stmt_insert->affected_rows;
            echo "🎉 Tabla de trabajo '$working_table_name' preparada exitosamente con **$rows_inserted** nuevos datos (novedad, s_observacion, tipo_reemplazo son NULL, y excluyendo 'estado' = 'an' pero incluyendo NULLs) para Año/Semestre: **$anio_semestre**, Departamento ID: **$departamento_id**.<br>";
        } else {
            echo "❌ Error al copiar datos a la tabla de trabajo: " . $stmt_insert->error . "<br>";
        }
        $stmt_insert->close();
    } else {
        echo "⚠️ Ya existen datos en la tabla de trabajo '$working_table_name' para Año/Semestre: **$anio_semestre**, Departamento ID: **$departamento_id**. No se realizó ninguna inserción de nuevos datos.<br>";
    }

} else {
    echo "❌ Error al crear la tabla de trabajo: " . $conn->error . "<br>";
}

$conn->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Consulta de Solicitudes</title>
    
         <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<!-- jQuery y Bootstrap JS -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>

    
    
<!-- Cargar Bootstrap 5 y Font Awesome -->

<!-- jQuery (si es necesario) -->

<!-- Cargar solo Bootstrap 5 JS -->

            <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">

    <script>
        function confirmarEnvio(count, tipo) {
            return confirm(` Se confirman ${count} profesores de  ${tipo}. ¿Desea continuar?`);
        }
    </script>
    <style>
    /* Unicauca Color Palette (Retained for Minimalism) */
    :root {
        --unicauca-blue-primary: #0047AB; /* Un azul vibrante pero con presencia */
        --unicauca-blue-dark: #002D72; /* Azul oscuro para elementos clave */
        --unicauca-blue-light: #F0F5FA; /* Un azul muy, muy pálido para fondos sutiles */
        --unicauca-red-primary: #CC3333; /* Un rojo más directo para acciones críticas */
        --unicauca-gray-light: #F8F8F8; /* Gris casi blanco para filas alternas */
        --unicauca-gray-border: #E0E0E0; /* Borde muy fino y sutil */
        --unicauca-text-dark: #333333; /* Texto principal oscuro */
        --unicauca-text-light: #777777; /* Texto secundario más suave */
        --unicauca-orange-primary: #FF9933; /* Naranja para énfasis de botón */
        --unicauca-orange-dark: #E68A00; /* Naranja más oscuro para hover */

        /* Colores institucionales definidos más abajo y priorizados */
        --unicauca-primary: #002A9E;
        --unicauca-secondary: #0051C6;
        --unicauca-accent: #16A8E1;
        --unicauca-success: #249337;
        --unicauca-warning: #F8AE15;
        --unicauca-danger: #E52724;
        --unicauca-light: #e8f4ff;
        --unicauca-gray: #f0f4f8;
        --unicauca-dark: #1a1a2e;

        /* Colores del banner y menú superior */
        --unicauca-main-dark-blue: #001A4E;
        --unicauca-main-dark-blue-light-variation: #002D7A;
        --unicauca-banner-start-color: #00226B;
        --unicauca-banner-end-color: #00368D;
        --unicauca-accent-gold: #F0C239;
        --unicauca-accent-red: #DC3545;
    }

    body {
        margin: 15px auto;
        padding: 15px;
        max-width: 95%;
        color: var(--unicauca-text-dark);
        background-color: #fcfdfe;
        line-height: 1.4;
        font-family: 'Open Sans', sans-serif !important; /* Priorizado */
        background-color: #f8fafc; /* Priorizado */
        padding: 20px; /* Priorizado */
    }

    /* Apply Open Sans to all text elements */
    body, h1, h2, h3, h4, h5, h6, p, span, div, a, li, td, th {
        font-family: 'Open Sans', sans-serif !important;
    }

    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        border-bottom: 1px solid var(--unicauca-gray-border);
        padding-bottom: 10px;
    }

    .header h1 {
        flex: 1;
        text-align: center;
        color: var(--unicauca-blue-dark);
        font-size: 1.8rem;
        margin: 0;
        font-weight: 600;
    }

    .header h2, .header h3 {
        flex: 1;
        text-align: left;
        margin: 3px 0;
        color: var(--unicauca-text-dark);
        font-size: 1rem;
        font-weight: 500;
    }

    /* --- Minimalist Table Style (General) --- */
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
        font-size: 0.9rem;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
        border-radius: 4px;
        overflow: hidden;
    }

    /* Estilos compactos para celdas */
    th, td {
        border: 1px solid var(--unicauca-gray-border);
        padding: 4px 8px;
        text-align: center;
        line-height: 1.2;
        min-height: 20px;
        font-size: 0.85rem;
    }

    /* Encabezados más compactos también */
    th {
        background-color: var(--unicauca-blue-dark);
        color: white;
        font-weight: 600;
        font-size: 0.8rem;
        padding: 6px 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
    }

    /* Filas alternas - casi invisibles */
    tr:nth-child(even) {
        background-color: var(--unicauca-gray-light);
    }

    /* Efecto hover - muy discreto */
    tr:hover {
        background-color: rgba(0, 71, 171, 0.05);
        transition: background-color 0.15s ease;
    }

    /* --- Button Styles (More Compact) --- */
    button {
        padding: 4px 10px;
        margin: 1px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.85rem;
        transition: background-color 0.15s ease, transform 0.1s ease, box-shadow 0.1s ease;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .update-btn {
        background-color: var(--unicauca-blue-primary);
        color: white;
    }
    .update-btn:hover {
        background-color: #003A90;
        transform: translateY(-0.5px);
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
    }

    .delete-btn {
        background-color: var(--unicauca-red-primary);
        color: white;
    }
    .delete-btn:hover {
        background-color: #B32D2D;
        transform: translateY(-0.5px);
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
    }

    .estado-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .estado-container h4 {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .estado-container h4 .fas {
        font-size: 1.5em;
        color: #AD0000;
        transition: transform 0.2s ease-in-out;
    }

    .estado-container h4 .fas:hover {
        color: #DB141C;
        transform: scale(1.1);
    }

    .estado-container h4 .fa-caret-right {
        transform: rotate(0deg);
    }

    .estado-container h4 .fa-caret-down {
        transform: rotate(0deg);
    }

    .container {
        display: flex;
        justify-content: space-between;
        align-items: stretch;
        gap: 15px;
        max-width: 100%;
        margin: 0 auto;
        padding: 10px;
    }

    .box {
        flex-grow: 1;
        padding: 15px;
        border: 1px solid var(--unicauca-gray-border);
        text-align: center;
        border-radius: 6px;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
        background-color: white;
    }

    .box-gray {
        background-color: var(--unicauca-gray-light);
        border-color: var(--unicauca-blue-primary);
        margin-bottom: 20px;
        padding: 12px 15px;
        border-radius: 6px;
    }

    .btn-primary {
        background-color: var(--unicauca-blue-primary);
        color: white;
        height: 36px;
        padding: 0 12px;
        font-size: 0.9rem;
        line-height: 36px;
        border-radius: 4px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    .btn-primary:hover {
        background-color: #003A90;
        transform: translateY(-0.5px);
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
    }

    /* --- Cargue Masivo Button (Compact) --- */
    @keyframes pulseEffect {
        0% { transform: scale(1); }
        50% { transform: scale(1.03); }
        100% { transform: scale(1); }
    }

    .btn-cargue-masivo {
        background: linear-gradient(to right, var(--unicauca-orange-primary), var(--unicauca-orange-dark));
        color: white;
        font-weight: 500;
        font-size: 0.95rem;
        padding: 8px 15px;
        border-radius: 5px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        transition: all 0.2s ease-in-out;
        border: none;
        display: inline-block;
        animation: pulseEffect 1s ease-in-out infinite;
    }

    .btn-cargue-masivo:hover {
        background: linear-gradient(to right, var(--unicauca-orange-dark), #CC6600);
        transform: scale(1.02);
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
    }

    .label-italic {
        font-style: italic;
        color: var(--unicauca-text-light);
    }

    #textoObservacion {
        white-space: pre-line;
    }

    /* Specific styles for the "FOR.45" download button (Highly Minimalist) */
    .download-btn {
        background-color: transparent;
        box-shadow: none;
        padding: 3px;
        margin: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 24px;
        min-height: 24px;
    }
    .download-btn:hover {
        background-color: rgba(0, 71, 171, 0.05);
        box-shadow: none;
        transform: none;
    }
    .download-btn i {
        font-size: 1.0em !important;
        color: var(--unicauca-blue-primary) !important;
    }

    /* --- Modal Styling (More Compact) - Initial Bootstrap overrides --- */
    .modal-content {
        border-radius: 8px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
    }
    .modal-header {
        background-color: var(--unicauca-blue-dark);
        color: white;
        border-bottom: none;
        border-top-left-radius: 8px;
        border-top-right-radius: 8px;
        padding: 12px 15px;
    }
    .modal-title {
        font-weight: 600;
        font-size: 1.1rem;
    }
    .modal-footer {
        border-top: 1px solid var(--unicauca-gray-border);
        padding: 10px 15px;
    }
    .btn-close {
        filter: invert(1);
        font-size: 0.9rem;
        padding: 0;
        margin: 0;
    }
    .form-control {
        border-radius: 4px;
        border: 1px solid var(--unicauca-gray-border);
        padding: 7px 10px;
        font-size: 0.9rem;
    }
    .form-label {
        font-weight: 500;
        color: var(--unicauca-text-dark);
        margin-bottom: 5px;
        font-size: 0.9rem;
    }

    /* Checkbox for Visado */
    .individualCheckbox {
        transform: scale(1);
        margin: 0 3px;
        cursor: pointer;
    }
    .individualCheckbox:disabled {
        cursor: not-allowed;
        opacity: 0.5;
    }

    /* --- Universidad del Cauca Styling (Prioritized) --- */

    .top-nav {
        background: linear-gradient(135deg, var(--unicauca-banner-start-color) 0%, var(--unicauca-banner-end-color) 100%);
        padding: 4px 0;
        border-radius: 10px;
        margin-bottom: 25px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        color: var(--unicauca-text-light);
    }

    .btn-unicauca-light {
        background-color: rgba(255, 255, 255, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: white;
        transition: all 0.3s;
        padding: 8px 15px;
        border-radius: 30px;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
    }

    .btn-unicauca-light:hover {
        background-color: rgba(255, 255, 255, 0.25);
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    }

    .institutional-card {
        flex: 0 0 calc(70% - 10px);
        background: white;
        border-radius: 12px;
        box-shadow: 0 6px 20px rgba(0, 42, 158, 0.1);
        border: 1px solid #e0e6ed;
        margin-bottom: 25px;
        overflow: hidden;
        border-top: 4px solid var(--unicauca-primary);
    }

    .institutional-cardb { /* Priorizado */
        flex: 0 0 calc(30% - 10px);
        background: white;
        border-radius: 12px;
        box-shadow: 0 6px 20px rgba(0, 42, 158, 0.1);
        border: 1px solid #e0e6ed;
        margin-bottom: 25px;
        overflow: hidden;
        border-top: 4px solid var(--unicauca-primary);
        padding: 25px; /* Priorizado */
    }

    .card-header-unicauca {
        background: linear-gradient(to right, var(--unicauca-light), white);
        padding: 20px 25px;
        border-bottom: 1px solid #e0e6ed;
    }

    .institutional-title {
        color: var(--unicauca-primary);
        font-weight: 700;
        font-size: 1.8rem;
        margin: 0;
        display: flex;
        align-items: center;
    }

    .institutional-subtitle {
        color: var(--unicauca-secondary);
        font-weight: 600;
        font-size: 1.3rem;
        margin-bottom: 0;
    }

    .periodo-badge {
        background-color: var(--unicauca-accent);
        color: white;
        padding: 8px 15px;
        border-radius: 30px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        box-shadow: 0 3px 8px rgba(22, 168, 225, 0.3);
    }

    .status-indicator {
        padding: 10px 15px;
        border-radius: 8px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        margin-right: 10px;
        margin-bottom: 10px;
    }

    .status-success {
        background-color: rgba(36, 147, 55, 0.15);
        color: var(--unicauca-success);
        border: 1px solid rgba(36, 147, 55, 0.3);
    }

    .status-warning {
        background-color: rgba(248, 174, 21, 0.15);
        color: var(--unicauca-warning);
        border: 1px solid rgba(248, 174, 21, 0.3);
    }

    .status-danger {
        background-color: rgba(229, 39, 36, 0.15);
        color: var(--unicauca-danger);
        border: 1px solid rgba(229, 39, 36, 0.3);
    }

    .status-icon {
        margin-right: 8px;
        font-size: 1.2em;
    }

    .card-content {
        padding: 25px;
    }

    .section-title {
        color: var(--unicauca-primary);
        border-bottom: 2px solid var(--unicauca-accent);
        padding-bottom: 10px;
        margin-bottom: 20px;
        font-weight: 700;
        display: flex;
        align-items: center;
    }

    .section-title i {
        margin-right: 10px;
        color: var(--unicauca-secondary);
    }

    .summary-card {
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        border: 1px solid #e0e6ed;
        padding: 20px;
        margin-bottom: 20px;
        background: white;
    }


    .summary-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--unicauca-secondary);
        margin-bottom: 5px;
    }

    .summary-label {
        color: #6c757d;
        font-size: 0.9rem;
    }

    .sede-container {
        display: flex;
        justify-content: space-around;
        margin: 15px 0;
        text-align: center;
    }

    .sede-box {
        padding: 10px;
        border-radius: 8px;
        background-color: var(--unicauca-light);
        flex: 1;
        margin: 0 5px;
    }

    .sede-value {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--unicauca-primary);
    }

    .status-badge { /* Priorizado, el de arriba es para status-indicator */
        padding: 8px 15px;
        border-radius: 20px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
    }

    .badge-success {
        background-color: rgba(36, 147, 55, 0.15);
        color: var(--unicauca-success);
        border: 1px solid rgba(36, 147, 55, 0.3);
    }

    .badge-warning {
        background-color: rgba(248, 174, 21, 0.15);
        color: var(--unicauca-warning);
        border: 1px solid rgba(248, 174, 21, 0.3);
    }

    @media (max-width: 768px) {
        .institutional-title {
            font-size: 1.5rem;
        }

        .institutional-subtitle {
            font-size: 1.1rem;
        }

        .sede-container {
            flex-direction: column;
        }

        .sede-box {
            margin: 5px 0;
        }
    }

    .summary-title .label-space {
        margin-right: 15px;
    }

    /* --- Summary Container --- */
    .summary-container {
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-left: 5px solid #A52A2A;
        padding: 15px 20px;
        margin-bottom: 0px;
        border-radius: 5px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    /* Lista de datos del resumen */
    .summary-data-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-wrap: wrap;
        font-size: 1.1em;
    }

    .summary-data-list li {
        display: flex;
        align-items: center;
        margin-right: 25px;
        white-space: nowrap;
        position: relative;
        margin-bottom: 0px;
    }

    /* Pseudo-elemento para el separador '|' */
    .summary-data-list li:not(:last-child)::after {
        content: "|";
        color: #A52A2A;
        margin-left: 20px;
        font-weight: normal;
        font-size: 1.1em;
    }

    /* Estilos para las etiquetas (Facultad, Departamento, Periodo) */
    .summary-data-list .label-heading {
        color: #000066;
        font-weight: bold;
        margin-right: 8px;
    }

    /* Estilos para los valores de los datos */
    .summary-data-list .data-value {
        color: #343a40;
        font-weight: 500;
    }

    /* Media Queries para pantallas más pequeñas */
    @media (max-width: 768px) {
        .summary-data-list {
            flex-direction: column;
            align-items: flex-start;
        }
        .summary-data-list li {
            margin-right: 0;
            margin-bottom: 8px;
        }
        .summary-data-list li:not(:last-child)::after {
            content: none;
        }
    }

    .link-hv {
        color: #004080;
        text-decoration: none;
        font-weight: bold;
        position: relative;
    }

    .link-hv:hover {
        text-decoration: underline;
    }

    .link-hv::after {
        content: "🔗";
        margin-left: 4px;
        font-size: 0.8em;
    }

    /* --- Modal Styles (for Bootstrap Modals) - Prioritized --- */
    .modal-content { /* Priorizado */
        border-radius: 10px;
        overflow: hidden;
    }
    .modal-header { /* Priorizado */
        background-color: #1A73E8; /* Usando este color, ya que es el último definido */
        color: white;
        padding: 15px 20px;
    }
    .card-header {
        background-color: #f8f9fa;
        font-weight: 600;
        padding: 10px 15px;
    }
    .info-section {
        padding: 15px;
        border-radius: 8px;
        background-color: #f8f9fa;
        margin-bottom: 15px;
    }
    .form-label { /* Priorizado */
        font-weight: 500;
        color: #333;
        margin-bottom: 5px;
    }
    .form-control { /* Priorizado */
        border-radius: 5px;
        padding: 8px 12px;
        border: 1px solid #ced4da;
        transition: all 0.3s;
    }
    .form-control:focus {
        border-color: #1A73E8;
        box-shadow: 0 0 0 0.2rem rgba(26, 115, 232, 0.25);
    }
    .experience-col {
        padding-left: 20px;
    }
    @media (max-width: 768px) {
        .experience-col {
            padding-left: 0;
            margin-top: 20px;
        }
    }

    .btn.btn-sm.btn-info .fa-eye {
        font-size: 9px !important;
        color: white !important;
        line-height: 1;
        vertical-align: middle;
    }

    .btn.btn-sm.btn-primary .fa-file-arrow-down {
        font-size: 9px !important;
        color: #1A73E8 !important;
        line-height: 1;
        vertical-align: middle;
    }

    /* Encabezado del resumen */
    .summary-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px dashed var(--unicauca-accent);
    }

    .summary-title {
        color: var(--unicauca-primary);
        font-size: 1.6rem;
        margin: 0;
        font-weight: normal;
        display: flex;
        align-items: center;
    }

    .summary-title strong {
        font-weight: 700;
        margin-right: 5px;
    }

    .summary-title i {
        margin-right: 10px;
        color: var(--unicauca-secondary);
    }

    /* Tabla institucional */
    .table-unicauca { /* Priorizado, este tiene un verde principal */
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-family: 'Open Sans', sans-serif !important;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        border-radius: 8px;
        overflow: hidden;
        margin: 15px 0; /* Asegura un margen apropiado */
    }

    .table-unicauca thead { /* Priorizado */
        background-color: #00843D; /* Verde Unicauca */
        color: white;
    }

    .table-unicauca th { /* Priorizado */
        padding: 10px 8px;
        text-align: center;
        font-weight: 600;
        font-size: 0.85rem;
        border-bottom: 2px solid #00612D; /* Borde más oscuro */
        position: relative;
    }

    .table-unicauca td { /* Priorizado */
        padding: 4px 8px;
        min-height: 24px;
        line-height: 1.2;
        text-align: center;
        border-bottom: 1px solid #e0e0e0;
        font-size: 0.85rem;
    }

    .table-unicauca tbody tr:last-child td {
        border-bottom: none;
    }

    .table-unicauca tbody tr:nth-child(even) { /* Priorizado */
        background-color: var(--unicauca-light); /* Usando el unicauca-light de la segunda paleta */
    }

    .table-unicauca tbody tr:hover { /* Priorizado */
        background-color: rgba(0, 132, 61, 0.05); /* Verde muy claro con transparencia */
    }

    .table-unicauca .table-secondary { /* Priorizado */
        background-color: var(--unicauca-gray); /* Usando el unicauca-gray de la segunda paleta */
        font-weight: 600;
    }

    .text-success { /* Priorizado */
        color: #00843D !important; /* Verde Unicauca */
    }

    .text-danger { /* Priorizado */
        color: #D32F2F !important; /* Rojo institucional */
    }

    /* Botones institucionales */
    .btn-unicauca-primary {
        background-color: var(--unicauca-primary);
        border-color: var(--unicauca-primary);
        color: white;
        padding: 8px 20px;
        border-radius: 30px;
        font-weight: 500;
        transition: all 0.3s;
    }

    .btn-unicauca-primary:hover {
        background-color: #001a7a;
        border-color: #001a7a;
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    .btn-unicauca-success {
        background-color: var(--unicauca-success);
        border-color: var(--unicauca-success);
        color: white;
        border-radius: 30px;
        padding: 8px 20px;
        font-weight: 500;
        transition: all 0.3s;
    }

    .btn-unicauca-success:hover {
        background-color: #1c7a2e;
        border-color: #1c7a2e;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    .btn-unicauca-danger {
        background-color: var(--unicauca-danger);
        border-color: var(--unicauca-danger);
        color: white;
        border-radius: 30px;
        padding: 8px 20px;
        font-weight: 500;
        transition: all 0.3s;
    }

    .btn-unicauca-danger:hover {
        background-color: #c21f1d;
        border-color: #c21f1d;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    .btn-unicauca-accent {
        background-color: var(--unicauca-accent);
        border-color: var(--unicauca-accent);
        color: white;
        border-radius: 30px;
        padding: 8px 20px;
        font-weight: 500;
        transition: all 0.3s;
    }

    .btn-unicauca-accent:hover {
        background-color: #1290c9;
        border-color: #1290c9;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    /* Sección de respuesta */
    .response-section {
        background-color: var(--unicauca-light);
        border-radius: 8px;
        padding: 15px;
        margin: 20px 0;
        border-left: 4px solid var(--unicauca-accent);
    }

    .response-section h3 {
        color: var(--unicauca-primary);
        font-weight: 600;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
    }

    .response-section .btn-observacion {
        background: none;
        border: none;
        cursor: pointer;
        margin-left: 5px;
        color: var(--unicauca-secondary);
        font-size: 1.1rem;
    }

    /* Sección de botones */
    .action-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        justify-content: center;
        margin: 20px 0;
    }

    .action-button-group {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
    }

    .button-tooltip {
        font-size: 0.8rem;
        color: #6c757d;
        text-align: center;
        max-width: 300px;
    }

    /* Mejora para la primera columna de .table-unicauca */
    .table-unicauca td:first-child {
        font-weight: 500;
        text-align: left;
        padding-left: 12px;
    }

    /* Estilos específicos para las columnas sin intercalado */
    .td-simple {
        border-bottom: 1px solid #e0e6ed;
        color: #343A40;
        font-weight: normal;
        font-family: 'Open Sans', sans-serif !important;
        font-size: 0.95rem;
    }

    /* --- Universidad del Cauca Actions Section --- */
    .unacauca-actions-section {
        padding: 20px;
        background-color: #f8f9fa;
        border-radius: 8px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        margin-bottom: 30px;
    }

    /* Primary Button (Enviar a Facultad) */
    .unacauca-btn-primary {
        background-color: #8B0000 !important;
        color: #fff !important;
        border-color: #8B0000 !important;
        font-weight: bold;
        padding: 10px 25px;
        border-radius: 5px;
        transition: background-color 0.3s ease, transform 0.2s ease;
    }

    .unacauca-btn-primary:hover {
        background-color: #a00000 !important;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }

    /* Reprint Button */
    .unacauca-btn-reprint {
        background-color: #FFD700 !important;
        color: #8B0000 !important;
        border-color: #FFD700 !important;
        font-weight: bold;
        padding: 10px 25px;
        border-radius: 5px;
        transition: background-color 0.3s ease, transform 0.2s ease;
    }

    .unacauca-btn-reprint:hover {
        background-color: #e5c100 !important;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }

    /* Disabled Button */
    .unacauca-btn-disabled {
        background-color: #cccccc !important;
        color: #666666 !important;
        border-color: #cccccc !important;
        cursor: not-allowed;
        padding: 10px 25px;
        border-radius: 5px;
    }

    /* Download Button */
    .unacauca-btn-download {
        background-color: #4CAF50 !important;
        color: #fff !important;
        border-color: #4CAF50 !important;
        font-weight: bold;
        padding: 10px 25px;
        border-radius: 5px;
        transition: background-color 0.3s ease, transform 0.2s ease;
    }

    .unacauca-btn-download:hover {
        background-color: #45a049 !important;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }

    /* --- Status Section (Rta. Facultad, Rta. VRA) - Minimalist Styles --- */
    .unacauca-status-section {
        display: flex;
        flex-direction: column;
        gap: 25px;
        padding: 20px;
        background-color: #fcfcfc;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        margin-bottom: 30px;
        border: 1px solid #f0f0f0;
    }

    .unacauca-status-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        padding: 0 10px;
        border-bottom: 1px dashed #e9e9e9;
        padding-bottom: 15px;
    }

    .unacauca-status-item:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }

    .unacauca-status-label-minimal {
        font-size: 1.15rem;
        font-weight: 600;
        color: #555;
        margin-right: 15px;
        white-space: nowrap;
        text-transform: capitalize;
    }

    /* Para pantallas más pequeñas, apilar la etiqueta y el badge */
    @media (max-width: 767px) {
        .unacauca-status-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }
        .unacauca-status-label-minimal {
            margin-right: 0;
            margin-bottom: 5px;
        }
    }

    .unacauca-status-badge-container {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .unacauca-status-badge {
        padding: 8px 16px;
        border-radius: 20px;
        font-weight: bold;
        font-size: 0.95rem;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-width: 125px;
        justify-content: center;
        transition: all 0.3s ease;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    /* Colores para status badges */
    .unacauca-status-badge.status-accepted {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .unacauca-status-badge.status-rejected {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .unacauca-status-badge.status-pending {
        background-color: #fff3cd;
        color: #856404;
        border: 1px solid #ffeeba;
    }

    /* Icons within badges */
    .unacauca-status-badge .fas,
    .unacauca-status-badge .fa-solid {
        font-size: 0.9rem;
    }

    /* Observation Button - Integrated Look */
    .unacauca-btn-icon {
        background: none;
        border: none;
        cursor: pointer;
        padding: 5px;
        line-height: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.2s ease-in-out, color 0.2s ease-in-out;
    }

    .unacauca-btn-icon i {
        color: #007bff;
        font-size: 1.1rem;
    }

    .unacauca-btn-icon:hover i {
        color: #0056b3;
    }

    .unacauca-btn-icon:hover {
        transform: scale(1.1);
    }

    /* Optional: Make observation modal text a bit more prominent */
    .unacauca-observation-text {
        font-size: 1.05rem;
        line-height: 1.7;
        color: #333;
        padding: 10px;
        background-color: #eef4f8;
        border-radius: 6px;
        border: 1px solid #d6e2ea;
        white-space: pre-wrap;
        word-wrap: break-word;
        font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    }

    /* --- Modal Styles (for the custom observation modal) --- */
    .unacauca-modal {
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: #fff;
        padding: 30px;
        border-radius: 10px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        z-index: 1050;
        max-width: 90%;
        width: 400px;
        text-align: center;
    }

    .unacauca-modal-backdrop {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        z-index: 1040;
    }

    .unacauca-modal-close-btn {
        background-color: #8B0000;
        color: #fff;
        border: none;
        padding: 8px 20px;
        border-radius: 5px;
        cursor: pointer;
        margin-top: 20px;
        transition: background-color 0.3s ease;
    }

    .unacauca-modal-close-btn:hover {
        background-color: #a00000;
    }

    /* --- Bootstrap Modal Overrides (for #myModal) --- */
    .unacauca-modal-content {
        border-radius: 10px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    }

    .unacauca-modal-header { /* Priorizado */
        background-color: #8B0000; /* Unacauca Red */
        color: #fff;
        border-bottom: 1px solid #7a0000;
        border-top-left-radius: 10px;
        border-top-right-radius: 10px;
    }

    .unacauca-modal-header .modal-title {
        color: #fff;
        font-weight: bold;
    }

    .unacauca-modal-close {
        color: #fff;
        opacity: 0.8;
        text-shadow: none;
        font-size: 1.5rem;
    }

    .unacauca-modal-close:hover {
        color: #FFD700;
        opacity: 1;
    }

    .unacauca-modal-body {
        padding: 25px;
    }

    .unacauca-modal-body label {
        font-weight: 600;
        color: #333;
        margin-bottom: 5px;
    }

    .unacauca-input {
        border-color: #ced4da;
        border-radius: 5px;
        padding: 8px 12px;
    }

    .unacauca-input:focus {
        border-color: #FFD700;
        box-shadow: 0 0 0 0.2rem rgba(255, 215, 0, 0.25);
    }

    .unacauca-modal-body .form-group {
        margin-bottom: 1.5rem;
    }

    .unacauca-folio-section {
        background-color: #f2f2f2;
        padding: 20px;
        border-radius: 8px;
        border: 1px solid #e0e0e0;
    }

    .unacauca-folio-section .section-title {
        font-size: 1.15rem;
        font-weight: bold;
        color: #8B0000;
        margin-bottom: 15px;
        display: block;
        text-align: center;
    }

    .unacauca-folio-section .label-italic {
        font-style: italic;
        color: #555;
    }

    .unacauca-total-folios {
        font-size: 1.1rem;
        font-weight: bold;
        color: #333;
        margin-top: 15px;
        border-top: 1px dashed #ddd;
        padding-top: 10px;
    }

    .unacauca-total-folios strong {
        color: #8B0000;
    }


    .unacauca-modal-footer {
        background-color: #f8f9fa;
        border-top: 1px solid #e9ecef;
        padding: 15px 25px;
        border-bottom-left-radius: 10px;
        border-bottom-right-radius: 10px;
    }

    .unacauca-btn-secondary {
        background-color: #6c757d !important;
        color: #fff !important;
        border-color: #6c757d !important;
        font-weight: normal;
        padding: 8px 20px;
        border-radius: 5px;
        transition: background-color 0.3s ease;
    }

    .unacauca-btn-secondary:hover {
        background-color: #5a6268 !important;
        border-color: #5a6268 !important;
    }

    /* Placeholder italic style (existing) */
    ::-webkit-input-placeholder {
        font-style: italic;
    }
    ::-moz-placeholder {
        font-style: italic;
    }
    :-ms-input-placeholder {
        font-style: italic;
    }
    :-moz-placeholder {
        font-style: italic;
    }

    /* Tooltip style (optional, if default isn't matching) */
    .tooltip-inner {
        background-color: rgba(139, 0, 0, 0.8);
        color: #fff;
        padding: 8px 12px;
        border-radius: 4px;
        font-size: 0.85rem;
    }
    .bs-tooltip-top .tooltip-arrow::before {
        border-top-color: #8B0000;
    }
    .bs-tooltip-right .tooltip-arrow::before {
        border-right-color: #8B0000;
    }
    .bs-tooltip-bottom .tooltip-arrow::before {
        border-bottom-color: #8B0000;
    }
    .bs-tooltip-left .tooltip-arrow::before {
        border-left-color: #8B0000;
    }
    .modal-close-btn {
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 8px 15px;
        line-height: 1;
        background-color: #6c757d;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }

    .modal-close-btn:hover {
        background-color: #5a6268;
    }

    /* --- */
    /* Estilo específico para el botón de "Enviar" en los modales (repetido para referencia) */
    /* --- */
    .modal-submit-btn {
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 8px 15px;
        line-height: 1;
        background-color: #007bff;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }

    .modal-submit-btn:hover {
        background-color: #0056b3;
    }
</style>
</head>
<body>
   <!-- Barra superior -->
    <div style="max-width: 1800px; width: 100%; margin: auto;">

<div class="top-nav">
    <div class="container position-relative d-flex align-items-center justify-content-center"> <?php if ($tipo_usuario != 3): ?>
            <a href="report_depto_full.php?anio_semestre=<?= urlencode($anio_semestre) ?>" 
               class="btn-unicauca-light position-absolute start-0" title="Regresar a 'Gestión facultad'">
                <i class="fas fa-arrow-left me-2"></i> Regresar
            </a>
        <?php endif; ?>
        
        <div class="text-white fw-bold"> <i class="fas fa-university me-2"></i> Gestión Departamento
        </div>
        </div>
</div>
    <div class="container">
        
 <div class="institutional-card">
                 <div class="card-header-unicauca">
<div class="summary-header">
<div class="summary-container">
    <ul class="summary-data-list">
        <li>
            <span class="label-heading">Facultad:</span>
            <span class="data-value"><?php echo obtenerNombreFacultad($departamento_id); ?></span>
        </li>
        <li>
            <span class="label-heading">Departamento:</span>
<span class="data-value"><?php echo obtenerNombreDepartamento($departamento_id); ?></span>
        </li>
        <li>
            <span class="label-heading">Periodo:</span>
            <span class="data-value"><?php echo htmlspecialchars($anio_semestre); ?></span>
            </li> 
    </ul>
</div>
</div>


    <?php
                    $nombre_fac=obtenerNombreFacultad($departamento_id);

$facultad_id = obtenerIdFacultad($departamento_id);

    // Función para obtener el nombre de la facultad
    function obtenerNombreFacultad($departamento_id) {
        $conn = new mysqli('localhost', 'root', '', 'contratacion_temporales_b');
        $sql = "SELECT nombre_fac_min FROM facultad,deparmanentos WHERE
        PK_FAC = FK_FAC AND 
        deparmanentos.PK_DEPTO = '$departamento_id'";
        $result = $conn->query($sql);
        if ($result->num_rows > 0) {    
            $row = $result->fetch_assoc();
            return $row['nombre_fac_min'];
        } else {
            return "Facultad Desconocida";
        }
    }
             // Función para obtener el nombre de la facultad
    function obtenerIdFacultad($departamento_id)  {
        $conn = new mysqli('localhost', 'root', '', 'contratacion_temporales_b');
        $sql = "SELECT deparmanentos.FK_FAC  FROM deparmanentos WHERE PK_DEPTO = '$departamento_id'";
        $result = $conn->query($sql);
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['FK_FAC'];
        } else {
            return "Departamento Desconocido";
        }
    }

    // Función para obtener el nombre del departamento
    function obtenerNombreDepartamento($departamento_id) {
        $conn = new mysqli('localhost', 'root', '', 'contratacion_temporales_b');
        $sql = "SELECT depto_nom_propio FROM deparmanentos WHERE PK_DEPTO = '$departamento_id'";
        $result = $conn->query($sql);
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['depto_nom_propio'];
        } else {
            return "Departamento Desconocido";
        }
    }
function obtenerTRDDepartamento($departamento_id) {
        $conn = new mysqli('localhost', 'root', '', 'contratacion_temporales_b');
        $sql = "SELECT trd_depto FROM deparmanentos WHERE PK_DEPTO = '$departamento_id'";
        $result = $conn->query($sql);
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['trd_depto'];
        } else {
            return "Departamento Desconocido";
        }
    }

    // Establecer conexión a la base de datos
    $conn = new mysqli('localhost', 'root', '', 'contratacion_temporales_b');
    if ($conn->connect_error) {
        die("Conexión fallida: " . $conn->connect_error);
    }

    require 'cn.php';
    // Consulta SQL para obtener los tipos de docentes
$consulta_tipo = "SELECT DISTINCT tipo_docente AS tipo_d
                  FROM solicitudes_working_copy  where solicitudes_working_copy.estado <> 'an' OR solicitudes_working_copy.estado IS NULL;";

$resultadotipo = $con->query($consulta_tipo);

if (!$resultadotipo) {
    die('Error en la consulta: ' . $con->error);
}
       
     $todosCerrados = true; // Inicializar bandera
            
            
    $obtenerDeptoCerrado = obtenerDeptoCerrado($departamento_id,$anio_semestre); // si cero   no cerrado si 1  cerrado

  $totalItems = 0; // Inicializar el acumulador fuera del bucle principal
   $contadorHV = 0; // 🔹 Inicializar el contador

while ($rowtipo = $resultadotipo->fetch_assoc()) {
    $tipo_docente = $rowtipo['tipo_d'];

    $sql = "SELECT solicitudes_working_copy.*, facultad.nombre_fac_minb AS nombre_facultad, deparmanentos.depto_nom_propio AS nombre_departamento
            FROM solicitudes_working_copy
            JOIN deparmanentos ON (deparmanentos.PK_DEPTO = solicitudes_working_copy.departamento_id)
            JOIN facultad ON (facultad.PK_FAC = solicitudes_working_copy.facultad_id)
            WHERE facultad_id = '$facultad_id' AND departamento_id = '$departamento_id' AND anio_semestre = '$anio_semestre' and tipo_docente = '$tipo_docente' AND (solicitudes_working_copy.estado <> 'an' OR solicitudes_working_copy.estado IS NULL) order by solicitudes_working_copy.nombre asc";

    $result = $conn->query($sql);

    // Generate a unique ID for the section that will be hidden/shown
    // Replace spaces and special characters to ensure a valid HTML ID
    $section_id = "section-" . strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', str_replace(' ', '-', $tipo_docente)));

    echo "<div class='box-gray'>";
    echo "<div class='estado-container'>";
    // Add onclick and an icon with a unique ID for rotation
echo "<h4 style='color: #000066; cursor: pointer;' onclick=\"toggleSection('" . htmlspecialchars($section_id) . "')\" title=\"Ocultar/Mostrar Detalles\">
            <i id=\"icon_" . htmlspecialchars($section_id) . "\" class=\"fas fa-caret-down\"></i>
            Vinculación: " . htmlspecialchars($tipo_docente) . " ("; // Applied style here
if ($tipo_docente == 'Catedra') {
    $estadoDepto = obtenerCierreDeptoCatedra($departamento_id, $aniose);
    echo "<strong>" . ucfirst(strtolower($estadoDepto)) . "</strong>";
} else {
    $estadoDepto = obtenerCierreDeptoOcasional($departamento_id, $aniose);
    echo "<strong>" . ucfirst(strtolower($estadoDepto)) . "</strong>";
}
echo ")</h4>";

        if ($tipo_usuario == 3) {
            echo "
            <form action='nuevo_registro_novedad.php' method='GET'>
                <input type='hidden' name='facultad_id' value='" . htmlspecialchars($facultad_id) . "'>
                <input type='hidden' name='departamento_id' value='" . htmlspecialchars($departamento_id) . "'>
                <input type='hidden' name='anio_semestre' value='" . htmlspecialchars($anio_semestre) . "'>
                <input type='hidden' name='tipo_docente' value='" . htmlspecialchars($tipo_docente) . "'>

                <div class='d-flex gap-2'>
                    <button type='submit' class='btn btn-success'>
                        <i class='fas fa-plus'></i> Agregar Profesor
                    </button>

                
                </div>
            </form>";
        }


    


    //termina agregar novedad
    echo "</div>"; // Close estado-container

    // Obtener el conteo de profesores
    $sqlCount = "SELECT COUNT(*) as count FROM solicitudes_working_copy WHERE facultad_id = '$facultad_id' AND departamento_id = '$departamento_id' AND anio_semestre = '$anio_semestre' and tipo_docente = '$tipo_docente' AND (solicitudes_working_copy.estado <> 'an' OR solicitudes_working_copy.estado IS NULL)";
    $resultCount = $conn->query($sqlCount);
    $count = $resultCount->fetch_assoc()['count'];

    // Wrap the table content in a div with the unique ID
    echo "<div id='" . htmlspecialchars($section_id) . "' style='display: block;'>"; // Initially visible

    if ($result->num_rows > 0) {
        echo "<table border='1'>
                <tr>
                    <th rowspan='2'>Ítem</th>
                    <th rowspan='2'>Cédula</th>
                    <th rowspan='2'>Nombre</th>";

        if ($tipo_docente == "Ocasional" || $tipo_docente == "Catedra") {
            echo "<th colspan='2'>Dedicación</th>";
        }
        echo "<th colspan='2'>Hojas de vida</th>";

        if ($estadoDepto != "CERRADO") {
            echo "<th colspan='3'>Acciones</th>";
        } else {
            echo "<th colspan='3' ></th>";
        }

        echo "</tr>";

        if ($tipo_docente == "Ocasional") {
            echo "<tr>
                    <th title='Sede Popayán'>Pop</th>
                    <th title='Sede Regionalización'>Reg</th>";
        } elseif ($tipo_docente == "Catedra") {
            echo "<tr>
                    <th title='Horas en Sede Popayán'>Horas Pop</th>
                    <th title='Horas en Sede Regionalización'>Horas Reg</th>";
        }
        echo "
            <th title='Anexa Hoja de Vida para nuevos aspirantes'>Anexa (nuevo)</th>
            <th title='Actualiza Hoja de Vida para aspirantes antiguos'>Actualiza (antiguo)</th>";

            echo "<th>Eliminar</th>
                  <th>Editar</th>";
          
        

        echo "</tr>";

        $item = 1;
        $todosLosRegistrosValidos = true;
        $datos_acta = obtener_acta($anio_semestre, $departamento_id);

        $num_acta = ($datos_acta !== 0) ? htmlspecialchars($datos_acta['acta_periodo']) : "";
        $fecha_acta = ($datos_acta !== 0) ? htmlspecialchars($datos_acta['fecha_acta']) : "";

      while ($row = $result->fetch_assoc()) {
    if ($row["anexa_hv_docente_nuevo"] == 'si' || $row["actualiza_hv_antiguo"] == 'si') {
        $contadorHV++;
    }
  // Obtenemos los datos del profesor (array asociativo o false)
$datosProfesor = datosProfesorCompleto($row["cedula"], $anio_semestre);

// Preparamos el tooltip
$tooltip = '';

if ($datosProfesor !== false) {
    // Escapamos todos los valores para HTML
    $datosSeguros = array_map('htmlspecialchars', $datosProfesor);
    
    // Construimos el tooltip con formato legible
    //quite del tootlip          <strong>postulado en Departamento(s):</strong> {$datosSeguros['departamento']}<br>        <strong>Teléfono:</strong> {$datosSeguros['telefono']}<br>


    $tooltip = "
     
        <strong>Email:</strong> {$datosSeguros['email']}<br>
        <strong>Títulos:</strong> {$datosSeguros['titulos']}<br>
        <strong>Celular:</strong> {$datosSeguros['celular']}<br>
        <strong>Trabaja actualmente:</strong> {$datosSeguros['trabaja_actualmente']}<br>
        <strong>Cargo actual:</strong> {$datosSeguros['cargo_actual']}
    ";
}
// Generamos la fila de la tabla
echo "<tr>
    <td class='td-simple'>" . $item . "</td> 
    <td class='td-simple' style='text-align: left;'>" . htmlspecialchars($row["cedula"]) . "</td>
    <td class='td-simple' style='text-align: left;' 
      data-toggle='tooltip' 
      data-html='true' 
      title='" . $tooltip . "'>
      " . htmlspecialchars($row["nombre"]) . "
    </td>
";

    if ($tipo_docente == "Ocasional") {
        echo "<td class='td-simple'>" . htmlspecialchars($row["tipo_dedicacion"]) . "</td>
              <td class='td-simple'>" . htmlspecialchars($row["tipo_dedicacion_r"]) . "</td>";
    }
    if ($tipo_docente == "Catedra") {
        $horas = ($row["horas"] == 0) ? "" : htmlspecialchars($row["horas"]);
        $horas_r = ($row["horas_r"] == 0) ? "" : htmlspecialchars($row["horas_r"]);

        echo "<td class='td-simple'>" . $horas . "</td>
              <td class='td-simple'>" . $horas_r . "</td>";
    }

    // Verificar si hay un enlace válido en 'anexos'
    $anexos = trim($row["anexos"]);
    $hasValidLink = !empty($anexos) && preg_match('/^(https?:\/\/|www\.)/i', $anexos);
    
    // Mostrar anexa_hv_docente_nuevo como enlace si hay un enlace válido
    if ($row["anexa_hv_docente_nuevo"] == 'si' && $hasValidLink) {
        echo "<td class='td-simple'><a href='" . htmlspecialchars($anexos) . "' target='_blank' class='link-hv'>si</a></td>";
    } else {
        echo "<td class='td-simple'>" . htmlspecialchars($row["anexa_hv_docente_nuevo"]) . "</td>";
    }
    
    // Mostrar actualiza_hv_antiguo como enlace si hay un enlace válido
    if ($row["actualiza_hv_antiguo"] == 'si' && $hasValidLink) {
        echo "<td class='td-simple'><a href='" . htmlspecialchars($anexos) . "' target='_blank' class='link-hv'>si</a></td>";
    } else {
        echo "<td class='td-simple'>" . htmlspecialchars($row["actualiza_hv_antiguo"]) . "</td>";
    }
    
       
                echo "<td class='td-simple'>";
                if ($tipo_usuario == 3) {
                    echo "
                        <form action='eliminar.php' method='POST' style='display:inline;'>
                            <input type='hidden' name='id_solicitud' value='" . htmlspecialchars($row["id_solicitud"]) . "'>
                            <input type='hidden' name='facultad_id' value='" . htmlspecialchars($facultad_id) . "'>
                            <input type='hidden' name='departamento_id' value='" . htmlspecialchars($departamento_id) . "'>
                            <input type='hidden' name='anio_semestre' value='" . htmlspecialchars($anio_semestre) . "'>
                            <input type='hidden' name='tipo_docente' value='" . htmlspecialchars($tipo_docente) . "'>
                            <button type='submit' class='delete-btn'><i class='fa-regular fa-trash-can'></i></button>
                        </form>";
                }
                echo "</td><td class='td-simple'>";
                if ($tipo_usuario == 3) {
                    echo "
                        <form action='actualizar.php' method='GET' style='display:inline;'>
                            <input type='hidden' name='id_solicitud' value='" . htmlspecialchars($row["id_solicitud"]) . "'>
                            <input type='hidden' name='facultad_id' value='" . htmlspecialchars($facultad_id) . "'>
                            <input type='hidden' name='departamento_id' value='" . htmlspecialchars($departamento_id) . "'>
                            <input type='hidden' name='anio_semestre' value='" . htmlspecialchars($anio_semestre) . "'>
                            <input type='hidden' name='tipo_docente' value='" . htmlspecialchars($tipo_docente) . "'>
                            <button type='submit' class='update-btn'><i class='fas fa-edit'></i></button>
                        </form>";
                }
                echo "</td>";

            
            echo "</tr>";
            $item++;
        }
        $totalItems += ($item - 1);

        echo "</table>";

    } else {
        echo "<p style='text-align: center;'>No se encontraron resultados.</p>";
        if ($tipo_usuario == 3) {
            if ($tipo_usuario == 3 && $estadoDepto != 'CERRADO') {
                echo '
                <div class="row mt-3">
                    <div class="col-md-12 text-center">
                        <a href="indexsolicitud.php?tipo_docente=' . urlencode($tipo_docente) . '&anio_semestre=' . urlencode($anio_semestre) . '" class="btn btn-cargue-masivo">
                            <i class="fas fa-upload"></i> Cargue Masivo - Tipo: ' . htmlspecialchars($tipo_docente) . '
                        </a>
                    </div>
                </div>';
            }
        }
    }

    echo "</div>"; // Close the section-id div
    ?>
    <div class="d-flex justify-content-between mt-3">

        <?php
        if ($estadoDepto == "ABIERTO" && $tipo_usuario == 3) {
            $mostrarFormulario = true;
        } else {
            $mostrarFormulario = false;
        }

        if ($mostrarFormulario):
            ?>
            <form id="confirmForm" action="confirmar_tipo_d_depto.php" method="GET" onsubmit="return confirmarEnvio(<?php echo $count; ?>, '<?php echo $tipo_docente; ?>');">
                <input type="hidden" name="facultad_id" value="<?php echo htmlspecialchars($facultad_id); ?>">
                <input type="hidden" name="departamento_id" value="<?php echo htmlspecialchars($departamento_id); ?>">
                <input type="hidden" name="anio_semestre" value="<?php echo htmlspecialchars($anio_semestre); ?>">
                <input type="hidden" name="tipo_docente" value="<?php echo htmlspecialchars($tipo_docente); ?>">
                <button type="submit" class="btn btn-primary"><i class="fas fa-unlock"></i> Confirmar Profesores</button>
            </form>
        <?php endif; ?>

        <?php if ($estadoDepto == "CERRADO") {
            $envio_fac = obtenerenviof($facultad_id, $anio_semestre);
            $acepta_vra = obteneraceptacionvra($facultad_id, $anio_semestre);
            if ($tipo_usuario == 3) {
                ?>

                <form action="abrir_estado.php" method="POST">
                    <input type="hidden" name="facultad_id" value="<?php echo htmlspecialchars($facultad_id); ?>">
                    <input type="hidden" name="departamento_id" value="<?php echo htmlspecialchars($departamento_id); ?>">
                    <input type="hidden" name="anio_semestre" value="<?php echo htmlspecialchars($anio_semestre); ?>">
                    <input type="hidden" name="tipo_docente" value="<?php echo htmlspecialchars($tipo_docente); ?>">
                    <input type="hidden" name="tipo_usuario" value="<?php echo htmlspecialchars($tipo_usuario); ?>">

                    <button type="submit" class="btn btn-warning" title="Lista cerrada — haga clic para abrir y editar.">
                        <i class="fas fa-lock"></i>
                    </button>
                </form>
            <?php
            }
        }
        // Moved the modal for FOR.45 and its script outside the main loop or ensure it's rendered only once
        // Also ensure the "Novedad" modal and its script are correctly placed.
        ?>

      
    </div></div><br>
<?php
} // End of while ($rowtipo = $resultadotipo->fetch_assoc())
?> <!-- Modal Rediseñado -->
    <div class='modal fade' id='actaModal' tabindex='-1' aria-labelledby='actaModalLabel' aria-hidden='true'>
        <div class='modal-dialog modal-lg'>
            <div class='modal-content'>
                <div class='modal-header'>
                    <h5 class='modal-title' id='actaModalLabel'>FOR-45. Información del Acta y Datos Adicionales</h5>
                    <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
                </div>
                <div class='modal-body'>
                    <form id='actaForm' action='for_45.php' method='GET'>
                        <input type='hidden' name='id_solicitud' id='modal_id_solicitud'>
                        <input type='hidden' name='departamento_id' id='modal_departamento_id'>
                        <input type='hidden' name='anio_semestre' id='modal_anio_semestre'>

                        <div class='row mb-4'>
                            <div class='col-md-6'>
                                <div class="mb-3">
                                    <label for='numero_acta' class='form-label'>No. de Acta</label>
                                    <input type='text' class='form-control' id='numero_acta' name='numero_acta' required>
                                </div>
                            </div>
                            <div class='col-md-6'>
                                <div class="mb-3">
                                    <label for='fecha_actab' class='form-label'>Fecha Acta</label>
                                    <input type='date' class='form-control' id='fecha_actab' name='fecha_actab' required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <!-- Columna de Información Académica -->
                            <div class="col-md-7">
                                <div class="info-section">
                                    <div class="card-header mb-3">Información Académica (Verificar)</div>
                                    
                                    <div class="mb-3">
                                        <label for='pregrado' class='form-label'>Pregrado</label>
                                        <input type='text' class='form-control' id='pregrado' name='pregrado' placeholder="Título obtenido">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for='especializacion' class='form-label'>Especialización</label>
                                        <input type='text' class='form-control' id='especializacion' name='especializacion' placeholder="Título obtenido">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for='maestria' class='form-label'>Maestría</label>
                                        <input type='text' class='form-control' id='maestria' name='maestria' placeholder="Título obtenido">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for='doctorado' class='form-label'>Doctorado</label>
                                        <input type='text' class='form-control' id='doctorado' name='doctorado' placeholder="Título obtenido">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for='otro_estudio' class='form-label'>Otro Estudio*</label>
                                        <input type='text' class='form-control' id='otro_estudio' name='otro_estudio' placeholder="Especificar">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Columna de Experiencia -->
                            <div class="col-md-5 experience-col">
                                <div class="info-section">
                                    <div class="card-header mb-3">Experiencia (Años)</div>
                                    
                                    <div class="mb-3">
                                        <label for='experiencia_docente' class='form-label'>Experiencia Docente</label>
                                        <input type='text' class='form-control' id='experiencia_docente' name='experiencia_docente' placeholder="Años">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for='experiencia_profesional' class='form-label'>Experiencia Profesional</label>
                                        <input type='text' class='form-control' id='experiencia_profesional' name='experiencia_profesional' placeholder="Años ">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for='otra_experiencia' class='form-label'>Otra Experiencia</label>
                                        <input type='text' class='form-control' id='otra_experiencia' name='otra_experiencia' placeholder="Años">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-3 text-muted small">
                            * Por favor especificar cualquier otro estudio relevante no incluido en las categorías anteriores.
                        </div>
                    </form>
                </div>
                <div class='modal-footer'>
                    <button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cerrar</button>
                    <button type='submit' form='actaForm' class='btn btn-primary'>Guardar y Descargar</button>
                </div>
            </div>
        </div>
    </div>
<script>
    // JavaScript function to toggle the visibility of the table and rotate the icon
    function toggleSection(sectionId) {
        const section = document.getElementById(sectionId);
        const icon = document.getElementById('icon_' + sectionId);

        if (section.style.display === 'none') {
            section.style.display = 'block'; // Show the section
            icon.classList.remove('fa-caret-right'); // Change icon to down arrow
            icon.classList.add('fa-caret-down');
        } else {
            section.style.display = 'none'; // Hide the section
            icon.classList.remove('fa-caret-down'); // Change icon to right arrow
            icon.classList.add('fa-caret-right');
        }
    }

</script>
        
<script>
document.addEventListener('DOMContentLoaded', function() {
    const actaModal = document.getElementById('actaModal');

    if (actaModal) {
        actaModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            actaModal.currentButton = button; // Guardar referencia al botón que abrió el modal

            // Campos de la solicitud
            const id_solicitud = button.getAttribute('data-id-solicitud');
            const departamento_id = button.getAttribute('data-departamento-id');
            const anio_semestre = button.getAttribute('data-anio-semestre');
            const numero_acta = button.getAttribute('data-numero-acta');
            const fecha_acta = button.getAttribute('data-fecha-acta');

            // Campos de estudio y experiencia desde el botón (prioridad 1)
            let pregrado = button.getAttribute('data-pregrado');
            let especializacion = button.getAttribute('data-especializacion');
            let maestria = button.getAttribute('data-maestria');
            let doctorado = button.getAttribute('data-doctorado');
            let otro_estudio = button.getAttribute('data-otro_estudio');
            let exp_docente = button.getAttribute('data-experiencia-docente');
            let exp_profesional = button.getAttribute('data-experiencia-profesional');
            let otra_exp = button.getAttribute('data-otra-experiencia');

            // Obtener la cédula del profesor del nuevo data-attribute
            const cedulaProfesor = button.getAttribute('data-cedula-profesor'); 

            // Verificar si TODOS los campos de estudio están vacíos
            const allStudyFieldsEmpty =
                !pregrado && !especializacion && !maestria && !doctorado && !otro_estudio;

            // Si todos los campos de estudio están vacíos Y tenemos la cédula, hacemos la llamada AJAX
            if (allStudyFieldsEmpty && cedulaProfesor) {
                fetch(`get_profesor_data.php?cedula=${cedulaProfesor}&anioSemestre=${anio_semestre}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.titulos) {
                            const titulosStr = data.titulos;
                            const parsedTitulos = parseTitulos(titulosStr);

                            // Asignar los valores parseados solo si el campo correspondiente está vacío
                            document.getElementById('pregrado').value = parsedTitulos.pregrado || '';
                            document.getElementById('especializacion').value = parsedTitulos.especializacion || '';
                            document.getElementById('maestria').value = parsedTitulos.maestria || '';
                            document.getElementById('doctorado').value = parsedTitulos.doctorado || '';
                            // 'otro_estudio' de la función no se está parseando, se mantiene el comportamiento original
                        }
                    })
                    .catch(error => {
                        console.error('Error al obtener datos del profesor:', error);
                    });
            }

            // Setear valores en el formulario (siempre se asignan los que vienen del botón primero)
            document.getElementById('modal_id_solicitud').value = id_solicitud;
            document.getElementById('modal_departamento_id').value = departamento_id;
            document.getElementById('modal_anio_semestre').value = anio_semestre;
            document.getElementById('numero_acta').value = numero_acta || '';
            document.getElementById('fecha_actab').value = fecha_acta || '';

            // Setear campos de estudio y experiencia desde los data-attributes del botón
            document.getElementById('pregrado').value = pregrado || '';
            document.getElementById('especializacion').value = especializacion || '';
            document.getElementById('maestria').value = maestria || '';
            document.getElementById('doctorado').value = doctorado || '';
            document.getElementById('otro_estudio').value = otro_estudio || '';
            document.getElementById('experiencia_docente').value = exp_docente || '';
            document.getElementById('experiencia_profesional').value = exp_profesional || '';
            document.getElementById('otra_experiencia').value = otra_exp || '';
        });

        // Función para parsear el string de títulos (¡AHORA USA startsWith() Y PRIORIZA!)
        function parseTitulos(titulosStr) {
            const parsed = {
                pregrado: '',
                especializacion: '',
                maestria: '',
                doctorado: ''
            };

            // Dividir el string por saltos de línea, lo que nos dará cada título o frase
            const titlesArray = titulosStr.split(/[\r\n]+/); 

            for (const title of titlesArray) {
                const trimmedTitle = title.trim();
                if (!trimmedTitle) continue;

                const upperTrimmedTitle = trimmedTitle.toUpperCase();

                // 1. Intentar detectar Doctorado (máxima prioridad)
                if (!parsed.doctorado) { 
                    for (const keyword of keywords.doctorado) {
                        // Usar startsWith para exigir que la palabra clave esté al inicio
                        if (upperTrimmedTitle.startsWith(keyword.toUpperCase())) {
                            parsed.doctorado = trimmedTitle;
                            break; 
                        }
                    }
                    if (parsed.doctorado) continue; // Si se encontró, pasa al siguiente título del array y no busca más
                }

                // 2. Intentar detectar Maestría
                if (!parsed.maestria) { 
                    for (const keyword of keywords.maestria) {
                        // Usar startsWith para exigir que la palabra clave esté al inicio
                        if (upperTrimmedTitle.startsWith(keyword.toUpperCase())) {
                            parsed.maestria = trimmedTitle;
                            break; 
                        }
                    }
                    if (parsed.maestria) continue; // Si se encontró, pasa al siguiente título del array y no busca más
                }

                // 3. Intentar detectar Especialización
                if (!parsed.especializacion) { 
                    for (const keyword of keywords.especializacion) {
                        // Usar startsWith para exigir que la palabra clave esté al inicio
                        if (upperTrimmedTitle.startsWith(keyword.toUpperCase())) {
                            parsed.especializacion = trimmedTitle;
                            break; 
                        }
                    }
                    if (parsed.especializacion) continue; // Si se encontró, pasa al siguiente título del array y no busca más
                }

                // 4. Intentar detectar Pregrado (última prioridad)
                if (!parsed.pregrado) { 
                    for (const keyword of keywords.pregrado) {
                        // Usar startsWith para exigir que la palabra clave esté al inicio
                        if (upperTrimmedTitle.startsWith(keyword.toUpperCase())) {
                            parsed.pregrado = trimmedTitle;
                            break; 
                        }
                    }
                }
            }
            return parsed;
        }

        // El resto de tu script (envío de formulario, etc.) permanece igual.
        document.getElementById('actaForm').addEventListener('submit', function(event) {
            event.preventDefault();
            const form = this;

            const newData = {
                numero_acta: document.getElementById('numero_acta').value,
                fecha_actab: document.getElementById('fecha_actab').value,
                pregrado: document.getElementById('pregrado').value,
                especializacion: document.getElementById('especializacion').value,
                maestria: document.getElementById('maestria').value,
                doctorado: document.getElementById('doctorado').value,
                otro_estudio: document.getElementById('otro_estudio').value,
                experiencia_docente: document.getElementById('experiencia_docente').value,
                experiencia_profesional: document.getElementById('experiencia_profesional').value,
                otra_experiencia: document.getElementById('otra_experiencia').value
            };

            if (actaModal.currentButton) {
                const button = actaModal.currentButton;

                button.setAttribute('data-numero-acta', newData.numero_acta);
                button.setAttribute('data-fecha-acta', newData.fecha_actab);
                button.setAttribute('data-pregrado', newData.pregrado);
                button.setAttribute('data-especializacion', newData.especializacion);
                button.setAttribute('data-maestria', newData.maestria);
                button.setAttribute('data-doctorado', newData.doctorado);
                button.setAttribute('data-otro_estudio', newData.otro_estudio);
                button.setAttribute('data-experiencia-docente', newData.experiencia_docente);
                button.setAttribute('data-experiencia-profesional', newData.experiencia_profesional);
                button.setAttribute('data-otra-experiencia', newData.otra_experiencia);
            }

            setTimeout(() => {
                form.submit();
            }, 500);
        });
    }
});
</script>
       
    </div>    </div>

      
<!-- Botón "Ver Departamento" -->
        <div class="institutional-cardb" >
        
            

   <script>
  // Mostrar el valor de la variable $tipo_docente en la consola
  console.log("Tipo de Docente: <?php echo htmlspecialchars($tipo_docente); ?>");
</script>         
    <?php        
            
// Consulta SQL para obtener los datos
$sqlb = "SELECT 
    depto_periodo.fk_depto_dp, 
    deparmanentos.depto_nom_propio AS nombre_departamento,

    -- Docente Ocasional por sede
    SUM(CASE WHEN solicitudes_working_copy.tipo_docente = 'Ocasional' AND solicitudes_working_copy.sede = 'Popayán' THEN 1 ELSE 0 END) AS total_ocasional_popayan,
    SUM(CASE WHEN solicitudes_working_copy.tipo_docente = 'Ocasional' AND solicitudes_working_copy.sede = 'Regionalización' THEN 1 ELSE 0 END) AS total_ocasional_regionalizacion,
    SUM(CASE WHEN solicitudes_working_copy.tipo_docente = 'Ocasional' AND solicitudes_working_copy.sede = 'Popayán-Regionalización' THEN 1 ELSE 0 END) AS total_ocasional_popayan_regionalizacion,

    depto_periodo.dp_estado_ocasional,
    depto_periodo.dp_estado_total,

    -- Docente Cátedra por sede
    SUM(CASE WHEN solicitudes_working_copy.tipo_docente = 'Catedra' AND solicitudes_working_copy.sede = 'Popayán' THEN 1 ELSE 0 END) AS total_catedra_popayan,
    SUM(CASE WHEN solicitudes_working_copy.tipo_docente = 'Catedra' AND solicitudes_working_copy.sede = 'Regionalización' THEN 1 ELSE 0 END) AS total_catedra_regionalizacion,
    SUM(CASE WHEN solicitudes_working_copy.tipo_docente = 'Catedra' AND solicitudes_working_copy.sede = 'Popayán-Regionalización' THEN 1 ELSE 0 END) AS total_catedra_popayan_regionalizacion,

    depto_periodo.dp_estado_catedra

FROM 
    depto_periodo
JOIN
    solicitudes_working_copy ON solicitudes_working_copy.anio_semestre = depto_periodo.periodo 
    AND solicitudes_working_copy.departamento_id = depto_periodo.fk_depto_dp
JOIN 
    deparmanentos ON deparmanentos.PK_DEPTO = depto_periodo.fk_depto_dp

WHERE 
    fk_depto_dp = '$departamento_id' 
    AND depto_periodo.periodo = '$anio_semestre'
    AND (solicitudes_working_copy.estado <> 'an' OR solicitudes_working_copy.estado IS NULL)

GROUP BY 
    depto_periodo.fk_depto_dp, 
    deparmanentos.depto_nom_propio,
    depto_periodo.dp_estado_ocasional,
    depto_periodo.dp_estado_total,
    depto_periodo.dp_estado_catedra;
";


$resultb = $conn->query($sqlb);
//echo "consulta". $sql;
?>

<div class="">
    <div class="row">
        <div class="col-12">
            <!-- Encabezado del resumen -->
            <div class="summary-header">
                <div>
                    <h2 class="summary-title">
                        <i class="fas fa-building"></i>
                        <?php 
                        // Variables iniciales
                        $nombre_departamento = "Resumen Departamento";
                        $estado = null;
                        
                        if ($resultb->num_rows > 0) {
                            $row = $resultb->fetch_assoc();
                            $nombre_departamento = htmlspecialchars($row['nombre_departamento']);
                            $estado = $row['dp_estado_total'];
                        }
                        echo $nombre_departamento; 
                        ?>
                    </h2>
                </div>
                <div>
                    <?php 
                    // Mostrar siempre el estado cuando hay datos
                    if ($resultb->num_rows > 0): 
                        $clase_estado = ($estado == 1) ? 'status-success' : 'status-warning';
                        $icono = ($estado == 1) ? 'fa-check-circle' : 'fa-exclamation-circle';
                        $texto = ($estado == 1) ? 'ENVIADO' : 'PENDIENTE';
                    ?>
                        <div class="status-badge <?= $clase_estado ?>">
                            <i class="fas <?= $icono ?> me-2"></i>
                            <?= $texto ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
      
                    <?php
// Inicializar acumuladores por columna
$total_popayan = 0;
$total_regional = 0;
$total_ambas = 0;
$gran_total = 0;
?>


<table class="table-unicauca">
    <thead>
        <tr>
            <th></th>
            <th title="Profesores únicamente en la sede Popayán">Pop</th>
            <th title="Profesores únicamente en la sede Regionalización">Reg</th>
            <th title="Profesores que laboran en ambas sedes">Pop_Reg</th>
            <th title="Total profesores por tipo de vinculación">Total_tipo</th>
            <th>Estado</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td  class="td-simple">Ocasional</td>
            <?php
            if ($resultb->num_rows > 0) {
                $estado_ocasional = ($row['dp_estado_ocasional'] == 'ce') 
                    ? '<i class="fas fa-lock text-success" title="Cerrado"></i>' 
                    : '<i class="fas fa-unlock text-danger" title="Abierto"></i>';

                $popayan = (int)$row['total_ocasional_popayan'];
                $regional = (int)$row['total_ocasional_regionalizacion'];
                $ambas = (int)$row['total_ocasional_popayan_regionalizacion'];
                $total_ocasional = $popayan + $regional + $ambas;

                $total_popayan += $popayan;
                $total_regional += $regional;
                $total_ambas += $ambas;
                $gran_total += $total_ocasional;

                echo "<td class='td-simple'>$popayan</td>";
                echo "<td class='td-simple'>$regional</td>";
                echo "<td class='td-simple'>$ambas</td>";
                echo "<td class='fw-bold'>$total_ocasional</td>";
                echo "<td class='td-simple'>$estado_ocasional</td>";
            }
            ?>
        </tr>
        <tr>
            <td class="td-simple">Cátedra</td>
            <?php
            if ($resultb->num_rows > 0) {
                $icono_catedra = ($row['dp_estado_catedra'] == 'ce') 
                    ? '<i class="fas fa-lock text-success" title="Cerrado"></i>' 
                    : '<i class="fas fa-unlock text-danger" title="Abierto"></i>';

                $popayan = (int)$row['total_catedra_popayan'];
                $regional = (int)$row['total_catedra_regionalizacion'];
                $ambas = (int)$row['total_catedra_popayan_regionalizacion'];
                $total_catedra = $popayan + $regional + $ambas;

                $total_popayan += $popayan;
                $total_regional += $regional;
                $total_ambas += $ambas;
                $gran_total += $total_catedra;

                echo "<td class='td-simple'>$popayan</td>";
                echo "<td class='td-simple'>$regional</td>";
                echo "<td class='td-simple'>$ambas</td>";
                echo "<td class='fw-bold'>$total_catedra</td>";
                echo "<td class='td-simple'> $icono_catedra</td>";
            }
            ?>
        </tr>
        <!-- Fila de Totales por columna -->
        <tr class="table-secondary">
            <td class="td-simple">Total_x_sede</td>
            <td class="td-simple"><?= $total_popayan ?></td>
            <td class="td-simple">  <?= $total_regional ?></td>
            <td class="td-simple"><?= $total_ambas ?></td>
            <td class="td-simple"><?= $total_popayan + $total_regional + $total_ambas ?></td>
            <td></td>
        </tr>
    </tbody>
</table>
            </div>
        </div>
</div>    
<div class="row mt-3 unacauca-actions-section">
<?php if ($todosCerrados && $cierreperiodo != '1') { ?>
    <div class="col-md-12 text-center mb-3 d-grid">
        <?php if ($resultb->num_rows > 0) {
            if ($row['dp_estado_total'] == 1) {
                if ($tipo_usuario != 2) { ?>
                    <button class="btn unacauca-btn-reprint" onclick="reimprOficio_depto()" style="border-radius: 30px;">
                        Reimprimir Oficio
                    </button>
                <?php }
            } elseif ($acepta_vra == '2' && $tipo_usuario == 3) { ?>
                <button class="btn unacauca-btn-reprint" onclick="reimprOficio_depto()" style="border-radius: 30px;">
                    Reimprimir Oficio
                </button>
            <?php } elseif ($acepta_vra != '2' && $tipo_usuario == 3) { ?>
                <button class="btn btn-unicauca-primary btn-lg" data-bs-toggle="modal" data-bs-target="#myModal" style="border-radius: 30px;">
                    <i class="fas fa-file-download me-2"></i> Enviar a Facultad (Descargar Oficio)
                </button>
            <?php }
        } ?>
    </div>
<?php } else { ?>
    
        <div class="col-md-12 text-center mb-3 d-grid">
            <?php if ($tipo_usuario == 3) {
                // Mostrar botón de Reimprimir incluso si el periodo está cerrado
                if ($resultb->num_rows > 0 && ($acepta_vra == '2' || $row['dp_estado_total'] == 1)) { ?>
                    <button class="btn unacauca-btn-reprint" onclick="reimprOficio_depto()" style="border-radius: 30px;">
                        (solicitud enviada) Reimprimir Oficio
                    </button>
                <?php } elseif (!$todosCerrados) { ?>
                    <div data-bs-toggle="tooltip" data-bs-placement="top" title="Confirmar profesores para poder enviar">
                        <button class="btn btn-unicauca-primary btn-lg disabled" disabled style="border-radius: 30px;">
                            <i class="fas fa-file-download me-2"></i> Enviar a Facultad (Descargar Oficio)
                        </button>
                    </div>
                <?php } elseif ($cierreperiodo == '1') { ?>
                    <div data-bs-toggle="tooltip" data-bs-placement="top" title="Periodo <?= $anio_semestre ?> cerrado">
                        <button class="btn btn-unicauca-primary btn-lg disabled" disabled style="border-radius: 30px;">
                            <i class="fas fa-file-download me-2"></i> Enviar a Facultad (Descargar Oficio)
                        </button>
                    </div>
                <?php }
            } ?>
        </div>
   
<?php } 
    
    function obtenerPeriodoAnterior($anio_semestre) {
    list($anio, $semestre) = explode('-', $anio_semestre);
    if ($semestre == '1') {
        $anio--;
        $semestre = '2';
    } else {
        $semestre = '1';
    }
    return $anio . '-' . $semestre;
}
$anio_semestre_anterior= obtenerPeriodoAnterior($anio_semestre);
    ?>


<div class="col-md-12 text-center mb-3 d-grid">
    <a href="excel_temporales_fac.php?tipo_usuario=<?php echo htmlspecialchars($tipo_usuario); ?>&departamento_id=<?php echo htmlspecialchars($departamento_id); ?>&facultad_id=<?php echo htmlspecialchars($facultad_id); ?>&anio_semestre=<?php echo htmlspecialchars($anio_semestre); ?>" 
       class="btn btn-unicauca-success"
       style="border-radius: 30px; padding: 0.6rem 1.2rem;">
        <i class="fas fa-file-excel me-2"></i> Descargar Reporte XLS
    </a>
    
    <!-- Botón "Ver Comparativo" (estilo similar al TD de departamento_comparativo.php) -->
<form action="depto_comparativo.php" method="POST" class="d-inline mt-2">
    <input type="hidden" name="departamento_id" value="<?php echo htmlspecialchars($departamento_id); ?>">
    <input type="hidden" name="anio_semestre" value="<?php echo htmlspecialchars($anio_semestre); ?>">
    <input type="hidden" name="anio_semestre_anterior" value="<?php echo htmlspecialchars($anio_semestre_anterior); ?>">
    <!-- Bandera oculta para identificar el origen -->
    <input type="hidden" name="envia" value="consulta_todo_depto">  <!-- ¡Nuevo campo! -->
    
    <form action="<?= $archivo_regreso ?>" method="post">
    <input type="hidden" name="anio_semestre" value="<?= htmlspecialchars($anio_semestre) ?>">
    <input type="hidden" name="departamento_id" value="<?= htmlspecialchars($departamento_id) ?>">

    <button type="submit" 
            class="btn btn-outline-primary" 
            style="border-radius: 30px; 
                   padding: 0.5rem 1.5rem;
                   position: relative;
                   transition: all 0.2s;
                   border: 1px solid #0d6efd;
                   background: none;
                   width: 100%;">
        <i class="fas fa-chart-bar me-2"></i>  
        Comparativo (<?= htmlspecialchars($anio_semestre) ?> vs <?= htmlspecialchars($anio_semestre_anterior) ?>)
        <span class="badge bg-primary bg-opacity-10 text-primary ms-2" 
              style="font-size: 0.7em; 
                     position: absolute;
                     right: 15px;
                     top: 50%;
                     transform: translateY(-50%);
                     opacity: 0;
                     transition: opacity 0.3s;">
            →
        </span>
    </button>
</form>

</form>
</div>
    <?php if ($tipo_usuario == 3) {
        $aceptacion_fac = obteneraceptacionfac($departamento_id, $anio_semestre);
        $aceptacion_vra = obteneraceptacionvra($facultad_id, $anio_semestre);
        $osbservacion_fac = obtenerobs_fac($departamento_id, $anio_semestre);
        $osbservacion_vra = obtenerobs_vra($facultad_id, $anio_semestre);
    ?>
    <div class="col-md-12 text-center unacauca-status-section d-flex justify-content-center flex-wrap align-items-stretch gap-4 mb-4">
     <div class="unacauca-status-item p-3">
        <h6 class="unacauca-status-label mb-2">Respuesta Facultad:</h6>
        <div class="unacauca-status-badge-container d-flex align-items-center">
            <?php
            if ($aceptacion_fac === 'aceptar') {
                echo "<span class='unacauca-status-badge status-accepted'><i class='fas fa-check-circle me-2'></i> Aceptado</span>";
            } elseif ($aceptacion_fac === 'rechazar') {
                // Apply htmlspecialchars just ONCE for attribute safety
                echo "<span class='unacauca-status-badge status-rejected'>Devuelto</span>
                      <button class='btn unacauca-btn-icon btn-sm ms-2' data-bs-toggle='modal' data-bs-target='#modalObservacion' data-obs=\"" . htmlspecialchars($osbservacion_fac, ENT_QUOTES, 'UTF-8') . "\">
                          <i class='fa-solid fa-info-circle fa-lg'></i>
                      </button>";
            } else {
                echo "<span class='unacauca-status-badge status-pending'><i class='fas fa-hourglass-half me-2'></i> Pendiente</span>";
            }
            ?>
        </div>
    </div>

        <div class="unacauca-status-item p-3">
            <h6 class="unacauca-status-label mb-2">Respuesta Vice-Académica:</h6>
            <div class="unacauca-status-badge-container d-flex align-items-center">
                <?php
                if ($aceptacion_vra == 2) {
                    echo "<span class='unacauca-status-badge status-accepted'><i class='fas fa-check-circle me-2'></i> Aceptado</span>";
                } elseif ($aceptacion_vra == 1) {
                    echo "<span class='unacauca-status-badge status-rejected'>Devuelto</span>
                          <button class='btn unacauca-btn-icon btn-sm ms-2' data-bs-toggle='modal' data-bs-target='#modalObservacion' data-obs=\"" . htmlspecialchars($osbservacion_vra) . "\">
                              <i class='fa-solid fa-info-circle fa-lg'></i>
                          </button>";
                } else {
                    echo "<span class='unacauca-status-badge status-pending'><i class='fas fa-hourglass-half me-2'></i> Pendiente</span>";
                }
                ?>
            </div>
        </div>
    </div>
    <?php } ?>

<div class="modal fade" id="modalObservacion" tabindex="-1" role="dialog" aria-labelledby="modalObservacionLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document"> <div class="modal-content unacauca-modal-content">
            <div class="modal-header unacauca-modal-header">
                <h5 class="modal-title" id="modalObservacionLabel">Detalle de Observación</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body unacauca-modal-body">
                <p id="observacionContent" class="unacauca-observation-text text-break"></p>
            </div>
            <div class="modal-footer unacauca-modal-footer">
                <button type="button" class="btn btn-secondary unacauca-btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
    <div id="myModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content unacauca-modal-content">
                <div class="modal-header unacauca-modal-header">
                    <h5 class="modal-title" id="myModalLabel">Información Adicional</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body unacauca-modal-body">
                    <form id="modalForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="num_oficio" class="form-label">Número de Oficio</label>
                                <input type="text" class="form-control unacauca-input" id="num_oficio" name="num_oficio" value="<?php echo obtenerTRDDepartamento($departamento_id) . '/'; ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="fecha_oficio" class="form-label">Fecha de Oficio</label>
                                <input type="date" class="form-control unacauca-input" id="fecha_oficio" name="fecha_oficio" value="<?php echo date('Y-m-d', strtotime('next Monday', strtotime(date('Y-m-d')))); ?>" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="elaboro" class="form-label">Jefe de Departamento<sup>*</sup></label>
                            <input type="text" class="form-control unacauca-input" id="elaboro" name="elaboro" value="<?php echo $profe_en_cargo; ?>" placeholder="Ej. Pedro Perez" required>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6 mb-3">
                                <label for="acta" class="form-label">Número de Acta<sup>*</sup></label>
                                <input type="text" class="form-control unacauca-input" id="acta" name="acta" placeholder="Ej. No. 02" value="<?php echo $num_acta; ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="fecha_acta" class="form-label">Fecha del Acta<sup>*</sup></label>
                                <input type="date" class="form-control unacauca-input" id="fecha_acta" name="fecha_acta" value="<?php echo $fecha_acta; ?>" required>
                            </div>
                        </div>

                        <div class="form-group mt-4 unacauca-folio-section">
                            <h6 class="section-title text-unicauca-primary fw-bold mb-3">Distribución de Folios</h6>
                            <div class="row mb-2 align-items-center">
                                <div class="col-md-8 text-start">
                                    <label for="folios1" class="mb-0 label-italic">FOR-59. Acta de Selección</label>
                                </div>
                                <div class="col-md-4">
                                    <input type="number" class="form-control folio-input unacauca-input" id="folios1" name="folios1" value="1" min="0" oninput="updateFoliosTotal()" required>
                                </div>
                            </div>
                            <div class="row mb-2 align-items-center">
                                <div class="col-md-8 text-start">
                                    <label for="folios2" class="mb-0 label-italic">FOR 45. Revisión Requisitos</label>
                                </div>
                                <div class="col-md-4">
                                    <input type="number" class="form-control folio-input unacauca-input" id="folios2" name="folios2" value="<?php echo $totalItems; ?>" min="0" oninput="updateFoliosTotal()" required>
                                </div>
                            </div>
                            <div class="row mb-2 align-items-center">
                                <div class="col-md-8 text-start">
                                    <label for="folios3" class="mb-0 label-italic">Otros: (hojas de vida y actualizaciones)</label>
                                </div>
                                <div class="col-md-4">
                                    <input type="number" class="form-control folio-input unacauca-input" id="folios3" name="folios3"
                                           placeholder="0" min="0" oninput="updateFoliosTotal()"
                                           onblur="if(this.value === '') this.value = 0;">
                                </div>
                            </div>
                            <div class="mt-3 unacauca-total-folios">
                                <label class="label-italic">Total de Folios:
                                    <span id="totalFoliosDisplay" class="label-italic">
                                        <strong><?php echo $totalItems + 1; ?></strong>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <input type="hidden" id="folios" name="folios">
                    </form>
                </div>
               <div class="modal-footer unacauca-modal-footer">
    <button type="button" class="btn btn-secondary unacauca-btn-secondary modal-close-btn" data-bs-dismiss="modal">Cerrar</button>
    <button type="button" class="btn btn-primary unacauca-btn-primary modal-submit-btn" onclick="submitForm()">Enviar</button>
</div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>


<script>
document.addEventListener('DOMContentLoaded', function() {
    // ... (Tu código existente para otros modales o funciones) ...

    const observacionModal = document.getElementById('modalObservacion');

    if (observacionModal) {
        observacionModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget; // El botón que disparó el modal
            let observationText = button.getAttribute('data-obs'); // Obtiene el texto del atributo data-obs

            // *** EL TRUCO ESTÁ AQUÍ ***
            // Paso 1: Crea un elemento DIV temporal para decodificar cualquier entidad HTML.
            // Esto convierte "&lt;br /&gt;" a "<br />", "&amp;" a "&", etc.
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = observationText; // Se usa innerHTML para que el navegador decodifique
            let decodedContent = tempDiv.textContent || tempDiv.innerText || ""; // Obtiene el texto decodificado como texto plano

            // Paso 2: Ahora que tenemos el texto decodificado (con '\n' si existían o con '<br />' si la DB los guardó así),
            // convierte cualquier '\n' (salto de línea real) a la etiqueta HTML <br />.
            // Si tu base de datos ya guarda <br />, esta línea no hará cambios en ellos.
            const finalHtmlToDisplay = decodedContent.replace(/\n/g, '<br>');

            // Paso 3: Asigna el resultado final al innerHTML del párrafo del modal.
            // Esto hará que <br /> se interprete como un salto de línea HTML.
            const modalBodyP = observacionModal.querySelector('#observacionContent');
            modalBodyP.innerHTML = finalHtmlToDisplay;
        });
    }

    // ... (El resto de tu JavaScript: updateFoliosTotal, submitForm, etc.) ...
});

    // JavaScript for updating total folios
    function updateFoliosTotal() {
        const folios1 = parseInt(document.getElementById('folios1').value) || 0;
        const folios2 = parseInt(document.getElementById('folios2').value) || 0;
        const folios3 = parseInt(document.getElementById('folios3').value) || 0;
        const total = folios1 + folios2 + folios3;
        document.getElementById('totalFoliosDisplay').innerHTML = `<strong>${total}</strong>`;
        document.getElementById('folios').value = total;
    }

    // Call updateFoliosTotal on page load to set initial value
    // This is already called by the DOMContentLoaded listener above for myModal, but good to have a fallback
    // document.addEventListener('DOMContentLoaded', updateFoliosTotal); // No longer needed here as covered by modal's shown event or on load in general

    // Placeholder functions (replace with your actual backend calls)
    function reimprOficio_depto() {
        const departamento_id = encodeURIComponent('<?php echo $departamento_id; ?>');
        const anio_semestre = encodeURIComponent('<?php echo $anio_semestre; ?>');
        const url = `oficio_depto_reimpr.php?departamento_id=${departamento_id}&anio_semestre=${anio_semestre}`;
        window.location.href = url;
    }

    function submitForm() {
        const form = document.getElementById('modalForm');
        if (form.checkValidity()) {
            alert('Formulario de envío de Oficio de Departamento enviado.');
            // Here you would typically use AJAX to submit the form data
            // e.g., fetch('your_submit_endpoint.php', { method: 'POST', body: new FormData(form) });
            // Correct way to close Bootstrap 5 modal with vanilla JS
            var myModalInstance = bootstrap.Modal.getInstance(document.getElementById('myModal'));
            if (myModalInstance) {
                myModalInstance.hide();
            }
        } else {
            // Trigger browser's default validation UI
            form.reportValidity();
        }
    }

    // Existing jQuery-dependent scripts (keep these if they rely on jQuery + Bootstrap JS working together)
    $(document).ready(function() {
        // Script for selectAll and individualCheckbox
        $('#selectAll').change(function() {
            const isChecked = $(this).is(':checked');
            $('.individualCheckbox').prop('checked', isChecked).trigger('change');
        });

        $('.individualCheckbox').change(function() {
            const form = $(this).closest('form');
            $.ajax({
                url: 'update_visto_bueno.php',
                type: 'POST',
                data: form.serialize(),
                success: function(response) {
                    console.log('Estado actualizado exitosamente.');
                },
                error: function() {
                    alert('Error al actualizar el estado.');
                }
            });
        });

        // The tooltip initialization using jQuery for Bootstrap 4 was here: $('[data-toggle="tooltip"]').tooltip();
        // It has been replaced by the vanilla JS Bootstrap 5 initialization in DOMContentLoaded.

        // The observation modal logic using jQuery was here:
        // $('#modalObservacion').on('show.bs.modal', function(event) { ... });
        // It has been replaced by vanilla JS in DOMContentLoaded for Bootstrap 5.
    });

    // This jQuery listener for myModal 'shown.bs.modal' is now handled by vanilla JS in DOMContentLoaded
    // $('#myModal').on('shown.bs.modal', function (e) {
    //     updateFoliosTotal();
    // });

    // The functions below (`eliminarRegistros` and `reimprOficio_depto`)
    // were already defined correctly outside of the jQuery block.
    // They are kept as is, as they appear in the previous full code.
  

    function reimprOficio_depto() {
        const departamento_id = encodeURIComponent('<?php echo $departamento_id; ?>');
        const anio_semestre = encodeURIComponent('<?php echo $anio_semestre; ?>');
        const url = `oficio_depto_reimpr.php?departamento_id=${departamento_id}&anio_semestre=${anio_semestre}`;
        window.location.href = url;
    }
</script>


<script>
    $(document).ready(function() {
        // Script for selectAll and individualCheckbox
        $('#selectAll').change(function() {
            const isChecked = $(this).is(':checked');
            $('.individualCheckbox').prop('checked', isChecked).trigger('change');
        });

        $('.individualCheckbox').change(function() {
            const form = $(this).closest('form');
            $.ajax({
                url: 'update_visto_bueno.php',
                type: 'POST',
                data: form.serialize(),
                success: function(response) {
                    console.log('Estado actualizado exitosamente.');
                },
                error: function() {
                    alert('Error al actualizar el estado.');
                }
            });
        });

        // Bootstrap Tooltip Initialization
  $('[data-toggle="tooltip"]').tooltip({
        html: true,
        placement: 'auto', // Elige automáticamente la mejor posición
        fallbackPlacement: 'flip', // Si no cabe, invierte la posición
        boundary: 'viewport'
    });
        // JavaScript for handling the observation modal
        // Listen for the 'show.bs.modal' event on the observation modal
        $('#modalObservacion').on('show.bs.modal', function(event) {
            // Get the button that triggered the modal
            var button = $(event.relatedTarget);

            // Extract the observation from the 'data-obs' attribute of that button
            var observation = button.data('obs');

            // Debugging: Log the observation to the console
            console.log("Observation received:", observation);

            // Get the modal itself
            var modal = $(this);

            // Find the paragraph where the observation should be displayed
            // Use .text() for plain text. If observations contain HTML (e.g., <br>), use .html()
            modal.find('#textoObservacion').text(observation);

            // Alternative if .text() is not working or if you suspect HTML issues:
            // modal.find('#textoObservacion').html(observation);
            // If observation might have line breaks, ensure the CSS 'white-space: pre-wrap;' is applied to #textoObservacion
        });
    });
</script>
<script>

    document.addEventListener('DOMContentLoaded', function() {
        const fechaOficioInput = document.getElementById('fecha_oficio');
        let today = new Date();
        let day = today.getDay();

        // If today is Saturday (6) or Sunday (0), adjust date to next Monday
        if (day === 6) { // Saturday
            today.setDate(today.getDate() + 2);
        } else if (day === 0) { // Sunday
            today.setDate(today.getDate() + 1);
        }

        // Format date to YYYY-mm-dd for date input value
        let year = today.getFullYear();
        let month = String(today.getMonth() + 1).padStart(2, '0');
        let date = String(today.getDate()).padStart(2, '0');
        let formattedDate = `${year}-${month}-${date}`;

        fechaOficioInput.value = formattedDate;
    });
</script>

<script>
    function updateFoliosTotal() {
        var folios1 = parseInt(document.getElementById('folios1').value) || 0;
        var folios2 = parseInt(document.getElementById('folios2').value) || 0;
        var folios3 = parseInt(document.getElementById('folios3').value) || 0;

        var totalFolios = folios1 + folios2 + folios3;

        document.getElementById('folios').value = totalFolios;
        document.getElementById('totalFoliosDisplay').innerHTML = "<strong>" + totalFolios + "</strong>";
    }

    function submitForm() {
        updateFoliosTotal();

        var numOficio = document.getElementById('num_oficio').value;
        var fechaOficio = document.getElementById('fecha_oficio').value;
        var elaboro = document.getElementById('elaboro').value;
        var acta = document.getElementById('acta').value;
        var fechaActa = document.getElementById('fecha_acta').value;
        var folios = document.getElementById('folios').value;
        var folios3 = document.getElementById('folios3').value;

        folios3 = folios3.trim() === '' ? 0 : parseInt(folios3, 10);

        // var contadorHV = <?php //echo $contadorHV; ?>; // Uncomment if needed

        // Re-enable validation if needed
        /*if (folios3 < contadorHV) {
            var mensaje = contadorHV === 1
                ? 'Verificar número de folios de ' + contadorHV + ' hoja de vida nueva y/o actualización informada.'
                : 'Verificar número de folios de las ' + contadorHV + ' hojas de vida nuevas y/o actualizaciones informadas.';
            alert(mensaje);
            return;
        }*/

        if (numOficio === '' || fechaOficio === '' || elaboro === '' || acta === '' || fechaActa === '') {
            alert('Por favor, diligencie los campos obligatorios (*).');
            return;
        }

        var departamentoId = "<?php echo urlencode($departamento_id); ?>";
        var anioSemestre = "<?php echo urlencode($anio_semestre); ?>";
        var nombrefac = "<?php echo urlencode($nombre_fac); ?>";

        var url = 'oficio_depto.php?folios=' + folios + '&departamento_id=' + departamentoId + '&anio_semestre=' + anioSemestre + '&nombre_fac=' + nombrefac + '&num_oficio=' + encodeURIComponent(numOficio) + '&fecha_oficio=' + encodeURIComponent(fechaOficio) + '&elaboro=' + encodeURIComponent(elaboro) + '&acta=' + encodeURIComponent(acta) + '&fecha_acta=' + encodeURIComponent(fechaActa);

        window.location.href = url;

        setTimeout(function() {
            window.location.reload();
        }, 1000);
    }
</script>
            
<?php
// Cerrar la conexión a la base de datos
$conn->close();
?>
    </div>    
            

<script>
    document.getElementById('confirmForm').addEventListener('submit', function() {
        setTimeout(function() {
            location.reload();
        }, 3000); // Espera 3 segundos antes de recargar la página
    });
</script>
    <script>
    // Script para cerrar el modal automáticamente después de enviar el formulario
    $('#oficioForm').on('submit', function() {
        $('#oficioModal').modal('hide');  // Cerrar el modal
    });

    $('#oficioFacultadForm').on('submit', function() {
        $('#oficioModal').modal('hide');  // Cerrar el modal
    });
</script>
  </div>


    </body>
    
</html>
<?php       // Función para obtener el cierreo no de departamento
    function obtenerCierreDeptoCatedra($departamento_id,$aniose) {
        $conn = new mysqli('localhost', 'root', '', 'contratacion_temporales_b');
        $sql = "SELECT depto_periodo.dp_estado_catedra FROM depto_periodo WHERE fk_depto_dp = '$departamento_id' and periodo ='$aniose'";
        $result = $conn->query($sql);   
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return ($row['dp_estado_catedra'] == 'ce') ? "CERRADO" : "ABIERTO";
        } else {
            return "estado depto Desconocida";
        }
    } // ocasional
    function obtenerCierreDeptoOcasional($departamento_id,$aniose) {
        $conn = new mysqli('localhost', 'root', '', 'contratacion_temporales_b');
        $sql = "SELECT depto_periodo.dp_estado_ocasional FROM depto_periodo WHERE fk_depto_dp = '$departamento_id' and periodo ='$aniose'";
        $result = $conn->query($sql);
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return ($row['dp_estado_ocasional'] == 'ce') ? "CERRADO" : "ABIERTO";
        } else {
            return "estado depto Desconocida";
        }
    } 


?>
