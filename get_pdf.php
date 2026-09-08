<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    exit('Доступ запрещён');
}

$file = $_GET['file'] ?? '';
if (!preg_match('/^[a-f0-9]{16}\.pdf$/', $file)) {
    http_response_code(400);
    exit('Некорректное имя файла');
}

$path = dirname(__DIR__) . '/pdfuploads/' . $file;
if (!file_exists($path)) {
    http_response_code(404);
    exit('Файл не найден');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $file . '"');
readfile($path);
exit;