<?php
declare(strict_types=1);

/**
 * Header template — HTML head, Bootstrap 5, sidebar, nav
 * Requiere $pageTitle (string) definido antes de incluir.
 */

if (!isset($pageTitle)) {
    $pageTitle = 'Dashboard';
}

$user        = currentUser();
$currentPage = basename($_SERVER['PHP_SELF']);
$currentName = str_replace('.php', '', $currentPage);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Clash Tracker — Sistema de gestión de clan para Clash of Clans">
    <title><?= clean($pageTitle) ?> — <?= APP_NAME ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom Theme -->
    <link href="assets/style.css?v=2.0" rel="stylesheet">
</head>
<body>

<!-- Mobile Toggle -->
<button class="ct-mobile-toggle" id="sidebarToggle" aria-label="Abrir menú">
    <i class="bi bi-list"></i>
</button>

<!-- Backdrop -->
<div class="ct-backdrop" id="sidebarBackdrop"></div>

<!-- Sidebar -->
<aside class="ct-sidebar" id="sidebar">
    <div class="ct-sidebar-brand">
        <h4>⚔️ <?= APP_NAME ?></h4>
        <small>Gestión de Clan</small>
    </div>

    <nav class="ct-sidebar-nav">
        <a href="index" class="nav-link <?= $currentName === 'index' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="bi bi-speedometer2"></i></span> Dashboard
        </a>
        <a href="jugadores" class="nav-link <?= $currentName === 'jugadores' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="bi bi-people-fill"></i></span> Jugadores
        </a>
        <a href="guerras" class="nav-link <?= $currentName === 'guerras' || $currentName === 'guerra_detalle' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="bi bi-lightning-fill"></i></span> Guerras
        </a>
        <a href="cwl" class="nav-link <?= $currentName === 'cwl' || $currentName === 'cwl_detalle' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="bi bi-trophy-fill"></i></span> Liga CWL
        </a>
        <a href="juegos" class="nav-link <?= $currentName === 'juegos' || $currentName === 'juegos_detalle' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="bi bi-controller"></i></span> Juegos del Clan
        </a>
        <a href="donaciones" class="nav-link <?= $currentName === 'donaciones' || $currentName === 'donaciones_detalle' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="bi bi-gift-fill"></i></span> Donaciones
        </a>
        <a href="capital" class="nav-link <?= $currentName === 'capital' || $currentName === 'capital_detalle' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="bi bi-building-fill"></i></span> Capital de Clan
        </a>
        <a href="reportes" class="nav-link <?= $currentName === 'reportes' ? 'active' : '' ?>">
            <span class="nav-icon"><i class="bi bi-bar-chart-fill"></i></span> Reporte Rendimiento
        </a>

        <?php if ($user && $user['rol'] === 'admin'): ?>
            <hr class="my-2 border-secondary">
            <a href="usuarios" class="nav-link <?= $currentName === 'usuarios' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="bi bi-shield-lock-fill"></i></span> Usuarios
            </a>
            <a href="log" class="nav-link <?= $currentName === 'log' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="bi bi-journal-text"></i></span> Log de Actividad
            </a>
        <?php endif; ?>
    </nav>

    <div class="ct-sidebar-footer">
        <?php if ($user): ?>
            <div class="user-name"><?= clean($user['nombre']) ?></div>
            <div class="user-role"><?= clean($user['rol']) ?></div>
            <a href="logout" class="btn btn-sm btn-outline-primary mt-2 w-100">
                <i class="bi bi-box-arrow-left"></i> Cerrar sesión
            </a>
        <?php endif; ?>
    </div>
</aside>

<!-- Main Content -->
<main class="ct-main">

    <?php
    // Flash messages
    $flash = getFlash();
    if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'error' ? 'danger' : 'info') ?> alert-dismissible fade show" role="alert">
            <?= clean($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    <?php endif; ?>
