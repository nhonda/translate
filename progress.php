<?php
session_start();
header('Content-Type: application/json');
$progressFile = sys_get_temp_dir() . '/progress_' . session_id() . '.json';
if (is_file($progressFile)) {
    $data = json_decode(file_get_contents($progressFile), true);
    if (!is_array($data)) {
        $data = ['percent'=>0, 'message'=>''];
    }
} else {
    $data = ['percent'=>0, 'message'=>''];
}
echo json_encode($data);
