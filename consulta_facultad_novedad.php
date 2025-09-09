<?php
// consulta_vra.php
// ARCHIVO DEDICADO Y CORREGIDO EXCLUSIVAMENTE PARA EL USUARIO TIPO 1 (VICERRECTORÍA)

// --- INCLUDES Y CONFIGURACIÓN INICIAL ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

require('include/headerz.php');
require_once('conn.php');
require('funciones.php');

// --- VARIABLES ESENCIALES ---
$anio_semestre = $_POST['anio_semestre'] ?? $_GET['anio_semestre'] ?? '2025-2';

// Obtenemos los datos del usuario logueado
$tipo_usuario = null;
if (isset($_SESSION['name'])) {
    $nombre_sesion = $_SESSION['name'];
    $stmt_user = $conn->prepare("SELECT Id, fk_fac_user, fk_depto_user, tipo_usuario FROM users WHERE Name = ?");
    $stmt_user->bind_param("s", $nombre_sesion);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();

    if ($result_user->num_rows > 0) {
        $user_row = $result_user->fetch_assoc();
        $tipo_usuario = $user_row['tipo_usuario'];
    }
    $stmt_user->close();
}

// --- VALIDACIÓN DE ACCESO ---
if ($tipo_usuario === null) {
    die('<div class="container mx-auto p-8 text-center"><h1 class="text-2xl font-bold text-red-600">Error: Sesión no iniciada o usuario no encontrado.</h1></div>');
} elseif ($tipo_usuario != 1) {
    die('<div class="container mx-auto p-8 text-center"><h1 class="text-2xl font-bold text-red-600">Error: Acceso denegado.</h1><p class="text-gray-600 mt-2">Esta página es solo para Vicerrectoría Académica.</p></div>');
}

// *** INICIO DE LA MODIFICACIÓN ***

// --- FUNCIÓN DE PROCESAMIENTO DE DATOS ---
// Se añade la función para detectar y consolidar los cambios de vinculación.
function procesarCambiosVinculacion($solicitudes) {
    $adiciones = [];
    $eliminaciones = [];
    $otras_novedades = [];
    $resultado_final = [];

    // 1. Clasificar solicitudes
    foreach ($solicitudes as $sol) {
        $cedula = $sol['cedula'];
        if (strtolower($sol['novedad']) === 'adicion' || strtolower($sol['novedad']) === 'adicionar') {
            $adiciones[$cedula] = $sol;
        } elseif (strtolower($sol['novedad']) === 'eliminar') {
            $eliminaciones[$cedula] = $sol;
        } else {
            $otras_novedades[] = $sol;
        }
    }

    // 2. Procesar coincidencias
    foreach ($adiciones as $cedula => $sol_adicion) {
        if (isset($eliminaciones[$cedula])) {
            $sol_eliminacion = $eliminaciones[$cedula];

            $tipo_docente_anterior = ($sol_eliminacion['tipo_docente'] === 'Catedra') ? 'Cátedra' : $sol_eliminacion['tipo_docente'];
            $estado_anterior = "Sale de " . $tipo_docente_anterior;
            if ($sol_eliminacion['tipo_docente'] === 'Ocasional') {
                if ($sol_eliminacion['tipo_dedicacion']) $estado_anterior .= " " . $sol_eliminacion['tipo_dedicacion'] . " Popayán";
                elseif ($sol_eliminacion['tipo_dedicacion_r']) $estado_anterior .= " " . $sol_eliminacion['tipo_dedicacion_r'] . " Regionalización";
            } elseif ($sol_eliminacion['tipo_docente'] === 'Catedra') {
                if ($sol_eliminacion['horas'] && $sol_eliminacion['horas'] > 0) $estado_anterior .= " " . $sol_eliminacion['horas'] . " horas Popayán";
                elseif ($sol_eliminacion['horas_r'] && $sol_eliminacion['horas_r'] > 0) $estado_anterior .= " " . $sol_eliminacion['horas_r'] . " horas Regionalización";
            }

            $sol_adicion['novedad'] = 'Cambio Vinculación';
            $observacion_existente = trim($sol_adicion['s_observacion']);
            $sol_adicion['s_observacion'] = $observacion_existente . ($observacion_existente ? ' ' : '') . '(' . $estado_anterior . ')';

            $resultado_final[] = $sol_adicion;
            unset($eliminaciones[$cedula]);
        } else {
            $resultado_final[] = $sol_adicion;
        }
    }
    return array_merge($resultado_final, array_values($eliminaciones), $otras_novedades);
}

