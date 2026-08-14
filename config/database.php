<?php
// ─── DATABASE CONFIGURATION ──────────────────────────────────────────────────
// Real credentials come from the environment on the server. The defaults here
// are safe placeholders so this file can live in a public repo.
define('DB_HOST',    getenv('TL_DB_HOST') ?: 'localhost');
define('DB_NAME',    getenv('TL_DB_NAME') ?: 'towload');
define('DB_USER',    getenv('TL_DB_USER') ?: 'towload');
define('DB_PASS',    getenv('TL_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// TowMasters lives on the same MySQL server. Read-only cross-checks (does this
// tower already have a TowMasters company?) use this name.
define('TOWMASTERS_DB', getenv('TL_TM_DB') ?: 'towbook_saas');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // A unix socket is used in local/staging setups; production connects
        // over TCP to the DreamHost MySQL host.
        $socket = getenv('TL_DB_SOCKET');
        $dsn = $socket
            ? "mysql:unix_socket=" . $socket . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET
            : "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
