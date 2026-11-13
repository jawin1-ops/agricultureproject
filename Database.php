<?php

$host = "db.saqltdvcpgentxyjhrsg.supabase.co";
$port = "5432";
$dbname = "postgres";
$user = "postgres";
$password = "jawinhermosa1";

try {

    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $user,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Connected to Supabase successfully!";

} catch (PDOException $e) {

    echo "Connection failed: " . $e->getMessage();

}