// --- LÓGICA PARA VICERRECTORÍA ACADÉMICA (VRA) ---
$sql_vra = "
    SELECT
        f.NOMBREF_FAC AS nombre_facultad,
        d.depto_nom_propio AS nombre_departamento, 
                s.id_solicitud AS solicitud_id, 
        s.*
    FROM solicitudes_working_copy s
    JOIN deparmanentos d ON s.departamento_id = d.PK_DEPTO
    JOIN facultad f ON s.facultad_id = f.PK_FAC
    WHERE s.anio_semestre = ?
      AND s.oficio_con_fecha_fac IS NOT NULL 
      AND s.estado_facultad = 'APROBADO'
      AND s.oficio_con_fecha_fac != ''
    ORDER BY f.NOMBREF_FAC ASC, d.depto_nom_propio ASC, s.oficio_con_fecha_fac ASC
";

$stmt_vra = $conn->prepare($sql_vra);
$stmt_vra->bind_param("s", $anio_semestre);
$stmt_vra->execute();
$todas_las_solicitudes_vra_raw = $stmt_vra->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_vra->close();

// 1. Agrupar todas las solicitudes por departamento para poder procesarlas
$solicitudes_por_depto = [];
foreach ($todas_las_solicitudes_vra_raw as $sol) {
    $solicitudes_por_depto[$sol['nombre_facultad']][$sol['nombre_departamento']][] = $sol;
}

// 2. Procesar cada grupo de departamento y reconstruir las estructuras de datos finales
$solicitudes_procesadas_flat = []; // Para el JSON plano que usa el modal
$datos_agrupados_vra = []; // Para el JSON anidado que usa la UI de acordeones

foreach ($solicitudes_por_depto as $nombre_facultad => $departamentos) {
    foreach ($departamentos as $nombre_departamento => $solicitudes_depto) {
        
        // Aplicamos la función de procesamiento aquí
        $solicitudes_procesadas_depto = procesarCambiosVinculacion($solicitudes_depto);

        // Reconstruimos las dos estructuras de datos con la información ya procesada
        foreach ($solicitudes_procesadas_depto as $sol_procesada) {
            $solicitudes_procesadas_flat[] = $sol_procesada;
            
            $oficio = $sol_procesada['oficio_con_fecha_fac'];
            $datos_agrupados_vra[$nombre_facultad][$nombre_departamento][$oficio][] = $sol_procesada;
        }
    }
}

// Se crean los JSON con los datos ya procesados y consolidados
$solicitudes_json = json_encode($solicitudes_procesadas_flat);
$estructura_vra_json = json_encode($datos_agrupados_vra);

// *** FIN DE LA MODIFICACIÓN ***

$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Novedades - VRA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
     .rotate-180 { transform: rotate(180deg); }
    .transition-transform { transition: transform 0.3s ease; }

    /* Estilo para el botón de DEPARTAMENTO abierto */
    .active-accordion {
        background-color: #eef2ff; /* Índigo claro */
        border-left-width: 4px;
        border-left-color: #4f46e5;
    }
    .active-accordion span {
        font-weight: bold;
    }

    /* --- NUEVO ESTILO PARA LA FACULTAD con hijos abiertos --- */
    .active-parent-accordion {
        background-color: #f5f3ff; /* Violeta muy, muy claro */
    }
        
    /* Estilo para el contenedor de la FACULTAD que tiene pendientes */
    .has-pending-fac {
        background-color: #fefce8; /* Un amarillo muy suave (Color de Tailwind: yellow-50) */
        border-left: 4px solid #facc15; /* Borde amarillo (yellow-400) */
    }

    /* Estilo para el botón del DEPARTAMENTO que tiene pendientes */
    .has-pending-depto {
        background-color: #fef2f2; /* Un rojo/rosa muy suave (red-50) */
    }
    .has-pending-depto span {
         font-weight: 600; /* semi-bold */
         color: #b91c1c; /* red-700 */
    }
    </style>
