<?php
session_start();
$sid = session_id();
session_write_close();
header('Content-Type: application/json');
$progressFile = sys_get_temp_dir() . '/progress_' . $sid . '.json';
if (is_file($progressFile)) {
    $data = json_decode(file_get_contents($progressFile), true);
    if (!is_array($data)) {
        $data = ['percent'=>0, 'message'=>''];
    }
} else {
    $data = ['percent'=>0, 'message'=>'翻訳実行中……'];
}
echo json_encode($data);
