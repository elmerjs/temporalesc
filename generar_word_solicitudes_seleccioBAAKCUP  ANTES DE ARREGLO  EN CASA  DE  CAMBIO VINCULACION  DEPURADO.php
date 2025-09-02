<?php
require_once 'vendor/autoload.php';
require_once 'conn.php';

date_default_timezone_set('America/Bogota');

use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Language;

setlocale(LC_TIME, 'es_ES.UTF-8', 'es_ES', 'Spanish_Spain', 'es');

// Parámetros recibidos
$anio_semestre = $_POST['anio_semestre'] ?? '';
$id_facultad = (int)($_POST['id_facultad'] ?? 0);
$numero_oficio = $_POST['oficio'] ?? 'S/N';
$fecha_oficio = $_POST['fecha_oficio'] ?? date('Y-m-d');
$decano_nombre = $_POST['decano'] ?? 'Decano/a';
$elaborado_por = $_POST['elaborado_por'] ?? 'Responsable Facultad';
$folios = (int)($_POST['folios'] ?? 0);
$numero_acta = $_POST['numero_acta'] ?? '';

// === NUEVA LÓGICA PARA RECIBIR Y PROCESAR LOS IDs SELECCIONADOS ===
$selected_ids_str = $_POST['selected_ids_for_word'] ?? '';
$selected_ids_array = [];
if (!empty($selected_ids_str)) {
    // Convertir la cadena de IDs de nuevo a un array de enteros
    $selected_ids_array = array_map('intval', explode(',', $selected_ids_str));
    // Asegurarse de que el array no esté vacío después de la conversión
    if (empty($selected_ids_array)) {
        die("No se proporcionaron IDs de solicitud válidos.");
    }
} else {
    die("Debe proporcionar los IDs de solicitud para generar el oficio.");
}
// ===================================================================

if (empty($anio_semestre) || $id_facultad === 0) {
    die("Debe proporcionar el año-semestre y la facultad para generar el oficio.");
}

// Mapeo de imágenes por facultad
$facultades = [
    1 => ['encab' => 'img/encabezado_decanatura_artes.png', 'pie' => 'img/pieartes.png'],
    2 => ['encab' => 'img/encabezado_decanatura_agrarias.png', 'pie' => 'img/pieagro.png'],
    3 => ['encab' => 'img/encabezado_decanatura_salud.png', 'pie' => 'img/piesalud.png'],
    4 => ['encab' => 'img/encabezado_decanatura_fccea.png', 'pie' => 'img/piecontables.png'],
    5 => ['encab' => 'img/encabezado_decanatura_humanas.png', 'pie' => 'img/piehumanas.png'],
    6 => ['encab' => 'img/encabezado_decanatura_facnedx.png', 'pie' => 'img/piefacned.png'],
    7 => ['encab' => 'img/encabezado_decanatura_derecho.png', 'pie' => 'img/piederecho.png'],
    8 => ['encab' => 'img/encabezado_decanatura_civil.png', 'pie' => 'img/piecivil.png'],
    9 => ['encab' => 'img/encabezado_decanatura_fiet.png', 'pie' => 'img/piefiet.png']
];

// Determinar imágenes según facultad
if (isset($facultades[$id_facultad])) {
    $imgencabezado = $facultades[$id_facultad]['encab'];
    $imgpie = $facultades[$id_facultad]['pie'];
} else {
    $imgencabezado = 'img/encabezado_generico.png';
    $imgpie = 'img/piegenerico.png';
}

