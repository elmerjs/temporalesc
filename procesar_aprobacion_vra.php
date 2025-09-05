<?php
// procesar_aprobacion_vra.php

// --- CONFIGURACIÓN Y SEGURIDAD INICIAL ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once('conn.php');
session_start(); // Es crucial iniciar la sesión para leer las variables

header('Content-Type: application/json');

// --- 1. Verificación de sesión y permisos (VERSIÓN CORREGIDA Y SEGURA) ---

// Primero, verificamos que el usuario haya iniciado sesión.
if (!isset($_SESSION['name'])) {
    echo json_encode(['success' => false, 'error' => 'Sesión no iniciada. Por favor, ingrese de nuevo.']);
    exit;
}

// Ahora, consultamos la base de datos para obtener el tipo de usuario real.
// Esto evita depender de variables de sesión que pueden no estar actualizadas.
$nombre_sesion = $_SESSION['name'];
$stmt_user = $conn->prepare("SELECT tipo_usuario FROM users WHERE Name = ?");
if (!$stmt_user) {
    echo json_encode(['success' => false, 'error' => 'Error preparando la consulta de usuario.']);
    exit;
}
$stmt_user->bind_param("s", $nombre_sesion);
$stmt_user->execute();
$result_user = $stmt_user->get_result();

if ($result_user->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Usuario de sesión no encontrado en la base de datos.']);
    exit;
}

$user_row = $result_user->fetch_assoc();
$tipo_usuario_db = $user_row['tipo_usuario'];
$stmt_user->close();

// Finalmente, comparamos el tipo de usuario de la base de datos.
if ($tipo_usuario_db != 1) {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado. Permiso insuficiente.']);
    exit;
}
// --- FIN DE LA VERIFICACIÓN CORREGIDA ---



// --- 2. Recepción y validación de datos (VERSIÓN CORREGIDA) ---
$accion = $_POST['accion'] ?? null;
// Leemos el string de IDs y lo convertimos en un array
$ids_string = $_POST['ids'] ?? '';
$ids = !empty($ids_string) ? explode(',', $ids_string) : null;
$observacion = trim($_POST['observacion'] ?? '');

if (!$accion || !$ids) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos o en formato incorrecto.']);
    exit;
}


// 3. Mapeo de acción a estado de la base de datos
$estado_db = '';
if ($accion === 'aprobar') {
    $estado_db = 'APROBADO';
} elseif ($accion === 'rechazar') {
    $estado_db = 'RECHAZADO';
    if (empty($observacion)) {
        echo json_encode(['success' => false, 'error' => 'La observación es obligatoria para rechazar.']);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Acción no válida.']);
    exit;
}

// 4. Preparación de la consulta SQL
    // --- 4. Preparación de la consulta SQL (VERSIÓN CORREGIDA) ---
    // La sanitización ahora se hace sobre el array que creamos nosotros
    $ids_sanitizados = array_filter($ids, 'is_numeric');

    if (empty($ids_sanitizados)) {
         echo json_encode(['success' => false, 'error' => 'No se proporcionaron IDs válidos.']);
         exit;
    }

// 5. Vinculación de parámetros y ejecución
$stmt->bind_param("ss" . $types, $estado_db, $observacion, ...$ids_sanitizados);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Error al ejecutar la actualización: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>