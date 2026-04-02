<?php
$showRewards = \App\Service\FeatureService::rewardsEnabled();
$showAffiliate = \App\Service\FeatureService::affiliateEnabled();

// read site name from DB, fall back to config, fall back to 'fyuhls'
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls'));
$seoDocument = \App\Service\SeoService::getDocumentMeta([
    'title' => $title ?? $siteName,
    'metaDescription' => $metaDescription ?? '',
    'filename' => $filename ?? '',
    'isPublic' => $isPublic ?? true,
]);
$resolvedTitle = $seoDocument['title'];
$generatedHead = \App\Service\SeoService::buildHead([
    'title' => $title ?? $siteName,
    'metaDescription' => $metaDescription ?? '',
    'filename' => $filename ?? '',
    'isPublic' => $isPublic ?? true,
]);
$allowRegistrations = \App\Model\Setting::get('allow_registrations', '1') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($resolvedTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <?= $generatedHead . "\n" ?>
    <?= $extraHead ?? '' ?>
</head>
<body style="background-color: #f3f4f6; color: #1f2937; min-height: 100vh; display: flex; flex-direction: column;">

    <!-- Top Navigation -->
    <header style="background: white; border-bottom: 1px solid var(--border-color); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 50;">
        <a href="/" style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); text-decoration: none; letter-spacing: -0.5px;"><?= htmlspecialchars($siteName) ?></a>
        
        <div style="display: flex; gap: 2rem; align-items: center;">
            <a href="/api" style="text-decoration: none; color: var(--text-color); font-weight: 500; padding: 0.5rem 0;">API</a>
            <a href="/faq" style="text-decoration: none; color: var(--text-color); font-weight: 500; padding: 0.5rem 0;">FAQ</a>
            <?php if ($showAffiliate): ?>
                <a href="/affiliate" style="text-decoration: none; color: var(--text-color); font-weight: 500; padding: 0.5rem 0;">Affiliate</a>
            <?php endif; ?>
            
            <?php if (\App\Core\Auth::check()): ?>
                <a href="/notifications" style="text-decoration: none; position: relative;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="2" style="position: relative; top: 3px;">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <?php 
                    try {
                        $unreadNotifications = \App\Service\NotificationService::getUnread(\App\Core\Auth::id() ?? 0);
                        $unreadCount = count($unreadNotifications);
                        if ($unreadCount > 0): ?>
                            <span style="position: absolute; top: 0px; right: 0px; background: #ef4444; color: white; border-radius: 50%; width: 16px; height: 16px; font-size: 10px; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 2px solid white;">
                                <?= $unreadCount ?>
                            </span>
                        <?php endif; 
                    } catch (\Exception $e) { /* Ignore fatal unloads */ } ?>
                </a>

                <?php if (\App\Core\Auth::isAdmin()): ?>
                    <a href="/admin" style="text-decoration: none; color: var(--text-color); font-weight: 600; padding: 0.5rem 0;">Admin</a>
                <?php endif; ?>
                
                <form action="/logout" method="POST" style="margin: 0;">
                    <?= \App\Core\Csrf::field() ?>
                    <button type="submit" style="text-decoration: none; color: var(--text-color); font-weight: 500; background: var(--border-color); padding: 0.5rem 1rem; border-radius: 6px; border: 0; cursor: pointer;">Logout</button>
                </form>
            <?php else: ?>
                <a href="/login" style="text-decoration: none; color: var(--text-color); font-weight: 500; padding: 0.5rem 0;">Login</a>
                <?php if ($allowRegistrations): ?>
                    <a href="/register" style="text-decoration: none; color: white; font-weight: 500; background: var(--primary-color); padding: 0.5rem 1.25rem; border-radius: 6px; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);">Sign Up</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </header>

    <main style="flex: 1; display: flex; flex-direction: column;">