// Función auxiliar para bind_param, necesaria cuando se usa call_user_func_array
function refValues($arr){
    if (strnatcmp(phpversion(),'5.3') >= 0) //PHP 5.3+
    {
        $refs = array();
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
    return $arr;
}

// ===================================================================
// LÓGICA PARA DETECTAR Y PROCESAR "CAMBIO DE VINCULACIÓN"
// ===================================================================
$cambio_vinculacion_data = [];
$cedulas_cambio_vinculacion = [];
$ids_cambio_vinculacion = []; // IDs de las solicitudes que son "Cambio de Vinculación"

// Primero, identificar los pares de "eliminar" y "adicionar" dentro de los IDs seleccionados
$placeholders_ids_seleccionados = implode(',', array_fill(0, count($selected_ids_array), '?'));

$sql_cambio_vinculacion = "
    SELECT
        t1.id_solicitud AS id_eliminar,
        t1.cedula,
        t1.departamento_id,
        t1.nombre AS nombre_eliminar,
        t1.tipo_dedicacion AS dedicacion_eliminar,
        t1.tipo_dedicacion_r AS dedicacion_eliminar_r,

        t1.horas AS horas_eliminar,
        t1.horas_r AS horas_r_eliminar,

        t1.s_observacion AS observacion_eliminar,
        t2.id_solicitud AS id_adicionar,
        t2.nombre AS nombre_adicionar,
        t1.tipo_docente as tipo_docente_eliminar,
        t2.tipo_docente,
        t2.tipo_dedicacion AS dedicacion_adicionar,
        t2.horas AS horas_adicionar,
        t2.tipo_dedicacion_r,
        t2.horas_r,
        t2.s_observacion AS observacion_adicionar,
        t2.anexa_hv_docente_nuevo,
        t2.actualiza_hv_antiguo,
        f.nombre_fac_minb AS nombre_facultad,
        d.depto_nom_propio AS nombre_departamento
    FROM solicitudes_working_copy t1
    JOIN solicitudes_working_copy t2
        ON t1.cedula = t2.cedula
        AND t1.departamento_id = t2.departamento_id
        AND t1.anio_semestre = t2.anio_semestre
    JOIN facultad f ON t1.facultad_id = f.PK_FAC
    JOIN deparmanentos d ON t1.departamento_id = d.PK_DEPTO
    WHERE t1.novedad = 'eliminar'
      AND t2.novedad = 'adicionar'
      AND t1.anio_semestre = ?
      AND t1.facultad_id = ?
      AND t1.estado_facultad = 'APROBADO'
      AND t1.estado_vra = 'PENDIENTE'
      AND t2.estado_facultad = 'APROBADO'
      AND t2.estado_vra = 'PENDIENTE'
      AND t1.id_solicitud IN ($placeholders_ids_seleccionados)
      AND t2.id_solicitud IN ($placeholders_ids_seleccionados)
    ORDER BY d.depto_nom_propio ASC, t1.nombre ASC
";

$stmt_cambio_vinculacion = $conn->prepare($sql_cambio_vinculacion);
if ($stmt_cambio_vinculacion) {
    $types_cambio = 'si' . str_repeat('i', count($selected_ids_array) * 2); // 'si' para anio_semestre, id_facultad, luego 'i' por cada ID en los dos IN clauses
    $params_cambio = array_merge([$types_cambio, $anio_semestre, $id_facultad], $selected_ids_array, $selected_ids_array);
    call_user_func_array([$stmt_cambio_vinculacion, 'bind_param'], refValues($params_cambio));
    $stmt_cambio_vinculacion->execute();
    $result_cambio_vinculacion = $stmt_cambio_vinculacion->get_result();
    while ($row = $result_cambio_vinculacion->fetch_assoc()) {
        $cambio_vinculacion_data[] = $row;
        $cedulas_cambio_vinculacion[] = $row['cedula'];
        $ids_cambio_vinculacion[] = $row['id_eliminar'];
        $ids_cambio_vinculacion[] = $row['id_adicionar'];
    }
    $stmt_cambio_vinculacion->close();
} else {
    die("Error al preparar la consulta de cambio de vinculación: " . $conn->error);
}

// Convertir las cédulas de cambio de vinculación y los IDs a un string para la cláusula IN
// Solo únicos para evitar problemas con la cláusula IN
$cedulas_excluir = array_unique($cedulas_cambio_vinculacion);
$cedulas_excluir_str = !empty($cedulas_excluir) ? "'" . implode("','", $cedulas_excluir) . "'" : "'_NONE_'"; // Usa un valor que no exista si el array está vacío

$ids_excluir = array_unique($ids_cambio_vinculacion);
$ids_excluir_str = !empty($ids_excluir) ? implode(',', $ids_excluir) : '0'; // Usa 0 si el array está vacío


// === MODIFICACIÓN DE LA CONSULTA SQL PRINCIPAL PARA EXCLUIR CAMBIOS DE VINCULACIÓN ===
$sql = "
    SELECT
        sw.id_solicitud,
        sw.cedula,
        sw.nombre,
        sw.novedad,
        sw.tipo_docente,
        sw.tipo_dedicacion,
        sw.horas,
        sw.tipo_dedicacion_r,
        sw.horas_r,
        sw.s_observacion,
        sw.observacion_facultad,
        sw.costo,
        sw.anexa_hv_docente_nuevo,
        sw.actualiza_hv_antiguo,
        f.nombre_fac_minb AS nombre_facultad,
        d.depto_nom_propio AS nombre_departamento
    FROM solicitudes_working_copy sw
    JOIN facultad f ON sw.facultad_id = f.PK_FAC
    JOIN deparmanentos d ON sw.departamento_id = d.PK_DEPTO
    WHERE sw.anio_semestre = ?
      AND sw.facultad_id = ?
      AND sw.estado_facultad = 'APROBADO'
      AND sw.estado_vra = 'PENDIENTE'
      AND sw.id_solicitud IN ($placeholders_ids_seleccionados)
      AND sw.id_solicitud NOT IN ($ids_excluir_str) -- ¡Excluir IDs de Cambio de Vinculación!
    ORDER BY d.depto_nom_propio ASC, sw.novedad ASC, sw.nombre ASC
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    // Reconstruir los parámetros para la consulta principal
    $types = 'si' . str_repeat('i', count($selected_ids_array));
    $params = array_merge([$types, $anio_semestre, $id_facultad], $selected_ids_array);

    call_user_func_array([$stmt, 'bind_param'], refValues($params));

    $stmt->execute();
    $result = $stmt->get_result();
    $solicitudes = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    die("Error al preparar la consulta de solicitudes: " . $conn->error);
}

