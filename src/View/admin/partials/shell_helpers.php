<?php
if (!function_exists('adminWikiBaseUrl')) {
    function adminWikiBaseUrl(): string
    {
        return 'https://github.com/softerfish/fyuhls/wiki';
    }
}

if (!function_exists('adminWikiPageUrl')) {
    function adminWikiPageUrl(?string $helpKey = null): ?string
    {
        $helpKey = trim((string)$helpKey);
        if ($helpKey === '' || $helpKey === 'docs') {
            return adminWikiBaseUrl();
        }

        $map = [
            'dashboard' => 'Admin-Dashboard-Guide',
            'configuration' => 'Config-Hub-Guide',
            'settings' => 'Config-Hub-Reference',
            'ticket_settings' => 'Config-Hub-Reference',
            'status' => 'System-Status-and-Diagnostics',
            'logs' => 'Application-Logs',
            'support' => 'Support-Center-and-Support-Bundles',
            'resources' => 'Home',
            'staff_activity' => 'Staff-Permissions-and-Activity',
            'requests' => 'Requests-and-Compliance',
            'contacts' => 'Requests-and-Compliance',
            'abuse' => 'Requests-and-Compliance',
            'dmca' => 'Requests-and-Compliance',
            'files' => 'Uploads-and-Downloads',
            'uploader_investigation' => 'Investigations',
            'file_investigation' => 'Investigations',
            'live_downloads' => 'Live-Downloads',
            'withdrawals' => 'Rewards-and-Withdrawals',
            'rewards_fraud' => 'Rewards-Fraud',
            'bonus_reviews' => 'Bonus-Offers',
            'users' => 'Users',
            'packages' => 'Packages',
            'subscriptions' => 'Subscriptions',
            'subscription_create' => 'Manual-Subscription-and-Offline-Premium-Grants',
            'coupons' => 'Coupons',
            'site_content' => 'Site-Content-Editor',
            'file-servers' => 'Storage-Nodes',
            'file_server_add' => 'Storage-Nodes',
            'file_server_edit' => 'Storage-Nodes',
            'file_server_migrate' => 'Storage-Nodes',
            'delivery' => 'Downloads,-CDN,-and-Delivery-Methods',
            'file_manager' => 'File-Manager-Guide',
            'scaling' => 'Scaling-Guide',
            'security' => 'Security-Operations',
            'email' => 'Email-and-SMTP',
            'plugins' => 'Plugins',
            'monitoring' => 'Server-Monitoring',
            'search' => 'Find-the-Right-Page',
            'link_checker' => 'Link-Checker',
            'seo' => 'Find-the-Right-Page',
        ];

        $slug = $map[$helpKey] ?? null;
        if ($slug === null) {
            return null;
        }

        return adminWikiBaseUrl() . '/' . $slug;
    }
}

if (!function_exists('renderAdminPageHeader')) {
    function renderAdminPageHeader(string $title, string $description = '', string $actionsHtml = ''): void
    {
        ?>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1"><?= htmlspecialchars($title) ?></h1>
                <?php if ($description !== ''): ?>
                    <p class="text-muted mb-0"><?= htmlspecialchars($description) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($actionsHtml !== ''): ?>
                <div><?= $actionsHtml ?></div>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('renderAdminCardStart')) {
    function renderAdminCardStart(?string $title = null, array $options = []): void
    {
        $cardClass = trim((string)($options['cardClass'] ?? 'card border-0 shadow-sm'));
        $headerClass = trim((string)($options['headerClass'] ?? 'card-header bg-white'));
        $bodyClass = trim((string)($options['bodyClass'] ?? 'card-body'));
        $headerHtml = (string)($options['headerHtml'] ?? '');
        ?>
        <div class="<?= htmlspecialchars($cardClass) ?>">
            <?php if ($title !== null || $headerHtml !== ''): ?>
                <div class="<?= htmlspecialchars($headerClass) ?>">
                    <?php if ($title !== null): ?>
                        <div class="fw-semibold"><?= htmlspecialchars($title) ?></div>
                    <?php endif; ?>
                    <?= $headerHtml ?>
                </div>
            <?php endif; ?>
            <div class="<?= htmlspecialchars($bodyClass) ?>">
        <?php
    }
}

if (!function_exists('renderAdminCardEnd')) {
    function renderAdminCardEnd(): void
    {
        ?>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('renderAdminStatCard')) {
    function renderAdminStatCard(string $label, string $value, string $cardClass = '', string $valueClass = ''): void
    {
        $cardClass = trim('card border-0 shadow-sm h-100 ' . $cardClass);
        $valueClass = trim('fs-4 fw-bold ' . $valueClass);
        $labelClass = preg_match('/\btext-(?!muted\b)[\w-]+/', $cardClass) === 1
            ? 'small opacity-75'
            : 'text-muted small';
        ?>
        <div class="<?= htmlspecialchars($cardClass) ?>">
            <div class="card-body">
                <div class="<?= htmlspecialchars($labelClass) ?>"><?= htmlspecialchars($label) ?></div>
                <div class="<?= htmlspecialchars($valueClass) ?>"><?= $value ?></div>
            </div>
        </div>
        <?php
    }
}
