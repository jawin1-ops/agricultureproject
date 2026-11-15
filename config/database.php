<?php

// Read connection details from environment variables (set via Render dashboard).
// Fallback values are intentionally left empty so a missing var fails loudly.
$host     = getenv('DB_HOST') ?: 'db.saqltdvcpgentxyjhrsg.supabase.co';
$port     = getenv('DB_PORT') ?: '5432';
$dbname   = getenv('DB_NAME') ?: 'postgres';
$user     = getenv('DB_USER') ?: 'postgres';
$password = getenv('DB_PASS') ?: 'jawinhermosa1';

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $user,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}