if (empty($solicitudes) && empty($cambio_vinculacion_data)) {
    die("No se encontraron solicitudes APROBADAS por Facultad y PENDIENTES por VRA para el periodo, facultad y IDs seleccionados.");
}

$nombre_facultad_principal = ($solicitudes[0]['nombre_facultad'] ?? $cambio_vinculacion_data[0]['nombre_facultad']) ?? 'Facultad Desconocida';

// Agrupar solicitudes por departamento y luego por novedad, recolectando observaciones
$grouped_solicitudes = [];
$grouped_observations = [];
foreach ($solicitudes as $sol) {
    $departamento = $sol['nombre_departamento'];
    $novedad_tipo = $sol['novedad'];

    if (!isset($grouped_solicitudes[$departamento])) {
        $grouped_solicitudes[$departamento] = [];
        $grouped_observations[$departamento] = [];
    }
    if (!isset($grouped_solicitudes[$departamento][$novedad_tipo])) {
        $grouped_solicitudes[$departamento][$novedad_tipo] = [];
        $grouped_observations[$departamento][$novedad_tipo] = [];
    }
    $grouped_solicitudes[$departamento][$novedad_tipo][] = $sol;

    if (!empty($sol['s_observacion']) && !in_array($sol['s_observacion'], $grouped_observations[$departamento][$novedad_tipo])) {
        $grouped_observations[$departamento][$novedad_tipo][] = $sol['s_observacion'];
    }
}

// Generar el documento de Word usando PHPWord
$phpWord = new \PhpOffice\PhpWord\PhpWord();
// Configurar idioma español (Colombia)
$phpWord->getSettings()->setThemeFontLang(new Language(Language::ES_ES));

// Estilos
$phpWord->addFontStyle('boldSize12', ['bold' => true, 'size' => 12]);
$phpWord->addFontStyle('normalSize11', ['size' => 11]);
$phpWord->addFontStyle('normalSize9', ['size' => 9]);
$phpWord->addParagraphStyle('center', ['alignment' => Jc::CENTER]);
$phpWord->addParagraphStyle('justify', ['alignment' => Jc::BOTH]);
$phpWord->addParagraphStyle('left', ['alignment' => Jc::LEFT]);

// Estilos para tabla
$styleTable = array(
    'borderSize' => 6,
    'borderColor' => '999999',
    'cellMargin' => 60,
    'alignment' => Jc::CENTER
);
$phpWord->addTableStyle('ColspanRowspan', $styleTable);

$cellTextStyle = ['size' => 9, 'bold' => false];
$cellTextStyleb = ['size' => 9, 'bold' => true];
$paragraphStyle = ['alignment' => Jc::CENTER, 'spaceAfter' => 0];
$headerCellStyle = [
    'bgColor' => 'F2F2F2',
    'borderSize' => 6,
    'borderColor' => '999999',
    'valign' => 'center'
];