</head>
<body class="bg-gray-100">

    <div class="container mx-auto p-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Novedades Aprobadas por Facultad (<?php echo htmlspecialchars($anio_semestre); ?>)</h1>
            <div class="mb-6 bg-white p-4 rounded-lg shadow-sm border border-gray-200">
                <div class="flex items-center space-x-6">
                    <span class="font-semibold text-gray-700">Filtrar por estado:</span>
                    <div class="flex items-center">
                        <input type="radio" id="filtro_todos" name="filtro_estado" value="todos" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                        <label for="filtro_todos" class="ml-2 block text-sm text-gray-900">
                            Mostrar Todos
                        </label>
                    </div>
                    <div class="flex items-center">
                        <input type="radio" id="filtro_pendientes" name="filtro_estado" value="pendientes" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                        <label for="filtro_pendientes" class="ml-2 block text-sm text-gray-900">
                            Mostrar solo con trámites pendientes en VRA
                        </label>
                    </div>
                </div>
            </div>
        <div class="space-y-4" id="lista-facultades">
            <?php if (!empty($datos_agrupados_vra)): ?>
                <?php foreach ($datos_agrupados_vra as $nombre_facultad => $departamentos): ?>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden" data-facultad-container="<?= htmlspecialchars($nombre_facultad) ?>">
                        <button class="accordion-facultad w-full text-left py-3 px-6 flex justify-between items-center hover:bg-gray-50 focus:outline-none">
                            <span class="text-2xl font-semibold text-gray-800"><?= htmlspecialchars($nombre_facultad) ?></span>
                            <svg class="w-6 h-6 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div class="accordion-body-facultad hidden p-2 md:p-6 bg-gray-50 border-t border-gray-200">
                            <div class="space-y-4">
                                <?php foreach ($departamentos as $nombre_departamento => $oficios): ?>
                                    <div class="bg-white rounded-lg shadow-sm overflow-hidden" data-depto-container="<?= htmlspecialchars($nombre_departamento) ?>">
                                        <button 
                                            class="accordion-departamento w-full text-left py-3 px-6 flex justify-between items-center hover:bg-gray-50 focus:outline-none" 
                                            data-facultad="<?= htmlspecialchars($nombre_facultad) ?>" 
                                            data-depto="<?= htmlspecialchars($nombre_departamento) ?>">
                                            <span class="text-xl font-semibold text-gray-800"><?= htmlspecialchars($nombre_departamento) ?></span>
                                            <svg class="w-5 h-5 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                        </button>
                                        <div class="accordion-body-departamento hidden p-2 bg-gray-100 border-t border-gray-200">
                                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 oficios-container">
                                                </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-gray-500 text-center p-10">No hay novedades aprobadas por las facultades para este periodo.</p>
            <?php endif; ?>
        </div>
    </div>

    <div id="detailsModal" class="fixed inset-0 bg-gray-800 bg-opacity-75 h-full w-full hidden z-50">
        <div class="relative top-40 mx-auto p-5 border w-11/12 lg-w-4/5 shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center pb-3 border-b">
                <h3 class="text-2xl font-semibold" id="modalTitle"></h3>
                <button id="closeModalBtn" class="text-black text-3xl hover:text-gray-600">&times;</button>
            </div>

            <div class="mt-3 overflow-y-auto" style="max-height: 65vh;">
             <table class="min-w-full divide-y divide-y-gray-200">
                <thead class="bg-gray-50">
    <tr>
        <th class="px-4 py-2 text-left">
            <input type="checkbox" id="selectAllCheckbox">
        </th>
        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Oficio Depto.</th>
        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Novedad</th>
        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Justificación Depto.</th>
        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Nombre</th>
        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Cédula</th>
        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Tipo</th>
        <th colspan="2" class="px-6 py-2 text-center text-xs font-medium text-gray-500 uppercase">Dedicación</th>
        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Estado Facultad</th>
        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Estado VRA</th>
        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Observación VRA</th>
    </tr>
    <tr>
        <th></th>
        <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase bg-gray-100">Popayán</th>
        <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase bg-gray-100">Regionalización</th>
    </tr>
