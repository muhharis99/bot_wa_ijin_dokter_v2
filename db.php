<?php

declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Cache for database connections
$_pdo_connections = [];

/**
 * Get database connection by name
 * Supports connections to local and remote database servers
 * 
 * @param string $name Database alias name (local, rsiklaten, server1, server2, etc.)
 * @return PDO
 * @throws Exception if database configuration not found or connection fails
 */
function get_db(string $name = 'local'): PDO
{
    global $_pdo_connections;
    $name = strtolower($name);
    // var_dump($name);die;
    $databases = $name === 'local' ? $GLOBALS['databases'] : $GLOBALS['databases'];
    if (!isset($_pdo_connections[$name])) {
        require_once __DIR__ . '/config.php';
        if (!isset($databases[$name])) {
            throw new Exception("Database configuration '$name' not found. Available: " . implode(', ', array_keys($databases)));
        }
        
        $config = $databases[$name];
        $host = $config['host'];
        $port = $config['port'] ?? 3306;
        $dbname = $config['name'];
        $user = $config['user'];
        $pass = $config['pass'] ?? '';
        
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        
        try {
            $_pdo_connections[$name] = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5
            ]);
            
            // Initialize local database if needed
            if ($name === 'local') {
                init_db($_pdo_connections[$name]);
            }
        } catch (PDOException $e) {
            error_log("Database connection error for '$name' ($host:$port): " . $e->getMessage());
            throw $e;
        }
    }

    return $_pdo_connections[$name];
}

/**
 * Legacy db() function - returns local database connection
 */
function db(): PDO
{
    return get_db('local');
}

/**
 * Legacy ext_db() function - returns rsiklaten database connection
 */
function ext_db(): PDO
{
    return get_db('rsiklaten');
}

function init_db(PDO $pdo): void
{
     if ((int)$pdo->query('SELECT COUNT(*) FROM doctors')->fetchColumn() === 0) seed_db($pdo);
}
   

function seed_db(PDO $pdo): void
{
}