$fontStyleb = ['bold' => true, 'size' => 10];
$observationTextStyle = ['size' => 9, 'italic' => true];
$paragraphStyleLeft = ['alignment' => Jc::LEFT, 'spaceAfter' => 0, 'spaceBefore' => 0];

// ==================================================
// SECCIÓN CON MÁRGENES PARA ENCABEZADO/PIE
// ==================================================
$section = $phpWord->addSection([
    'marginTop'    => 1200,  // Espacio para encabezado (1200 twips = ~2.12 cm)
    'marginBottom' => 1000,  // Espacio para pie de página
    'marginLeft'   => 1000,
    'marginRight'  => 1000
]);

// ==================================================
// ENCABEZADO CON IMAGEN
// ==================================================
$header = $section->addHeader();
$header->addImage($imgencabezado, [
    'width'       => 200,
    'alignment'   => Jc::LEFT
]);

// ==================================================
// PIE DE PÁGINA CON IMAGEN
// ==================================================
$footer = $section->addFooter();
$footer->addImage($imgpie, [
    'width'       => 450,
    'alignment'   => Jc::CENTER
]);

// ==================================================
// CONTENIDO DEL DOCUMENTO
// ==================================================
$section->addTextBreak(0);  // Espacio después del encabezado
$paragraphStylexz = array('lineHeight' => 0.8, 'spaceAfter' => 0, 'spaceBefore' => 0);
$styleNoSpace = ['align' => 'left', 'spaceAfter' => 0];

$fontStylecuerpo = array('name' => 'Arial', 'size' => 11);
$fecha_actual = strftime('%d de %B de %Y', strtotime($fecha_oficio));

$section->addText($numero_oficio, ['size' => 11], $styleNoSpace);
$section->addText('Popayán, ' . $fecha_actual,$fontStylecuerpo,$paragraphStylexz);
if (!empty($numero_acta)) {
    $section->addText('Número de Acta: ' . $numero_acta, 'normalSize11', 'center');
}
$section->addTextBreak(1);

// Destinatario (Vicerrectoría Académica)

$section->addText('Doctora', ['size' => 11], $styleNoSpace);
$section->addText('AIDA PATRICIA GONZALEZ NIEVA', ['size' => 11], $styleNoSpace);
$section->addText('Vicerrectora Académica', ['size' => 11], $styleNoSpace);
$section->addText('Universidad del Cauca', ['size' => 11], $styleNoSpace);
$section->addTextBreak(1);

$section->addText(
    'Asunto: Novedades de Vinculación Profesores temporales ' . $anio_semestre . '.',
    [ 'size' => 11], 'left'
);
$section->addTextBreak(1);

$section->addText('Cordial saludo,', 'normalSize11', 'left');
$section->addTextBreak(0);

$section->addText(
    'Para su conocimiento y trámite pertinente remito solicitud de novedades de la Facultad de ' . $nombre_facultad_principal . ' periodo: ' . $anio_semestre . ',  para los siguientes profesores:',
    'normalSize11', 'justify'
);
$section->addTextBreak(1);