</thead>
                <tbody id="modalTableBody" class="bg-white divide-y divide-gray-200"></tbody>
            </table>
            </div>

            <div id="modalActionFooter" class="mt-4 pt-4 border-t">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
                 
                    <div class="flex items-end space-x-3">
                        <button id="aprobarVraBtn" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-green-600 text-base font-medium text-white hover:bg-green-700 focus:outline-none">
                            <i class="fas fa-check mr-2"></i> Aprobar
                        </button>
                        <button id="rechazarVraBtn" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none">
                            <i class="fas fa-times mr-2"></i> Rechazar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>


   <script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- 1. VARIABLES GLOBALES Y ELEMENTOS DEL DOM ---
        const estructuraVRA = <?php echo $estructura_vra_json; ?>;
        const todasLasSolicitudes = <?php echo $solicitudes_json; ?>;
        const modal = document.getElementById('detailsModal');
        const modalTitle = document.getElementById('modalTitle');
        const modalTableBody = document.getElementById('modalTableBody');
        const selectAllCheckbox = document.getElementById('selectAllCheckbox');

        
         // =============================================================
            // ===== INICIA NUEVA LÓGICA PARA EL FILTRO =====
            // =============================================================
            const radiosFiltro = document.querySelectorAll('input[name="filtro_estado"]');

            function tienePendientesVRA(oficios) {
                for (const nombreOficio in oficios) {
                    if (oficios[nombreOficio].some(sol => sol.estado_vra === 'PENDIENTE')) {
                        return true; // Si encuentra al menos uno, retorna verdadero
                    }
                }
                return false; // Si recorre todo y no encuentra, retorna falso
            }
        // ======================================================================
    // ===== INICIA NUEVA FUNCIÓN PARA PINTAR FONDOS DE PENDIENTES =====
    // ======================================================================
    function actualizarEstilosPendientes() {
        const facultadesContainers = document.querySelectorAll('[data-facultad-container]');

        facultadesContainers.forEach(facContainer => {
            const nombreFacultad = facContainer.dataset.facultadContainer;
            let facultadTienePendientes = false;

            const deptosContainers = facContainer.querySelectorAll('[data-depto-container]');
            
            deptosContainers.forEach(deptoContainer => {
                const nombreDepto = deptoContainer.dataset.deptoContainer;
                const oficios = estructuraVRA[nombreFacultad]?.[nombreDepto] || {};

                // Buscamos el botón dentro del contenedor para aplicarle el estilo
                const deptoBoton = deptoContainer.querySelector('.accordion-departamento');
                
                if (tienePendientesVRA(oficios)) {
                    facultadTienePendientes = true;
                    if (deptoBoton) deptoBoton.classList.add('has-pending-depto');
                } else {
                    if (deptoBoton) deptoBoton.classList.remove('has-pending-depto');
                }
            });

            // Aplicamos o quitamos la clase a la facultad entera
            if (facultadTienePendientes) {
                facContainer.classList.add('has-pending-fac');
            } else {
                facContainer.classList.remove('has-pending-fac');
            }
        });
    }
    // ======================================================================
    // ===== FIN DE LA NUEVA FUNCIÓN =====
    // ======================================================================


            function aplicarFiltro() {
                const filtroSeleccionado = document.querySelector('input[name="filtro_estado"]:checked').value;
                const facultadesContainers = document.querySelectorAll('[data-facultad-container]');

                facultadesContainers.forEach(facContainer => {
                    const nombreFacultad = facContainer.dataset.facultadContainer;
                    let facultadTieneDeptosVisibles = false;

                    const deptosContainers = facContainer.querySelectorAll('[data-depto-container]');

                    deptosContainers.forEach(deptoContainer => {
                        if (filtroSeleccionado === 'pendientes') {
                            const nombreDepto = deptoContainer.dataset.deptoContainer;
                            const oficios = estructuraVRA[nombreFacultad]?.[nombreDepto] || {};

                            if (tienePendientesVRA(oficios)) {
                                deptoContainer.style.display = 'block';
                                facultadTieneDeptosVisibles = true;
                            } else {
                                deptoContainer.style.display = 'none';
                            }
                        } else {
                            deptoContainer.style.display = 'block';
                            facultadTieneDeptosVisibles = true;
                        }
                    });

                    // Finalmente, ocultamos la facultad entera si no tiene departamentos visibles
                    if (facultadTieneDeptosVisibles) {
                        facContainer.style.display = 'block';
                    } else {
                        facContainer.style.display = 'none';
                    }
                });
            }

            // Añadimos el evento a los botones de radio para que ejecuten el filtro
            radiosFiltro.forEach(radio => {
                radio.addEventListener('change', aplicarFiltro);
            });
            // =============================================================
            // ===== FIN DE LA LÓGICA PARA EL FILTRO =====
            // =============================================================

        // --- 2. FUNCIONES AUXILIARES ---
        
        function crearEtiquetaEstado(estado, observacion, tipo = 'facultad') {
            let texto = estado || 'PENDIENTE';
            let clasesColor = 'bg-yellow-100 text-yellow-800';
            let tooltip = observacion ? `title="${observacion}"` : '';
            if (tipo === 'facultad') {
                if (estado === 'APROBADO') { texto = 'AVALADO'; clasesColor = 'bg-green-100 text-green-800'; }
                else if (estado === 'RECHAZADO') { texto = 'NO AVALADO'; clasesColor = 'bg-red-100 text-red-800'; }
            } else {
                if (estado === 'APROBADO') { clasesColor = 'bg-green-100 text-green-800'; }
                else if (estado === 'RECHAZADO') { clasesColor = 'bg-red-100 text-red-800'; }
            }
            return `<span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${clasesColor}" ${tooltip}>${texto}</span>`;
        }

    function llenarModalVRA(oficio_fac) {
    modalTitle.textContent = 'Solicitudes del Oficio de facultad # ' + oficio_fac;
    modalTableBody.innerHTML = '';
    selectAllCheckbox.checked = false;
    const solicitudesFiltradas = todasLasSolicitudes.filter(sol => sol.oficio_con_fecha_fac === oficio_fac);

    if (solicitudesFiltradas.length > 0) {
        solicitudesFiltradas.forEach(sol => {
            let popayanData = '<span class="text-gray-400">N/A</span>';
            let regionalizacionData = '<span class="text-gray-400">N/A</span>';
            if (sol.tipo_docente === 'Ocasional') {
                if (sol.tipo_dedicacion) popayanData = `<span class="bg-gray-200 px-2 py-1 rounded">${sol.tipo_dedicacion}</span>`;
                if (sol.tipo_dedicacion_r) regionalizacionData = `<span class="bg-gray-200 px-2 py-1 rounded">${sol.tipo_dedicacion_r}</span>`;
            } else if (sol.tipo_docente === 'Catedra') {
                if (sol.horas && sol.horas > 0) popayanData = `<span class="bg-blue-100 px-2 py-1 rounded">${sol.horas} hrs</span>`;
                if (sol.horas_r && sol.horas_r > 0) regionalizacionData = `<span class="bg-blue-100 px-2 py-1 rounded">${sol.horas_r} hrs</span>`;
            }
            const tipoDocenteDisplay = (sol.tipo_docente === 'Catedra') ? 'Cátedra' : sol.tipo_docente;
            const estadoFacultadHtml = crearEtiquetaEstado(sol.estado_facultad, sol.observacion_facultad, 'facultad');
            const estadoVraHtml = crearEtiquetaEstado(sol.estado_vra, sol.observacion_vra, 'vra');
            const observacionGuardada = sol.observacion_vra || '';

            // ESTA ES LA ESTRUCTURA CORRECTA CON 12 CELDAS (<td>)
            const filaHTML = `<tr class="${sol.estado_vra !== 'PENDIENTE' ? 'bg-gray-200 opacity-60' : ''}">
                <td class="px-4 py-2">
                    ${sol.estado_vra === 'PENDIENTE' ? `<input type="checkbox" class="solicitud-checkbox" value="${sol.solicitud_id}">` : ''}
                </td>
                <td class="px-6 py-2 whitespace-nowrap text-sm text-gray-600">${sol.oficio_con_fecha || ''}</td>
                <td class="px-6 py-2 whitespace-nowrap">${sol.novedad || ''}</td>
                <td class="px-6 py-2 whitespace-normal max-w-xs break-words text-gray-700">${sol.s_observacion || ''}</td>
                <td class="px-6 py-2 whitespace-nowrap text-gray-700">${sol.nombre}</td>
                <td class="px-6 py-2 whitespace-nowrap text-gray-700">${sol.cedula}</td>
                <td class="px-6 py-2 whitespace-nowrap text-gray-700">${tipoDocenteDisplay}</td>
                <td class="px-6 py-2 whitespace-nowrap text-gray-700">${popayanData}</td>
                <td class="px-6 py-2 whitespace-nowrap text-gray-700">${regionalizacionData}</td>
                <td class="px-6 py-2 whitespace-nowrap text-gray-700">${estadoFacultadHtml}</td>
                <td class="px-6 py-2 whitespace-nowrap text-gray-700">${estadoVraHtml}</td>
                <td class="px-6 py-2 whitespace-nowrap">
                    ${sol.estado_vra === 'PENDIENTE' 
                        ? `<input type="text" class="observacion-vra-input w-full border-gray-300 rounded-md shadow-sm text-sm" data-id="${sol.solicitud_id}" value="${observacionGuardada}" placeholder="Justificación (si rechaza)...">` 
                        : `<span class="text-sm text-gray-600">${observacionGuardada}</span>`
                    }
                </td>
            </tr>`;
            modalTableBody.innerHTML += filaHTML;
        });
    } else {
        // El colspan ahora debe ser 13 para coincidir con el número total de columnas
        modalTableBody.innerHTML = `<tr><td colspan="13" class="text-center py-4">No se encontraron solicitudes para este oficio.</td></tr>`;
    }
    modal.classList.remove('hidden');
}
        function procesarSeleccion(accion) {
             const checkboxes = modalTableBody.querySelectorAll('.solicitud-checkbox:checked');
    
    // Ahora creamos un array de objetos, cada uno con id y observacion
    let solicitudesData = [];
    let observacionFaltante = false;

    checkboxes.forEach(cb => {
        const id = cb.value;
        const observacionInput = modalTableBody.querySelector(`.observacion-vra-input[data-id="${id}"]`);
        const observacion = observacionInput ? observacionInput.value.trim() : '';

        // Si la acción es rechazar y la observación está vacía, marcamos un error
        if (accion === 'rechazar' && observacion === '') {
            observacionFaltante = true;
            // Opcional: Resaltar el campo vacío
            if(observacionInput) observacionInput.style.borderColor = 'red';
        }
        
        solicitudesData.push({ id: id, observacion: observacion });
    });

    if (solicitudesData.length === 0) {
        alert('Por favor, seleccione al menos una solicitud para procesar.');
        return;
    }

    if (observacionFaltante) {
        alert('La observación es obligatoria para cada solicitud que se va a rechazar.');
        return;
    }

    if (!confirm(`¿Está seguro de que desea ${accion} ${solicitudesData.length} solicitud(es)?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('accion', accion);
    formData.append('anio_semestre', '<?php echo $anio_semestre; ?>');
    // Convertimos el array de objetos a un string JSON para enviarlo
    formData.append('solicitudes', JSON.stringify(solicitudesData)); 

    fetch('procesar_aprobacion_vra.php', {
        method: 'POST',
        body: formData
    })
            .then(response => {
                // NUEVO: Añadimos una verificación para ver si la respuesta del servidor es válida
                if (!response.ok) {
                    // Si el servidor responde con un error (ej. 404 No Encontrado, 500 Error del Servidor)
                    throw new Error(`Error del servidor: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    alert('Las solicitudes han sido procesadas exitosamente.');
                    location.reload();
                } else {
                    alert('Ocurrió un error al procesar las solicitudes: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error en la petición fetch:', error);
                // Mensaje más detallado para nosotros en la consola del navegador
                alert('Ocurrió un error de conexión. Verifique la consola (F12) para más detalles. Asegúrese de que el archivo procesar_aprobacion_vra.php existe en la ubicación correcta.');
            });
        }

        // --- 3. ASIGNACIÓN DE EVENTOS (EVENT LISTENERS) ---
        
        // Eventos para cerrar el modal
        document.getElementById('closeModalBtn').addEventListener('click', () => modal.classList.add('hidden'));
        modal.addEventListener('click', (event) => { if (event.target === modal) modal.classList.add('hidden'); });

        // Eventos para los botones de acción del modal
        document.getElementById('aprobarVraBtn').addEventListener('click', () => procesarSeleccion('aprobar'));
        document.getElementById('rechazarVraBtn').addEventListener('click', () => procesarSeleccion('rechazar'));
        
        // Evento para el checkbox "Seleccionar todos"
        selectAllCheckbox.addEventListener('change', (e) => {
            const checkboxes = modalTableBody.querySelectorAll('.solicitud-checkbox');
            checkboxes.forEach(checkbox => { checkbox.checked = e.target.checked; });
        });

        // Evento principal delegado para los acordeones y botones "Ver Solicitudes"
       // Evento principal delegado para los acordeones y botones "Ver Solicitudes"
