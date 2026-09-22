<?php
/**
 * Sophía - Sistema de Gestión PQRS
 * "Gestión con sabiduría y cercanía"
 * 
 * Sophía → sabiduría 📖 → Simboliza inteligencia en la gestión y toma de decisiones
 */

// Redirigir al login si no está autenticado
session_start();
if (!isset($_SESSION['autenticado']) || !$_SESSION['autenticado']) {
    header('Location: auth/login.php');
    exit;
} else {
    header('Location: dashboard/');
    exit;
}
?>
