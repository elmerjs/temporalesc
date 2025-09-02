<?php
session_start();

// Connection info. file
include 'conn.php';    

// Connection variables
$conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbname);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$sesionok = 0;
$error_msg = "";

// Verificar si se ha enviado informaci�n del formulario
if (isset($_POST['email']) && isset($_POST['password'])) {
    $email = $_POST['email']; 
    $password = $_POST['password'];

    // Query sent to database
    $result = mysqli_query($conn, "SELECT * FROM users WHERE Email = '$email'");

    // Verificar si se encontr� alg�n resultado
    if (mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $hash = $row['Password'];

        // Verificar la contrase�a utilizando password_verify()
        if (password_verify($password, $hash)) {
            $sesionok = 1;
            // Establecer variables de sesi�n
            $_SESSION['loggedin'] = true;
            $_SESSION['id_user'] = $row['Id']; // ? Aseg�rate que 'id' existe en tu tabla users

            $_SESSION['name'] = $row['Name'];
                        $_SESSION['fk_fac_user'] = $row['fk_fac_user'];

            $_SESSION['docusuario'] = $row['DocUsuario'];
            $_SESSION['start'] = time();
            $_SESSION['expire'] = $_SESSION['start'] + (5 * 3600); // 5 horas de sesi�n
            $email_fac = $row['email_padre'];
            $tipo_usuario = $row['tipo_usuario'];
            $depto_user = $row['fk_depto_user'];
            $where = "";

            
        if (is_null($tipo_usuario)) {
             $where = "";
  echo "<div class='alert alert-warning mt-4' role='alert'>
        <div class='d-flex align-items-center'>
            <i class='fas fa-exclamation-triangle fa-2x mr-3'></i>
            <div>
                <h4 class='alert-heading'>Usuario pendiente de activaci�n</h4>
                <p>Por favor, contacta al administrador para activar tu cuenta en <a href='mailto:viceacad@unicauca.edu.co'>viceacad@unicauca.edu.co</a>.</p>
                <p><a href='/temporalesc/index.html' class='btn btn-primary'>Regresar al Inicio</a></p>
            </div>
        </div>
    </div>";
    exit; // Detener la ejecuci�n del script
} else {    
            
            if ($tipo_usuario == 3) {
                $where = "WHERE email_fac LIKE '%$email_fac%' and PK_DEPTO = '$depto_user'";
            } else if ($tipo_usuario == 2) {
                $where = "WHERE email_fac LIKE '%$email_fac%'";
            }
            
            $con = new mysqli('localhost', 'root', '', 'contratacion_temporales_b');
            if ($con->connect_error) {
                die("Conexi�n fallida: " . $con->connect_error);
            }
            
            if ($tipo_usuario != 1) {
                $result = $con->query("SELECT PK_FAC, nombre_fac_minb, deparmanentos.depto_nom_propio, deparmanentos.PK_DEPTO
                                       FROM facultad, deparmanentos 
                                       $where
                                       AND deparmanentos.FK_FAC = facultad.PK_FAC");
            } else {
                $result = $con->query("SELECT PK_FAC, nombre_fac_minb, deparmanentos.depto_nom_propio, deparmanentos.PK_DEPTO 
                                       FROM facultad, deparmanentos 
                                       WHERE deparmanentos.FK_FAC = facultad.PK_FAC");
            }

            $departamentos = [];
            while ($row = $result->fetch_assoc()) {
                $departamentos[] = $row;
            }
            foreach ($departamentos as $departamento) {
                $facultad_id = $departamento['PK_FAC']; 
                $departamento_id = $departamento['PK_DEPTO']; 
                $anio_semestre = "2025-1";
                $departamento['depto_nom_propio'];
            }

            $con->close();
            
            // Redireccionar a otra p�gina o mostrar contenido
            header('Location: /temporalesc/menu_inicio.php');
            exit;
        } 
        
           
        }
        
         
        else {
            // Mensaje de error si la contrase�a no coincide
           $error_msg = "<div class='alert alert-danger mt-4' role='alert'>
    <h4>Invalid email or password!</h4>
    <p>Please <a href='/temporalesc/index.html' style='color: #00008B; font-weight: bold;'>Login Here</a> again.</p>
</div>";
        }
    } else {
        // Mensaje de error si el usuario no se encuentra en la base de datos
        $error_msg = "<div class='alert alert-danger mt-4' role='alert'>
    <h4>Invalid email or password!</h4>
    <p>Please <a href='/temporalesc/index.html' style='color: #00008B; font-weight: bold;'>Login Here</a> again.</p>
</div>";
        
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Chequeo login y creaci�n de sesi�n</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0-beta.2/css/bootstrap.min.css" integrity="sha384-PsH8R72JQ3SOdhVi3uxftmaW6Vc51MKb0q5P2rRUpPvrszuE4W1povHYgTpBfshb" crossorigin="anonymous">
    <h1>Solicitud Profesores Temporales Unicauca</h1>
        <div id="login">
            <?php
            if ($sesionok == 1) {  
                echo "<i>" . $_SESSION['name'] . "</i> <i class='fas fa-sign-out-alt' style='font-size:12px;color:#245D96'></i><a href='../../temporalesc/logout.php'>Logout</a>";
            } else {
                echo $error_msg; // Muestra el mensaje de error si no hay sesi�n activa
            }
            ?>
        </div>
    <style>
        /* Estilos del contenido */
        #contenido {
            margin-left: 220px; /* Aumentar el margen para dejar espacio al men� */
            padding: 20px;
            z-index: 1; /* Asegurar que est� adelante del men� */
        }

        /* Estilos del encabezado */
        header {
            background: linear-gradient(#003366, #001f33); /* Azul oscuro institucional */
            width: 100%;
            height: 40px; /* Altura del encabezado reducida */
            position: fixed;
            top: 0;
            left: 0;
            z-index: 100;
            color: white;
            display: flex; /* Alinear elementos horizontalmente */
            justify-content: space-between; /* Distribuir elementos en los extremos */
            align-items: center; /* Centrar verticalmente */
            padding: 0 20px; /* A�adir relleno a los lados */
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); /* Sombra suave */
        }

        header h1 {
            margin: 0;
            font-size: 24px; /* Tama�o de fuente aumentado */
            font-weight: bold; /* Texto m�s impactante */
        }

        /* Estilos del login */
        #login {
            position: fixed;
            top: 0;
            right: 0;
            padding: 10px;
            color: white; /* Texto blanco */
            font-family: Arial, sans-serif;
        }

        #login a {
            color: white; /* Color del enlace blanco */
        }

        @media screen and (max-width: 768px) {
            /* Estilos para pantallas peque�as */
            #menu-lateral {
                width: 170px; /* Reducir el ancho del men� */
                padding-top: 80px; /* Altura del encabezado disminuida */
            }

            #contenido {
                margin-left: 170px; /* Aumentar el margen para dejar espacio al men� */
            }

            header {
                height: 70px; /* Altura del encabezado reducida */
                line-height: 70px; /* Ajustar l�nea de altura */
            }

            header h1 {
                font-size: 18px; /* Tama�o de fuente reducido */
                line-height: 70px; /* Ajustar l�nea de altura */
            }
        }

        #menu {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }

        .menu-btn {
            background-color: transparent;
            color: #555;
            border: 2px solid #ddd;
            border-radius: 10px; /* Aumentar el radio para hacer los botones m�s redondeados */
            padding: 20px 40px; /* Aumentar el padding para hacer los botones m�s grandes */
            margin: 0 20px; /* Aumentar el margen para separar los botones */
            cursor: pointer;
            font-size: 18px; /* Aumentar el tama�o de la fuente */
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }

        @media screen and (max-width: 768px) {
            .menu-btn {
                padding: 15px 30px; /* Reducir el padding en pantallas peque�as */
                margin: 0 10px; /* Reducir el margen en pantallas peque�as */
                font-size: 16px; /* Reducir el tama�o de la fuente en pantallas peque�as */
            }
        }

      
        #menu {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .menu-btn {
            background-color: #f5f5f5;
            color: #555;
            border: 1px solid #ddd;
            border-radius: 5px; /* Radio de los bordes */
            padding: 15px;
            text-align: center;
            cursor: pointer;
            font-size: 16px;
            width: 150px; /* Ancho de los botones */
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .menu-btn i {
            display: block;
            margin-bottom: 10px;
            font-size: 24px;
        }

        .menu-btn:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 12px rgba(0, 0, 0, 0.2);
        }

        .menu-btn.selected {
            background-color: #B22222;
            color: white;
            border-color: #FF6F61;
        }
    </style>
    <script>
        function enviarFormulario(formId) {
            document.getElementById(formId).submit();
        }
    </script>
