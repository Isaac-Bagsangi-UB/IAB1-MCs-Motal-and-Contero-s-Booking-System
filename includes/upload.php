<?php
// includes/upload.php
function uploadFile($fileInput, $subdir = 'misc', $allowedTypes = ['image/jpeg','image/png','image/webp']) {
    if (!isset($_FILES[$fileInput]) || $_FILES[$fileInput]['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'No file uploaded or upload error.'];
    }
    $file = $_FILES[$fileInput];
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'error' => 'Invalid file type.'];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File too large (max 5MB).'];
    }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('', true) . '.' . $ext;
    $dir = UPLOAD_DIR . $subdir . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $dest = $dir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'error' => 'Failed to save file.'];
    }
    return ['success' => true, 'path' => $subdir . '/' . $filename];
}

function uploadMultiple($fileInput, $subdir = 'unit_photos') {
    $results = [];
    if (!isset($_FILES[$fileInput]) || empty($_FILES[$fileInput]['name'][0])) return $results;
    $files = $_FILES[$fileInput];
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        $tmp = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ];
        $_FILES['_single'] = $tmp;
        $result = uploadFile('_single', $subdir);
        if ($result['success']) $results[] = $result['path'];
    }
    return $results;
}
