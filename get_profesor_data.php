<?php
header('Content-Type: application/json');

// Incluye tu función datosProfesorCompleto
require_once 'funciones.php'; 

$cedula = $_GET['cedula'] ?? '';
$anioSemestre = $_GET['anioSemestre'] ?? '';

if (empty($cedula) || empty($anioSemestre)) {
    echo json_encode(['error' => 'Cédula y año/semestre son requeridos.']);
    exit;
}

$datosProfesor = datosProfesorCompleto($cedula, $anioSemestre);

if ($datosProfesor) {
    echo json_encode(['titulos' => $datosProfesor['titulos']]);
} else {
    echo json_encode(['titulos' => null]);
}
?>
