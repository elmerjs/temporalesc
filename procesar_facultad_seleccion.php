<?php
// procesar_facultad_seleccion.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
session_start();

require_once('conn.php');

function responder_json($success, $message, $data = []) {
    ob_clean();
    die(json_encode(['success' => $success, 'message' => $message, 'data' => $data]));
}

// --- 1. VALIDACIÓN DE DATOS ---
$action = $_POST['action'] ?? '';
$selected_ids = $_POST['selected_ids'] ?? [];
$observacion = $_POST['observacion'] ?? '';
$anio_semestre = $_POST['anio_semestre'] ?? '';

$id_facultad = $_SESSION['id_facultad'] ?? null;
$aprobador_id_logged_in = $_SESSION['aprobador_id_logged_in'] ?? null;

if (empty($action) || empty($selected_ids) || is_null($id_facultad) || is_null($aprobador_id_logged_in)) {
    responder_json(false, 'La sesión o los datos de la solicitud son inválidos. Por favor, recargue la página e inicie sesión de nuevo.');
}

// --- 2. LÓGICA DE PROCESAMIENTO MEJORADA ---
try {
    $conn->begin_transaction();

    // El array final de IDs que se van a actualizar
    $ids_a_procesar = $selected_ids;

    // ===========================================================================
    // ===== INICIO DE LA NUEVA LÓGICA PARA ENCONTRAR PAREJAS "ELIMINAR" ========
    // ===========================================================================

    // Primero, buscamos las cédulas de los registros "Adicionar" que hemos seleccionado
    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
    $types = str_repeat('i', count($selected_ids));
    $sql_find_cedulas = "SELECT cedula FROM solicitudes_working_copy WHERE id_solicitud IN ($placeholders) AND (novedad = 'Adicion' OR novedad = 'adicionar')";
    
    $stmt_cedulas = $conn->prepare($sql_find_cedulas);
    $stmt_cedulas->bind_param($types, ...$selected_ids);
    $stmt_cedulas->execute();
    $result_cedulas = $stmt_cedulas->get_result();
    $cedulas_de_adiciones = [];
    while ($row = $result_cedulas->fetch_assoc()) {
        $cedulas_de_adiciones[] = $row['cedula'];
    }
    $stmt_cedulas->close();

    // Si encontramos cédulas, buscamos sus contrapartes "Eliminar" pendientes
    if (!empty($cedulas_de_adiciones)) {
        $placeholders_cedulas = implode(',', array_fill(0, count($cedulas_de_adiciones), '?'));
        $types_cedulas = str_repeat('s', count($cedulas_de_adiciones));

        $sql_find_eliminar = "SELECT id_solicitud FROM solicitudes_working_copy WHERE cedula IN ($placeholders_cedulas) AND novedad = 'Eliminar' AND anio_semestre = ? AND facultad_id = ? AND estado_facultad = 'PENDIENTE'";
        $stmt_eliminar = $conn->prepare($sql_find_eliminar);
        
        $params_eliminar = array_merge($cedulas_de_adiciones, [$anio_semestre, $id_facultad]);
        $stmt_eliminar->bind_param($types_cedulas . 'si', ...$params_eliminar);
        
        $stmt_eliminar->execute();
        $result_eliminar = $stmt_eliminar->get_result();
        while ($row = $result_eliminar->fetch_assoc()) {
            $ids_a_procesar[] = $row['id_solicitud']; // Añadimos el ID del registro "Eliminar"
        }
        $stmt_eliminar->close();
    }
    
    // Nos aseguramos de que no haya IDs duplicados
    $ids_a_procesar = array_unique(array_map('intval', $ids_a_procesar));

    // ===========================================================================
    // ===== FIN DE LA NUEVA LÓGICA ==============================================
    // ===========================================================================

    // Ahora, ejecutamos la actualización en el listado COMPLETO de IDs
    $new_status_facultad = ($action === 'avalar') ? 'APROBADO' : 'RECHAZADO';
    $success_count = 0;
    
    $update_sql = "UPDATE solicitudes_working_copy SET estado_facultad = ?, fecha_aprobacion_facultad = NOW(), aprobador_facultad_id = ?, observacion_facultad = ? WHERE id_solicitud = ? AND facultad_id = ?";
    $stmt_update = $conn->prepare($update_sql);

    foreach ($ids_a_procesar as $id) {
        $stmt_update->bind_param("sisii", $new_status_facultad, $aprobador_id_logged_in, $observacion, $id, $id_facultad);
        if ($stmt_update->execute() && $stmt_update->affected_rows > 0) {
            $success_count++;
        }
    }
    $stmt_update->close();
    
    $conn->commit();
    
   if ($success_count > 0) {
    // AÑADIMOS UN TERCER PARÁMETRO CON LA LISTA COMPLETA DE IDs
    responder_json(true, "$success_count registros han sido procesados exitosamente (incluyendo contrapartes de 'Cambio de Vinculación').", ['processed_ids' => array_values($ids_a_procesar)]);
} else {
        responder_json(false, 'Ningún registro pudo ser actualizado. Es posible que ya hubieran sido procesados.');
    }

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollback();
    }
    responder_json(false, "Error crítico: " . $e->getMessage());
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>