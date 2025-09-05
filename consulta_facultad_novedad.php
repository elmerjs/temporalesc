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
    </style>
</head>
<body class="bg-gray-100">

    <div class="container mx-auto p-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Novedades Aprobadas por Facultad (<?php echo htmlspecialchars($anio_semestre); ?>)</h1>

        <div class="space-y-4" id="lista-facultades">
            <?php if (!empty($datos_agrupados_vra)): ?>
                <?php foreach ($datos_agrupados_vra as $nombre_facultad => $departamentos): ?>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <button class="accordion-facultad w-full text-left py-3 px-6 flex justify-between items-center hover:bg-gray-50 focus:outline-none">
                            <span class="text-2xl font-semibold text-gray-800"><?= htmlspecialchars($nombre_facultad) ?></span>
                            <svg class="w-6 h-6 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div class="accordion-body-facultad hidden p-2 md:p-6 bg-gray-50 border-t border-gray-200">
                            <div class="space-y-4">
                                <?php foreach ($departamentos as $nombre_departamento => $oficios): ?>
                                    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
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
                        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Justificación</th>
                        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Nombre</th>
                        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Cédula</th>
                        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Tipo</th>
                        <th colspan="2" class="px-6 py-2 text-center text-xs font-medium text-gray-500 uppercase">Dedicación</th>
                        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Estado Facultad</th>
                        <th rowspan="2" class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase align-middle">Estado VRA</th>
                    </tr>
                    <tr>
                        <th></th> <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase bg-gray-100">Popayán</th>
                        <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase bg-gray-100">Regionalización</th>
                    </tr>
                </thead>
                <tbody id="modalTableBody" class="bg-white divide-y divide-gray-200"></tbody>
            </table>
            </div>

            <div id="modalActionFooter" class="mt-4 pt-4 border-t">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
                    <div class="md:col-span-2">
                        <label for="observacionVraTextarea" class="block text-sm font-medium text-gray-700 mb-1">Observación / Justificación (Opcional para aprobar, requerida para rechazar)</label>
                        <textarea id="observacionVraTextarea" rows="2" class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md" placeholder="Escriba aquí la justificación..."></textarea>
                    </div>
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
            modalTitle.textContent = 'Solicitudes del Oficio: ' + oficio_fac;
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
                    </tr>`;
                    modalTableBody.innerHTML += filaHTML;
                });
            } else {
                modalTableBody.innerHTML = `<tr><td colspan="10" class="text-center py-4">No se encontraron solicitudes para este oficio.</td></tr>`;
            }
            modal.classList.remove('hidden');
        }

        function procesarSeleccion(accion) {
            const checkboxes = modalTableBody.querySelectorAll('.solicitud-checkbox:checked');
            const ids = Array.from(checkboxes).map(cb => cb.value);
            const observacion = document.getElementById('observacionVraTextarea').value;

            if (ids.length === 0) {
                alert('Por favor, seleccione al menos una solicitud para procesar.');
                return;
            }
            if (accion === 'rechazar' && !observacion.trim()) {
                alert('La observación es obligatoria para rechazar solicitudes.');
                return;
            }
            if (!confirm(`¿Está seguro de que desea ${accion} ${ids.length} solicitud(es)?`)) {
                return;
            }
            
            // CORRECCIÓN: Una sola declaración de formData, sin duplicados
            const formData = new FormData();
            formData.append('accion', accion);
            formData.append('observacion', observacion);
            formData.append('ids', ids.join(',')); 

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
                for (const oficio_fac in oficios) {
                    let tienePendientesVRA = oficios[oficio_fac].some(sol => sol.estado_vra === 'PENDIENTE');
                    const estadoVRA = tienePendientesVRA ? 'En Proceso' : 'Finalizado';
                    const colorVRA = estadoVRA === 'En Proceso' ? 'bg-orange-100 text-orange-800' : 'bg-green-100 text-green-800';
                    const iconVRA = estadoVRA === 'En Proceso' ? '<i class="fas fa-hourglass-half"></i>' : '<i class="fas fa-check-circle"></i>';
                    const cardHtml = `<div class="bg-white rounded-lg shadow-md p-4 border-l-4 border-blue-500 flex flex-col justify-between"><div><div class="flex justify-between items-start mb-2"><h3 class="text-xs font-semibold text-gray-500 uppercase">Oficio Facultad</h3><span title="Estado en VRA: ${estadoVRA}" class="px-2 py-0.5 text-xs font-bold rounded-full flex items-center space-x-1 ${colorVRA}">${iconVRA} <span>${estadoVRA}</span></span></div><p class="text-md font-bold text-gray-800 my-2 truncate" title="${oficio_fac}">${oficio_fac}</p></div><button data-oficio-fac="${oficio_fac}" class="ver-detalles-btn-vra w-full mt-3 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-3 rounded-md text-sm">Ver Solicitudes</button></div>`;
                    cardsContainer.innerHTML += cardHtml;
                }
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
    }); // CORRECCIÓN: Se eliminó un }); extra y se reorganizó la estructura.
</script>
</body>
</html>