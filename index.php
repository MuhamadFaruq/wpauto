<?php
// ================================================
// index.php — Front Controller / Router
// ================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

startSession();

$page = $_GET['page'] ?? 'dashboard';
$allowedPublic = ['login'];

// If not logged in and not on login page, redirect
if (!isLoggedIn() && !in_array($page, $allowedPublic)) {
    redirect(APP_URL . '/index.php?page=login');
}

// If logged in and trying to access login, redirect to dashboard
if (isLoggedIn() && $page === 'login') {
    redirect(APP_URL . '/index.php?page=dashboard');
}

// Admin-only pages
$adminPages = ['sites', 'users', 'author_map', 'settings', 'activity'];
if (in_array($page, $adminPages) && !isAdmin()) {
    setFlash('error', 'Akses ditolak. Halaman ini hanya untuk admin.');
    redirect(APP_URL . '/index.php?page=dashboard');
}

$pageMap = [
    'login'       => 'pages/login.php',
    'dashboard'   => 'pages/dashboard.php',
    'posts'       => 'pages/posts.php',
    'new_post'    => 'pages/new_post.php',
    'auto_writer' => 'pages/auto_writer.php',
    'auto_posts'  => 'pages/auto_posts.php',
    'edit_post'   => 'pages/edit_post.php',
    'sites'       => 'pages/sites.php',
    'users'       => 'pages/users.php',
    'author_map'  => 'pages/author_map.php',
    'profile'     => 'pages/profile.php',
    'activity'    => 'pages/activity.php',
    'settings'    => 'pages/settings.php',
];

$pageFile = $pageMap[$page] ?? null;

if (!$pageFile || !file_exists(__DIR__ . '/' . $pageFile)) {
    $pageFile = 'pages/404.php';
}

include __DIR__ . '/' . $pageFile;
