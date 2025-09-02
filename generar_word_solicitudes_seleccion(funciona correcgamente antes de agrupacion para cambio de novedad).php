<?php
require_once 'vendor/autoload.php';
require_once 'conn.php';

date_default_timezone_set('America/Bogota');

use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;

// Parámetros recibidos
$anio_semestre = $_POST['anio_semestre'] ?? '';
$id_facultad = (int)($_POST['id_facultad'] ?? 0);
$numero_oficio = $_POST['numero_oficio'] ?? 'S/N';
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

// === MODIFICACIÓN DE LA CONSULTA SQL Y EL BINDING DE PARÁMETROS ===
// Crear un string de placeholders para la cláusula IN (ej. '?,?,?')
$placeholders = implode(',', array_fill(0, count($selected_ids_array), '?'));

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
      AND sw.id_solicitud IN ($placeholders) -- ¡Aquí se añade la condición IN!
    ORDER BY d.depto_nom_propio ASC, sw.novedad ASC, sw.nombre ASC
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    // Construir la cadena de tipos de parámetros
    // 's' para anio_semestre, 'i' para id_facultad, y 'i' por cada ID seleccionado
    $types = 'si' . str_repeat('i', count($selected_ids_array));
    // Combinar todos los parámetros en un solo array para bind_param
    $params = array_merge([$types, $anio_semestre, $id_facultad], $selected_ids_array);

    // Usar call_user_func_array para llamar a bind_param dinámicamente
    call_user_func_array([$stmt, 'bind_param'], refValues($params));

    $stmt->execute();
    $result = $stmt->get_result();
    $solicitudes = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    die("Error al preparar la consulta de solicitudes: " . $conn->error);
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

if (empty($solicitudes)) {
    die("No se encontraron solicitudes APROBADAS por Facultad y PENDIENTES por VRA para el periodo, facultad y IDs seleccionados.");
}

$nombre_facultad_principal = $solicitudes[0]['nombre_facultad'] ?? 'Facultad Desconocida';

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


$section->addText($numero_oficio, ['bold' => true, 'size' => 11], 'left');
$section->addText('Popayán, ' . (new DateTime($fecha_oficio))->format('d \de F \de Y'), 'normalSize11', 'left');
if (!empty($numero_acta)) {
    $section->addText('Número de Acta: ' . $numero_acta, 'normalSize11', 'center');
}
$section->addTextBreak(1);

// Destinatario (Vicerrectoría Académica)
$section->addText('Señor(a)', 'normalSize11', 'left');
$section->addText('Vicerrector/a Académico/a', ['bold' => true, 'size' => 11], 'left');
$section->addText('Universidad del Cauca', 'normalSize11', 'left');
$section->addText('Ciudad.', 'normalSize11', 'left');
$section->addTextBreak(1);

$section->addText(
    'Asunto: Novedades de Contratos Docentes ' . $anio_semestre . '.',
    ['bold' => true, 'size' => 11], 'left'
);
$section->addTextBreak(1);

$section->addText('Cordial saludo,', 'normalSize11', 'left');
$section->addTextBreak(1);

$section->addText(
    'Me permito presentar a su consideración las novedades de contratos docentes correspondientes al período académico ' . $anio_semestre . ' de la Facultad de ' . $nombre_facultad_principal . ' para los siguientes docentes:',
    'normalSize11', 'justify'
);
$section->addTextBreak(1);

// Iterar por departamentos y luego por novedades dentro de cada departamento
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

// Configurar el nombre del archivo y las cabeceras para la descarga
$fileName = 'Oficio_Decanatura_Novedades_' . $anio_semestre . '_Facultad_' . $nombre_facultad_principal . '_' . date('Ymd_His') . '.docx';
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

// Guardar el documento en la salida
$objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
$objWriter->save('php://output');

// Cerrar la conexión a la base de datos
$conn->close();
?>