// ==================================================
// SECCIÓN: Novedad Cambio de Vinculación
// ==================================================
if (!empty($cambio_vinculacion_data)) {
    $section->addText('Novedad: Cambio de Vinculación', ['bold' => true, 'size' => 11]);
    $section->addTextBreak(0);

 
foreach ($cambio_vinculacion_data as $cambio) {
    // 1. Añadir la observación general/contexto al inicio (si existe)
    if (!empty($cambio['observacion_adicionar'])) {
        $section->addText(htmlspecialchars($cambio['observacion_adicionar']), $observationTextStyle, $paragraphStyleLeft);
    }

    // 2. Construir la frase narrativa de transición del profesor
    $tipo_docente_eliminar = $cambio['tipo_docente_eliminar'];
    
    // Determinar los valores para la vinculación que se elimina
    if ($tipo_docente_eliminar == "Ocasional") {
        // Para Ocasional, usar dedicación (MT o TC) - priorizar el campo principal, sino el _r
        $dedicacion_eliminar_val = !empty($cambio['dedicacion_eliminar']) ? $cambio['dedicacion_eliminar'] : $cambio['dedicacion_eliminar_r'];
        $dedicacion_eliminar_str = '';
        
        if (!empty($dedicacion_eliminar_val)) {
            // Convertir abreviaciones a texto completo
            $dedicacion_eliminar_str = str_replace(
                ['MT', 'TC'], 
                ['Medio Tiempo', 'Tiempo Completo'], 
                htmlspecialchars($dedicacion_eliminar_val)
            );
        }
        
        $salida_part = $dedicacion_eliminar_str;
    } else if ($tipo_docente_eliminar == "Catedra") {
        // Para Cátedra, usar horas - mostrar ambos si existen, sino el que tenga valor
        $horas_eliminar_p = !empty($cambio['horas_eliminar']) ? htmlspecialchars($cambio['horas_eliminar']) : '';
        $horas_eliminar_r = !empty($cambio['horas_eliminar_r']) ? htmlspecialchars($cambio['horas_eliminar_r']) : '';
        
        $horas_eliminar_str = '';
        if (!empty($horas_eliminar_p) && !empty($horas_eliminar_r)) {
            $horas_eliminar_str = "{$horas_eliminar_p} (P) / {$horas_eliminar_r} (R) horas";
        } elseif (!empty($horas_eliminar_p)) {
            $horas_eliminar_str = "{$horas_eliminar_p} horas";
        } elseif (!empty($horas_eliminar_r)) {
            $horas_eliminar_str = "{$horas_eliminar_r} horas";
        }
        
        $salida_part = $horas_eliminar_str;
    } else {
        $salida_part = '';
    }

    // Construir parte de la nueva vinculación
    $tipo_docente_str = htmlspecialchars($cambio['tipo_docente'] ?: '');
    $nueva_vinculacion_dedicacion_horas = '';
    
    if ($cambio['tipo_docente'] == "Ocasional") {
        $dedicacion_val = !empty($cambio['dedicacion_adicionar']) ? $cambio['dedicacion_adicionar'] : $cambio['tipo_dedicacion_r'];
        if (!empty($dedicacion_val)) {
            $nueva_vinculacion_dedicacion_horas = str_replace(
                ['MT', 'TC'], 
                ['Medio Tiempo', 'Tiempo Completo'], 
                htmlspecialchars($dedicacion_val)
            );
        }
    } elseif ($cambio['tipo_docente'] == "Catedra") {
        $horas_val = !empty($cambio['horas_adicionar']) ? $cambio['horas_adicionar'] : $cambio['horas_r'];
        if (!empty($horas_val)) {
            $nueva_vinculacion_dedicacion_horas = htmlspecialchars($horas_val) . ' horas';
        }
    }

    $nombre_profesor = htmlspecialchars($cambio['nombre_adicionar'] ?: $cambio['nombre_eliminar']);

    // Construcción del texto narrativo con lógica mejorada
    $narrative_text = "Con el fin de atender esta necesidad, solicitamos comedidamente que el profesor {$nombre_profesor}";
    
    // Parte de la vinculación actual (que se elimina)
    $narrative_text .= " que pasa de {$tipo_docente_eliminar}";
    if (!empty($salida_part)) {
        $narrative_text .= " - {$salida_part}";
    }
    
    // Parte de la nueva vinculación
    $narrative_text .= " a {$tipo_docente_str}";
    if (!empty($nueva_vinculacion_dedicacion_horas)) {
        $narrative_text .= " {$nueva_vinculacion_dedicacion_horas}";
    }
    $narrative_text .= ".";

    $section->addText($narrative_text, ['size' => 9], $paragraphStyleLeft);
    $section->addTextBreak(0); // Pequeña separación

    // 3. Tabla para la "Nueva Vinculación"
    $section->addText('Nueva Vinculación:', ['bold' => true, 'size' => 9], $paragraphStyleLeft);
    $table_cambio = $section->addTable('ColspanRowspan');
    $table_cambio->setWidth(100 * 50, TblWidth::PERCENT);

    // Encabezados de tabla
    $table_cambio->addRow();
    $table_cambio->addCell(1500, $headerCellStyle)->addText('Tipo Docente', $cellTextStyleb, $paragraphStyle);
    $table_cambio->addCell(1500, $headerCellStyle)->addText('Dedic/hr (Pop)', $cellTextStyleb, $paragraphStyle);
    $table_cambio->addCell(1500, $headerCellStyle)->addText('Dedic/hr (Reg)', $cellTextStyleb, $paragraphStyle);
    $table_cambio->addCell(1000, $headerCellStyle)->addText('H. de Vida (Nuevo)', $cellTextStyleb, $paragraphStyle);
    $table_cambio->addCell(1000, $headerCellStyle)->addText('H. de Vida (Antig)', $cellTextStyleb, $paragraphStyle);

    // Fila de datos
    $table_cambio->addRow();
    $table_cambio->addCell(1500, ['borderSize' => 1, 'valign' => 'center'])->addText(htmlspecialchars($cambio['tipo_docente'] ?: ''), $cellTextStyle, $paragraphStyle);
    
    if ($cambio['tipo_docente'] == "Ocasional") {
        $table_cambio->addCell(1500, ['borderSize' => 1, 'valign' => 'center'])->addText(htmlspecialchars($cambio['dedicacion_adicionar'] ?: ''), $cellTextStyle, $paragraphStyle);
        $table_cambio->addCell(1500, ['borderSize' => 1, 'valign' => 'center'])->addText(htmlspecialchars($cambio['tipo_dedicacion_r'] ?: ''), $cellTextStyle, $paragraphStyle);
    } elseif ($cambio['tipo_docente'] == "Catedra") {
        $table_cambio->addCell(1500, ['borderSize' => 1, 'valign' => 'center'])->addText(htmlspecialchars($cambio['horas_adicionar'] ?: ''), $cellTextStyle, $paragraphStyle);
        $table_cambio->addCell(1500, ['borderSize' => 1, 'valign' => 'center'])->addText(htmlspecialchars($cambio['horas_r'] ?: ''), $cellTextStyle, $paragraphStyle);
    } else {
        $table_cambio->addCell(1500, ['borderSize' => 1, 'valign' => 'center'])->addText('', $cellTextStyle, $paragraphStyle);
        $table_cambio->addCell(1500, ['borderSize' => 1, 'valign' => 'center'])->addText('', $cellTextStyle, $paragraphStyle);
    }

    $table_cambio->addCell(1000, ['borderSize' => 1, 'valign' => 'center'])->addText(
        mb_strtoupper(htmlspecialchars($cambio['anexa_hv_docente_nuevo'] ?: ''), 'UTF-8'), 
        $cellTextStyle, 
        $paragraphStyle
    );
    
    $table_cambio->addCell(1000, ['borderSize' => 1, 'valign' => 'center'])->addText(
        mb_strtoupper(htmlspecialchars($cambio['actualiza_hv_antiguo'] ?: ''), 'UTF-8'), 
        $cellTextStyle, 
        $paragraphStyle
    );
    
    $section->addTextBreak(1); // Un salto de línea después de cada cambio de vinculación
}
    $section->addTextBreak(1); // Espacio después de la sección de Cambio de Vinculación
}

