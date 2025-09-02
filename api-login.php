<?php
// api-login.php
session_start();

// Habilitar logging de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

// DEBUG: Log the request
error_log("==========================================");
error_log("API Login called: " . date('Y-m-d H:i:s'));
error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
error_log("Content type: " . ($_SERVER['CONTENT_TYPE'] ?? 'none'));
error_log("Remote addr: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// Permitir CORS para React
// Permitir CORS dinámico
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowed_origins = [
    'http://localhost:3000',
    'http://192.168.42.175:3000'
];

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}

header('Access-Control-Allow-Methods: POST, OPTIONS, GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    error_log("OPTIONS request handled - CORS preflight");
    http_response_code(200);
    exit();
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Invalid method: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

// Obtener datos JSON de React
$json = file_get_contents('php://input');
error_log("Raw input: " . $json);

$input = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON decode error: " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Formato JSON inválido']);
    exit();
}

$email = $input['email'] ?? '';
$password = $input['password'] ?? '';

error_log("Email: " . $email);
error_log("Password received: " . $password);

// Validar datos
if (empty($email) || empty($password)) {
    error_log("Empty email or password");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email y contraseña requeridos']);
    exit();
}

// Incluir conexión a BD
include 'conn.php';

error_log("DB host: " . $dbhost);
error_log("DB user: " . $dbuser);
error_log("DB name: " . $dbname);

// Conexión a BD
$conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbname);
if (!$conn) {
    $error_msg = mysqli_connect_error();
    error_log("DB connection failed: " . $error_msg);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos: ' . $error_msg]);
    exit();
}

error_log("DB connection successful");

// Buscar usuario con prepared statements
$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE Email = ?");
if (!$stmt) {
    error_log("Prepare failed: " . mysqli_error($conn));
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error en la consulta']);
    exit();
}

mysqli_stmt_bind_param($stmt, "s", $email);
if (!mysqli_stmt_execute($stmt)) {
    error_log("Execute failed: " . mysqli_stmt_error($stmt));
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al ejecutar consulta']);
    exit();
}

$result = mysqli_stmt_get_result($stmt);
if (mysqli_num_rows($result) === 0) {
    error_log("User not found: " . $email);
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Credenciales inválidas - usuario no encontrado']);
    exit();
}

$row = mysqli_fetch_assoc($result);
$hash = $row['Password'];

error_log("User found: " . $row['Name']);
error_log("Hash from DB: " . $hash);
error_log("Tipo usuario: " . $row['tipo_usuario']);

// Verificar contraseña
if (!password_verify($password, $hash)) {
    error_log("Password verification FAILED for: " . $email);
    error_log("Input password: " . $password);
    error_log("Stored hash: " . $hash);
    
    // Debug: verificar el algoritmo del hash
    $hashInfo = password_get_info($hash);
    error_log("Hash algorithm: " . $hashInfo['algo']);
    error_log("Hash options: " . print_r($hashInfo['options'], true));
    
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Credenciales inválidas - contraseña incorrecta']);
    exit();
}

error_log("Password verification SUCCESSFUL for: " . $email);

// Verificar si el usuario está activo
if (is_null($row['tipo_usuario'])) {
    error_log("User not activated: " . $email);
    echo json_encode([
        'success' => false, 
        'error' => 'Usuario pendiente de activación. Contacta al administrador en viceacad@unicauca.edu.co'
    ]);
    exit();
}

// Login exitoso - crear sesión
$_SESSION['loggedin'] = true;
$_SESSION['id_user'] = $row['Id'];
$_SESSION['name'] = $row['Name'];
$_SESSION['fk_fac_user'] = $row['fk_fac_user'];
$_SESSION['docusuario'] = $row['DocUsuario'];
$_SESSION['start'] = time();
$_SESSION['expire'] = $_SESSION['start'] + (5 * 3600);

// Devolver datos del usuario a React
$userData = [
    'success' => true,
    'user' => [
        'Id' => $row['Id'],
        'Name' => $row['Name'],
        'Email' => $row['Email'],
        'tipo_usuario' => $row['tipo_usuario'],
        'fk_depto_user' => $row['fk_depto_user'],
        'fk_fac_user' => $row['fk_fac_user'],
        'u_nombre_en_cargo' => $row['u_nombre_en_cargo'] ?? '',
        'email_padre' => $row['email_padre'] ?? '',
        'DocUsuario' => $row['DocUsuario'] ?? ''
    ]
];

error_log("Login successful, returning user data");
error_log("User data: " . print_r($userData, true));

echo json_encode($userData);

// Cerrar conexiones
mysqli_stmt_close($stmt);
mysqli_close($conn);

error_log("==========================================");
?>