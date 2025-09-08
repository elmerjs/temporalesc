<?php
// procesar_aprobacion_vra.php (Versión Corregida para Observaciones Individuales)

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once('conn.php');

$response = ['success' => false, 'error' => 'Error desconocido.'];

try {
    if ($conn->connect_error) {
        throw new Exception('Fallo en la conexión a la base de datos: ' . $conn->connect_error);
    }
    session_start();

    // 1. Verificación de Permisos (sin cambios)
    if (!isset($_SESSION['name'])) throw new Exception('Sesión no iniciada.');
    $nombre_sesion = $_SESSION['name'];
    $stmt_user = $conn->prepare("SELECT Id, tipo_usuario FROM users WHERE Name = ?");
    $stmt_user->bind_param("s", $nombre_sesion);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user->num_rows === 0) throw new Exception('Usuario de sesión no encontrado.');
    $user_row = $result_user->fetch_assoc();
    $aprobador_id = $user_row['Id'];
    if ($user_row['tipo_usuario'] != 1) throw new Exception('Acceso denegado.');
    $stmt_user->close();

    // CAMBIO 1: Recibimos un JSON de solicitudes en lugar de datos sueltos
    $accion = $_POST['accion'] ?? null;
    $solicitudes_json = $_POST['solicitudes'] ?? null;
    $anio_semestre = $_POST['anio_semestre'] ?? null;

    if (!$accion || !$solicitudes_json || !$anio_semestre) {
        throw new Exception('Datos incompletos (accion, solicitudes o anio_semestre).');
    }

    $solicitudes = json_decode($solicitudes_json, true);

    if (empty($solicitudes) || !is_array($solicitudes)) {
        throw new Exception('No se proporcionaron solicitudes válidas.');
    }
    
    $estado_db = ($accion === 'aprobar') ? 'APROBADO' : 'RECHAZADO';

    // CAMBIO 2: Usamos una transacción. Si algo falla, se revierte todo.
    $conn->begin_transaction();

    // CAMBIO 3: Preparamos una sola consulta y la ejecutamos en un bucle para cada solicitud
    // Nota: La columna de la llave primaria parece ser 'solicitud_id' según tu SQL y el JS.
    $sql = "UPDATE solicitudes_working_copy 
            SET estado_vra = ?, observacion_vra = ?, fecha_aprobacion_vra = NOW(), aprobador_vra_id = ? 
            WHERE id_solicitud = ? AND estado_vra = 'PENDIENTE'";
    
    $stmt = $conn->prepare($sql);

    foreach ($solicitudes as $sol) {
        $id = $sol['id'] ?? null;
        $observacion = trim($sol['observacion'] ?? '');

        if (!is_numeric($id)) continue; // Ignorar si el ID no es válido

        if ($estado_db === 'RECHAZADO' && empty($observacion)) {
            // Si una sola observación falta al rechazar, detenemos todo.
            throw new Exception("La observación es obligatoria para rechazar la solicitud ID: " . $id);
        }

        // La lógica de "pareo" se podría integrar aquí si fuera necesario,
        // pero por ahora procesamos directamente lo que el usuario seleccionó.

        $stmt->bind_param("ssii", $estado_db, $observacion, $aprobador_id, $id);
        if (!$stmt->execute()) {
            // Si una sola actualización falla, detenemos todo.
            throw new Exception("Error al actualizar la solicitud ID: " . $id . " - " . $stmt->error);
        }
    }

    // Si el bucle se completó sin errores, confirmamos todos los cambios en la BD
    $conn->commit();
    $stmt->close();
    
    $response['success'] = true;

} catch (Exception $e) {
    // Si ocurrió cualquier error, revertimos todos los cambios.
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    $response['error'] = $e->getMessage();
} finally {
    if (isset($conn) && $conn->ping()) {
        $conn->close();
    }
}

ob_end_clean();
echo json_encode($response);
exit;
?>