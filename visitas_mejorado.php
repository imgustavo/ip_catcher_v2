<?php
// =============================================
// SCRIPT DE VISITAS PARA RAILWAY (v4.0)
// Características:
// 1. Almacenamiento en PostgreSQL
// 2. Endpoint para ver registros (/visitas?ver_log)
// 3. Compatible con entornos efímeros
// =============================================

// --- Configuración inicial --- //
date_default_timezone_set($_ENV['TZ'] ?? 'America/Argentina/Buenos_Aires');
header('Content-Type: application/json');

// --- Conexión a PostgreSQL (Railway) --- //
function conectarDB() {
    try {
        $url = parse_url($_ENV['DATABASE_URL']);
        $dsn = "pgsql:host={$url['host']};port={$url['port']};dbname=" . substr($url['path'], 1);
        return new PDO($dsn, $url['user'], $url['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        die(json_encode(['error' => 'Error de conexión a DB: ' . $e->getMessage()]));
    }
}

// --- Crear tabla si no existe --- //
function inicializarDB($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS visitas (
            id SERIAL PRIMARY KEY,
            ip VARCHAR(45),
            pais VARCHAR(50),
            region VARCHAR(50),
            ciudad VARCHAR(50),
            idioma VARCHAR(20),
            vpn BOOLEAN,
            so VARCHAR(30),
            navegador VARCHAR(30),
            dispositivo VARCHAR(20),
            user_agent TEXT,
            url TEXT,
            referencia TEXT,
            fecha_argentina TIMESTAMP,
            fecha_visitante TIMESTAMP,
            gmt_offset VARCHAR(6)
        )
    ");
}

// --- Obtener datos del visitante (las mismas funciones anteriores) --- //
// [Aquí van todas las funciones de detección: obtenerIP(), obtenerGeoData(), etc.]
// ... (Pega aquí las funciones del script original)

// --- Endpoint para ver registros --- //
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ver_log'])) {
    try {
        $db = conectarDB();
        $limit = min(intval($_GET['limit'] ?? 50), 100); // Límite por seguridad
        $registros = $db->query("SELECT * FROM visitas ORDER BY id DESC LIMIT $limit")->fetchAll();
        
        header('Content-Type: application/json');
        echo json_encode($registros, JSON_PRETTY_PRINT);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// --- Procesamiento principal --- //
$db = conectarDB();
inicializarDB($db);

$ip = obtenerIP();
$geoData = obtenerGeoData($ip);
$hora_argentina = date('Y-m-d H:i:s');
$hora_visitante = obtenerHoraVisitante($geoData->timezone ?? null);

// Insertar en PostgreSQL
try {
    $stmt = $db->prepare("
        INSERT INTO visitas (
            ip, pais, region, ciudad, idioma, vpn, so, navegador, 
            dispositivo, user_agent, url, referencia, fecha_argentina,
            fecha_visitante, gmt_offset
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $ip,
        $geoData->country ?? null,
        $geoData->regionName ?? null,
        $geoData->city ?? null,
        obtenerIdiomaNavegador(),
        ($geoData->proxy ?? false) ? true : false,
        obtenerSO(),
        obtenerNavegador(),
        obtenerDispositivo(),
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $_SERVER['HTTP_HOST'] . ($_SERVER['REQUEST_URI'] ?? ''),
        $_SERVER['HTTP_REFERER'] ?? null,
        $hora_argentina,
        explode(' ', $hora_visitante)[0] ?? null, // Extrae solo la fecha/hora
        preg_match('/\((.*?)\)/', $hora_visitante, $matches) ? $matches[1] : null // GMT offset
    ]);

    // Respuesta JSON para APIs
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        echo json_encode(['status' => 'success', 'ip' => $ip]);
        exit;
    }

} catch (PDOException $e) {
    error_log("Error al guardar visita: " . $e->getMessage());
}

// --- Modo debug (HTML) --- //
if (isset($_GET['debug'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<h1>Registro exitoso</h1>";
    echo "<pre>IP: {$ip} | País: {$geoData->country ?? 'N/A'}</pre>";
}
?>