const listaFacultades = document.getElementById('lista-facultades');
if (listaFacultades) {
    listaFacultades.addEventListener('click', function(event) {
    
        // Manejador para el Acordeón de Facultad (Nivel 1)
        const headerFacultad = event.target.closest('.accordion-facultad');
        if (headerFacultad) {
            document.querySelectorAll('.accordion-facultad').forEach(otherHeader => {
                if (otherHeader !== headerFacultad) {
                    otherHeader.nextElementSibling.classList.add('hidden');
                    otherHeader.querySelector('svg').classList.remove('rotate-180');
                    // NUEVO: Asegurarse de quitar los estilos de padre activo a las otras facultades
                    otherHeader.classList.remove('active-parent-accordion');
                }
            });

            const bodyFacultad = headerFacultad.nextElementSibling;
            const iconFacultad = headerFacultad.querySelector('svg');
            bodyFacultad.classList.toggle('hidden');
            iconFacultad.classList.toggle('rotate-180');
            
            // NUEVO: Si estamos CERRANDO la facultad, reseteamos todos sus hijos
            if (bodyFacultad.classList.contains('hidden')) {
                headerFacultad.classList.remove('active-parent-accordion');
                bodyFacultad.querySelectorAll('.accordion-departamento').forEach(deptoHeader => {
                    deptoHeader.classList.remove('active-accordion');
                    deptoHeader.nextElementSibling.classList.add('hidden');
                    deptoHeader.querySelector('svg').classList.remove('rotate-180');
                });
            }
        }

        // Manejador para el Acordeón de Departamento (Nivel 2)
        const headerDepto = event.target.closest('.accordion-departamento');
        if (headerDepto) {
            const parentFacultadBody = headerDepto.closest('.accordion-body-facultad');
            if (parentFacultadBody) {
                parentFacultadBody.querySelectorAll('.accordion-departamento').forEach(otherHeader => {
                    if (otherHeader !== headerDepto) {
                        otherHeader.nextElementSibling.classList.add('hidden');
                        otherHeader.querySelector('svg').classList.remove('rotate-180');
                        otherHeader.classList.remove('active-accordion');

                    }
                });
            }
            
            const bodyDepto = headerDepto.nextElementSibling;
            const iconDepto = headerDepto.querySelector('svg');
            bodyDepto.classList.toggle('hidden');
            iconDepto.classList.toggle('rotate-180');
            headerDepto.classList.toggle('active-accordion');
            
            // --- NUEVA LÓGICA PARA ACTUALIZAR EL PADRE ---
            const parentHeaderFacultad = parentFacultadBody.previousElementSibling;
            const tieneHijosActivos = parentFacultadBody.querySelector('.active-accordion');

            if (tieneHijosActivos) {
                parentHeaderFacultad.classList.add('active-parent-accordion');
            } else {
                parentHeaderFacultad.classList.remove('active-parent-accordion');
            }
            // --- FIN DE LA NUEVA LÓGICA ---

            if (!bodyDepto.classList.contains('hidden') && bodyDepto.querySelector('.oficios-container').innerHTML.trim() === '') {
                // ... (El código para cargar las tarjetas de oficios no cambia)
                const deptoName = headerDepto.dataset.depto;
                const facultadName = headerDepto.dataset.facultad;
                const cardsContainer = bodyDepto.querySelector('.oficios-container');
                const oficios = estructuraVRA[facultadName]?.[deptoName] || {};
               // ===================================================================
// ===== INICIA BLOQUE DE CÓDIGO REEMPLAZADO =====
// ===================================================================
for (const oficio_fac in oficios) {
    const solicitudesDelOficio = oficios[oficio_fac];
    const totalSolicitudes = solicitudesDelOficio.length;

    // 1. Contamos cuántas solicitudes hay en cada estado
    const pendientesCount = solicitudesDelOficio.filter(sol => sol.estado_vra === 'PENDIENTE').length;
    const rechazadosCount = solicitudesDelOficio.filter(sol => sol.estado_vra === 'RECHAZADO').length;
    const aprobadosCount = solicitudesDelOficio.filter(sol => sol.estado_vra === 'APROBADO').length;

    let estadoVRA = '';
    let colorVRA = '';
    let iconVRA = '';

    // 2. Aplicamos la nueva lógica de estados y colores
    if (pendientesCount > 0) {
        // Si hay CUALQUIER pendiente, el estado es "En Proceso"
        estadoVRA = 'En Proceso';
        colorVRA = 'bg-orange-100 text-orange-800'; // Naranja
        iconVRA = '<i class="fas fa-hourglass-half"></i>';
    } else {
        // Si no hay pendientes, está finalizado. Ahora determinamos CÓMO finalizó.
        if (rechazadosCount > 0 && rechazadosCount === totalSolicitudes) {
            // Caso 1: TODOS los registros fueron rechazados
            estadoVRA = 'Finalizado (Rechazado)';
            colorVRA = 'bg-red-100 text-red-800'; // Rojo
            iconVRA = '<i class="fas fa-times-circle"></i>';
        } else if (rechazadosCount > 0) {
            // Caso 2: Hay AL MENOS UN rechazado (y el resto aprobados)
            estadoVRA = 'Finalizado (Mixto)';
            colorVRA = 'bg-blue-100 text-blue-800'; // Azul
            iconVRA = '<i class="fas fa-check-double"></i>';
        } else {
            // Caso 3: No hay pendientes y no hay rechazados, por lo tanto, TODOS están aprobados
            estadoVRA = 'Finalizado (Aprobado)';
            colorVRA = 'bg-green-100 text-green-800'; // Verde (como estaba antes)
            iconVRA = '<i class="fas fa-check-circle"></i>';
        }
    }
const { codigo, fechaFormateada } = dividirOficio(oficio_fac);
    // 3. Creamos el HTML de la tarjeta con los nuevos valores dinámicos
    const cardHtml = `<div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-blue-500 flex flex-col justify-between">
        <div>
            <div class="flex justify-between items-start mb-2">
                <h3 class="text-xs font-semibold text-gray-500 uppercase">Oficio Facultad</h3>
                <span title="Estado en VRA: ${estadoVRA}" class="px-2 py-0.5 text-xs font-bold rounded-full flex items-center space-x-1 ${colorVRA}">
                    ${iconVRA} <span>${estadoVRA}</span>
                </span>
            </div>
            <p class="text-md text-gray-800 my-2 truncate" title="${oficio_fac}">
    <strong>${codigo}</strong> <span class="font-normal">${fechaFormateada}</span>
</p>
        </div>
        <button data-oficio-fac="${oficio_fac}" class="ver-detalles-btn-vra w-full mt-3 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-3 rounded-md text-sm">
            Ver Solicitudes
        </button>
    </div>`;
    cardsContainer.innerHTML += cardHtml;
}
                
                function dividirOficio(oficio) {
    const partes = oficio.split(" ");
    if (partes.length < 2) return { codigo: oficio, fechaFormateada: "" };

    const codigo = partes[0];
    const fecha = partes[1];

    const [anio, mes, dia] = fecha.split("-");
    const meses = {
        "01": "ene.", "02": "feb.", "03": "mar.", "04": "abr.",
        "05": "may.", "06": "jun.", "07": "jul.", "08": "ago.",
        "09": "sept.", "10": "oct.", "11": "nov.", "12": "dic."
    };

    const mesEsp = meses[mes] || mes;
    const fechaFormateada = `(${parseInt(dia)} de ${mesEsp} de ${anio})`;

    return { codigo, fechaFormateada };
}

// ===================================================================
// ===== FIN DEL BLOQUE DE CÓDIGO REEMPLAZADO =====
// ===================================================================
                if (Object.keys(oficios).length === 0) {
                    cardsContainer.innerHTML = '<p class="col-span-full text-gray-500">No hay oficios de facultad para este departamento.</p>';
                }
            }
        }

        // Manejador para el botón "Ver Solicitudes" que abre el modal
        const verDetallesBtn = event.target.closest('.ver-detalles-btn-vra');
        if (verDetallesBtn) {
            llenarModalVRA(verDetallesBtn.dataset.oficioFac);
        }
    });
}
          actualizarEstilosPendientes(); 
    }); // CORRECCIÓN: Se eliminó un }); extra y se reorganizó la estructura.
       
       