// Iterar por departamentos y luego por novedades dentro de cada departamento (EXCLUYENDO los de cambio de vinculación)
foreach ($grouped_solicitudes as $departamento_nombre => $novedades_por_depto) {
    $section->addText('Departamento de ' . htmlspecialchars($departamento_nombre), ['bold' => true, 'size' => 11]);
    $section->addTextBreak(0);

    foreach ($novedades_por_depto as $novedad_tipo => $solicitudes_por_novedad) {
        $novedad_mostrar = ucfirst($novedad_tipo);
        $section->addText('Novedad: ' . htmlspecialchars($novedad_mostrar), $fontStyleb, $paragraphStyleLeft);

        if (!empty($grouped_observations[$departamento_nombre][$novedad_tipo])) {
            $observation_text = '';
            foreach ($grouped_observations[$departamento_nombre][$novedad_tipo] as $index => $obs) {
                $observation_text .= '(' . ($index + 1) . ') ' . htmlspecialchars($obs) . ' ';
            }
            $section->addText($observation_text, $observationTextStyle, $paragraphStyleLeft);
            $section->addTextBreak(0);
        }
        $section->addTextBreak(0);

        $table = $section->addTable('ColspanRowspan');
        $table->setWidth(100 * 50, TblWidth::PERCENT);

        // Encabezados de la tabla - Primera fila
        $row = $table->addRow();

        // Nº
        $textrun = $row->addCell(400, array_merge($headerCellStyle, array('vMerge' => 'restart')))->addTextRun($paragraphStyle);
        $textrun->addText('N', $cellTextStyle);
        $textrun->addText('o', array_merge($cellTextStyle, array('superScript' => true)));

        // Cédula
        $row->addCell(1200, array_merge($headerCellStyle, array('vMerge' => 'restart')))->addText('Cédula', $cellTextStyle, $paragraphStyle);

        // Nombre
        $row->addCell(4000, array_merge($headerCellStyle, array('vMerge' => 'restart')))->addText('Nombre', $cellTextStyle, $paragraphStyle);

        // Dedicación/hr
        $row->addCell(1400, array_merge($headerCellStyle, array('alignment' => Jc::CENTER, 'gridSpan' => 2, 'vMerge' => 'restart')))->addText('Dedic/hr', $cellTextStyle, $paragraphStyle);

        // Hoja de vida
        $row->addCell(700, array_merge($headerCellStyle, array('alignment' => Jc::CENTER, 'gridSpan' => 2, 'vMerge' => 'restart')))->addText('H.de Vida', $cellTextStyle, $paragraphStyle);

        // Tipo Docente
        $row->addCell(1000, array_merge($headerCellStyle, array('vMerge' => 'restart')))->addText('Tipo Docente', $cellTextStyle, $paragraphStyle);


        // Encabezados de la tabla - Segunda fila
        $row = $table->addRow();
        // Celdas 'continue' para los vMerge 'restart' de la fila anterior
        $row->addCell(400, array('vMerge' => 'continue', 'borderSize' => 6, 'borderColor' => '999999')); // Nº
        $row->addCell(1200, array('vMerge' => 'continue', 'borderSize' => 6, 'borderColor' => '999999')); // Cédula
        $row->addCell(4000, array('vMerge' => 'continue', 'borderSize' => 6, 'borderColor' => '999999')); // Nombre

        // Sub-encabezados de Dedicación/hr
        $row->addCell(700, $headerCellStyle)->addText('Pop', $cellTextStyleb, $paragraphStyle);
        $row->addCell(700, $headerCellStyle)->addText('Reg', $cellTextStyleb, $paragraphStyle);

        // Sub-encabezados de Hoja de vida
        $row->addCell(350, $headerCellStyle)->addText('Nuevo', $cellTextStyleb, $paragraphStyle);
        $row->addCell(350, $headerCellStyle)->addText('Antig', $cellTextStyleb, $paragraphStyle);

        // Celdas 'continue' para Tipo Docente
        $row->addCell(1000, array('vMerge' => 'continue', 'borderSize' => 6, 'borderColor' => '999999')); // Tipo Docente

        $cont = 0; // Contador de filas dentro de cada tabla de novedad
        foreach ($solicitudes_por_novedad as $sol) {
            $cont++;
            $table->addRow();

            // Columna Nº
            $table->addCell(400, ['borderSize' => 1, 'marginTop' => 0, 'marginBottom' => 0])->addText($cont, $cellTextStyle, $paragraphStyle);

            // Columna Cédula
            $table->addCell(1200, ['borderSize' => 1, 'marginTop' => 0, 'marginBottom' => 0])->addText(htmlspecialchars($sol['cedula'] ?: ''), $cellTextStyle, $paragraphStyle);

            // Columna Nombre
            $full_nombre = htmlspecialchars($sol['nombre'] ?: '');
            $table->addCell(4000, ['borderSize' => 1, 'marginTop' => 0, 'marginBottom' => 0])->addText($full_nombre, $cellTextStyle, $paragraphStyle);

            // Columnas de Dedicación/horas según el tipo de docente
            if ($sol['tipo_docente'] == "Ocasional") {
                $table->addCell(700, ['borderSize' => 1, 'marginTop' => 0, 'marginBottom' => 0])->addText(htmlspecialchars($sol['tipo_dedicacion'] ?: ''), $cellTextStyle, $paragraphStyle);
                $table->addCell(700, ['borderSize' => 1, 'marginTop' => 0, 'marginBottom' => 0])->addText(htmlspecialchars($sol['tipo_dedicacion_r'] ?: ''), $cellTextStyle, $paragraphStyle);
            } elseif ($sol['tipo_docente'] == "Catedra") {
                $table->addCell(700, ['borderSize' => 1, 'marginTop' => 0, 'marginBottom' => 0])->addText(htmlspecialchars($sol['horas'] ?: ''), $cellTextStyle, $paragraphStyle);
                $table->addCell(700, ['borderSize' => 1, 'marginTop' => 0, 'marginBottom' => 0])->addText(htmlspecialchars($sol['horas_r'] ?: ''), $cellTextStyle, $paragraphStyle);
            } else {
                $table->addCell(700, ['borderSize' => 1, 'marginTop' => 0, 'marginBottom' => 0])->addText('', $cellTextStyle, $paragraphStyle);
                $table->addCell(700, ['borderSize' => 1, 'marginTop' => 0, 'marginBottom' => 0])->addText('', $cellTextStyle, $paragraphStyle);
            }

            // Columnas de Hoja de Vida
            $table->addCell(350, ['borderSize' => 1, 'marginTop' => 0, 'marginBottom' => 0])
                ->addText(mb_strtoupper(htmlspecialchars($sol['anexa_hv_docente_nuevo'] ?: ''), 'UTF-8'), $cellTextStyle, $paragraphStyle);

            $table->addCell(350, ['borderSize' => 1, 'marginTop' => 0, 'marginBottom' => 0])
                ->addText(mb_strtoupper(htmlspecialchars($sol['actualiza_hv_antiguo'] ?: ''), 'UTF-8'), $cellTextStyle, $paragraphStyle);

            // Columna Tipo Docente
            $table->addCell(1000, ['borderSize' => 1, 'marginTop' => 0, 'marginBottom' => 0])
                ->addText(htmlspecialchars($sol['tipo_docente'] ?: ''), $cellTextStyle, $paragraphStyle);
        }
        $section->addTextBreak(1); // Espacio entre tablas de novedad
    }
}

