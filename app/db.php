<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Conexión PDO única (patrón singleton por request).
 *
 * Nota: este build de pdo_sqlsrv rechaza PDO::ATTR_TIMEOUT y
 * PDO::ATTR_PERSISTENT en el constructor, por eso no se usan.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        $detalle = APP_DEBUG ? ' (' . $e->getMessage() . ')' : '';
        exit('No se pudo conectar a SQL Server' . htmlspecialchars($detalle));
    }

    return $pdo;
}
