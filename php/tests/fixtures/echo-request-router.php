<?php

declare(strict_types=1);

// Minimal router for PHP's built-in server, used only by CurlHttpTransportTest
// to confirm which URL/method/body the transport actually sent.
header('Content-Type: application/json');
echo json_encode([
    'path' => $_SERVER['REQUEST_URI'],
    'method' => $_SERVER['REQUEST_METHOD'],
    'body' => json_decode(file_get_contents('php://input'), true),
]);