</head>
<body>
    <header>
        <h1>Solicitud Profesores Temporales Unicauca</h1>
        <div id="login">
            <?php
            if (isset($_SESSION['loggedin'])) {  
                echo "<i>" . $_SESSION['name'] . "</i> <i class='fas fa-sign-out-alt' style='font-size:12px;color:#245D96'></i><a href='../../temporalesc/logout.php'>Logout</a>";
            } else {
                echo $error_msg;
            }
            ?>
        </div>
    </header>

    <?php if (isset($_SESSION['loggedin'])): ?>
    <div id="contenido">
        <br><br><br>
       
        <div id="menu">
            <?php if (isset($tipo_usuario) && $tipo_usuario == 3): ?>
                <button class="menu-btn" onclick="window.location.href='indexsolicitud.php'" title="Aqu� puede cargar docentes temporales para el periodo correspondiente">
                    <i class="fas fa-users"></i> Cargar Solicitud
                </button>
                <form id="verOcasionalCatedraForm" action="consulta_todo_depto.php" method="POST" style="display: none;">
                    <input type="hidden" name="facultad_id" value="<?php echo htmlspecialchars($facultad_id); ?>">
                    <input type="hidden" name="departamento_id" value="<?php echo htmlspecialchars($depto_user); ?>">
                    <input type="hidden" name="anio_semestre" value="<?php echo htmlspecialchars($anio_semestre); ?>">
                </form>
                <button class="menu-btn" onclick="enviarFormulario('verOcasionalCatedraForm')" title="Aqu� puede gestionar los profesores temporales cargados y generar oficio para la facultad y descargar el reporte">
                    <i class="fas fa-chalkboard-teacher"></i> Ver Solicitudes
                </button>
            <?php endif; ?>
            <?php if (isset($tipo_usuario) && $tipo_usuario != 3): ?>
                <button class="menu-btn" onclick="window.location.href='report_depto_full.php'" title="Aqu� puede ver el avance de los departamentos, generar oficio para la Vicerrector�a Acad�mica y descargar excel">
                    <i class="fas fa-list"></i> Resumen por facultad
                </button>
            <?php endif; ?>
            <?php if (isset($tipo_usuario) && $tipo_usuario == 1): ?>
                <button class="menu-btn" onclick="window.location.href='gestion_periodos.php'" title="Aqu� puede administrar (abrir, cerrar o eliminar periodos)">
                    <i class="fas fa-calendar-alt"></i> Gesti�n Periodos
                </button>
            <?php endif; ?>
            <?php if (isset($tipo_usuario) && $tipo_usuario != 3): ?>
                <button class="menu-btn" onclick="window.location.href='graficos_powerbi.php'" title="Aqu� puede ver gr�ficos de Power BI">
                    <i class="fas fa-chart-bar"></i> Gr�ficos
                </button>
            <?php endif; ?>
            <button class="menu-btn" onclick="window.location.href='tutorial.php'" title="Aqu� puede ver el proceso de solicitudes de profesores">
                <i class="fas fa-book"></i> Tutorial
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Scripts opcionales -->
    <script src="https://code.jquery.com/jquery-3.2.1.slim.min.js" integrity="sha384-KyZXEAg3QhqLMpG8r+8fhAXLRk2vvoC2rUmkjgxerL8C4nhk6U7BWHg7P5Kk2h1J" crossorigin="anonymous"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0-beta.2/js/bootstrap.min.js" integrity="sha384-alpBpkh1PFOepccYVYDB4do5UnbKysX5WZXm3XxPqe5iKTfUKjNkCk9SaVuEZflJ" crossorigin="anonymous"></script>
</body>
</html>
