<?php

function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function secure_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        // Build-in security params if PHP 7.3+ (but we might be on older)
        // Manual regeneration
        session_start();
        if (!isset($_SESSION['initiated'])) {
            session_regenerate_id(true);
            $_SESSION['initiated'] = true;
        }
    }
}
