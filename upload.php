<?php
header('Content-Type: application/json');
$uploadDir = __DIR__ .'/uploads/images/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir,0777, true);
}

if(isset($_FILES['image'])){
    $file = $_FILES['image'];

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if(!in_array($file['type'], $allowedTypes)){
        echo json_encode(['error'=> 'Invalid file type']);
        exit;
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() .'.'. $ext;
    $filepath = $uploadDir . $filename;

    if(move_uploaded_file($file['tmp_name'], $filepath)){
        $fileUrl = 'uploads/images/' . $filename;
        echo json_encode(['url' => $fileUrl]);
    }else{
        echo json_encode(['error'=> 'Failed to move uploaded file']);
    }
}else{
    echo json_encode(['error'=> 'No file uploaded']);
}


?>