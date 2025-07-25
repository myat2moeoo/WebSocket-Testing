<?php
header('Content-Type: application/json');

$allowedImageTypes = ['image/jpeg', 'image/png', 'image/gif'];
$allowedPdfTypes = ['application/pdf'];

if (!isset($_FILES['file'])) {
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
$type = $file['type'];

if (in_array($type, $allowedImageTypes)) {
    $subFolder = 'images';
    $fileType = 'image';
} elseif (in_array($type, $allowedPdfTypes)) {
    $subFolder = 'pdfs';
    $fileType = 'pdf';
} else {
    echo json_encode(['error' => 'Unsupported file type']);
    exit;
}

$uploadDir = __DIR__ . "/uploads/$subFolder/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = uniqid() . '.' . $ext;
$filepath = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $filepath)) {
    $fileUrl = "uploads/$subFolder/$filename";
    echo json_encode(['url' => $fileUrl, 'file_type' => $fileType]);
} else {
    echo json_encode(['error' => 'Failed to move uploaded file']);
}
?>