</script>
    
<script>
document.addEventListener('DOMContentLoaded', () => {
    // =================================================================
    // PARTE 1: INICIALIZACIÓN Y DEPURACIÓN DE DATOS (LA CLAVE)
    // =================================================================
    const statusesVRA = <?php echo $statuses_json; ?>;

    // --- Inicio del Bloque de Depuración ---
    console.log("--- INICIO DEPURACIÓN DEL FILTRO ---");
    console.log("1. El objeto de estados (statusesVRA) que se recibió de PHP es:");
    console.log(statusesVRA);
    console.log("2. Las claves (nombres de facultad) disponibles en el objeto son:");
    console.log(Object.keys(statusesVRA));
    console.log("--- FIN DEPURACIÓN ---");
    // --- Fin del Bloque de Depuración ---


    // ==================================================
    // PARTE 2: LÓGICA DEL ACORDEÓN (SIN CAMBIOS)
    // ==================================================
    document.querySelectorAll('.accordion-header').forEach(header => {
        header.addEventListener('click', () => {
            const body = header.nextElementSibling;
            const icon = header.querySelector('svg');
            body.classList.toggle('hidden');
            icon.classList.toggle('rotate-180');
        });
    });

    // ==================================================
    // PARTE 3: LÓGICA DEL FILTRO (CON DEPURACIÓN ADICIONAL)
    // ==================================================
    const filtroRadios = document.querySelectorAll('input[name="filtro_estado"]');
    const mensajeNoPendientes = document.getElementById('mensaje-no-pendientes');

    function aplicarFiltroGlobal() {
        const filtroSeleccionado = document.querySelector('input[name="filtro_estado"]:checked').value;
        const todasLasFacultades = document.querySelectorAll('#lista-facultades > .bg-white.rounded-lg');
        let facultadesVisibles = 0;

        console.log(`\n--- Aplicando filtro: '${filtroSeleccionado}' ---`);

        todasLasFacultades.forEach(contenedor => {
            const header = contenedor.querySelector('.accordion-header');
            const facName = header.querySelector('span').textContent.trim();
            
            // Depuración: mostramos qué nombre estamos buscando
            console.log(`Buscando estados para la facultad: "'${facName}'"`);

            const statusesFacultad = statusesVRA[facName];
            let tienePendientes = false;
            
            // Depuración: verificamos si encontramos la facultad en el objeto
            if (statusesFacultad) {
                console.log(" -> ¡Encontrada! Revisando sus oficios...");
                for (const oficio in statusesFacultad) {
                    if (statusesFacultad[oficio].vra === 'En Proceso VRA') {
                        tienePendientes = true;
                        break;
                    }
                }
            } else {
                console.log(" -> X No encontrada en el objeto de estados.");
            }

            if (filtroSeleccionado === 'pendientes' && !tienePendientes) {
                contenedor.style.display = 'none';
            } else {
                contenedor.style.display = 'block';
                facultadesVisibles++;
            }
        });

        if (filtroSeleccionado === 'pendientes' && facultadesVisibles === 0) {
            if (mensajeNoPendientes) mensajeNoPendientes.classList.remove('hidden');
        } else {
            if (mensajeNoPendientes) mensajeNoPendientes.classList.add('hidden');
        }
    }

    filtroRadios.forEach(radio => {
        radio.addEventListener('change', aplicarFiltroGlobal);
    });
});
</script>
</body>
</html>