$section->addTextBreak(1);
$section->addText(
    'Agradezco su atención al presente. Para cualquier inquietud, no dude en contactarme.',
    'normalSize11', 'justify'
);
$section->addTextBreak(2);

// Firma (Decano)
$section->addText(
    'Atentamente,',
    'normalSize11', 'left'
);
$section->addTextBreak(2);

$section->addText(
    $decano_nombre,
    ['bold' => true, 'size' => 11], 'left'
);
$section->addText(
    'Decano/a de la Facultad de ' . $nombre_facultad_principal,
    'normalSize11', 'left'
);
$section->addTextBreak(1);
$section->addText(
    'Elaborado por: ' . $elaborado_por,
    'normalSize9', 'left'
);
$section->addText(
    'Folios: ' . $folios,
    'normalSize9', 'left'
);

// ===================================================================
// ACTUALIZAR ESTADO DE SOLICITUDES A 'ENVIADO'
// ===================================================================
// Recolectar todos los IDs de solicitudes que fueron incluidas en el documento/*
/*$ids_a_actualizar = [];
foreach ($solicitudes as $sol) {
    $ids_a_actualizar[] = $sol['id_solicitud'];
}
// Añadir los IDs de las solicitudes de "Cambio de Vinculación"
foreach ($cambio_vinculacion_data as $cambio) {
    $ids_a_actualizar[] = $cambio['id_eliminar'];
    $ids_a_actualizar[] = $cambio['id_adicionar'];
}
$ids_a_actualizar = array_unique($ids_a_actualizar); // Asegurar IDs únicos

if (!empty($ids_a_actualizar)) {
    $placeholders_update = implode(',', array_fill(0, count($ids_a_actualizar), '?'));
        $sql_update = "UPDATE solicitudes_working_copy SET estado_vra = estado_vra WHERE id_solicitud IN ($placeholders_update)";
    $stmt_update = $conn->prepare($sql_update);

    if ($stmt_update) {
        $types_update = str_repeat('i', count($ids_a_actualizar));
        call_user_func_array([$stmt_update, 'bind_param'], refValues(array_merge([$types_update], $ids_a_actualizar)));
        $stmt_update->execute();
        $stmt_update->close();
    } else {
        error_log("Error al preparar la actualización de estado: " . $conn->error);
    }
}
*/
// Configurar el nombre del archivo y las cabeceras para la descarga
$fileName = 'Novedades_' . $anio_semestre . '_Facultad_' . $nombre_facultad_principal . '_' . date('Ymd') . '.docx';
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

// Guardar el documento en la salida
$objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
$objWriter->save('php://output');

// Cerrar la conexión a la base de datos
$conn->close();
?>