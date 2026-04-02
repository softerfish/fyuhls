<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Model\Setting;

class SeoService
{
    public static function getAdminConfig(): array
    {
        return [
            'seo_title_template' => Setting::get('seo_title_template', '%page_title% | %site_name%'),
            'seo_default_meta_description' => Setting::get('seo_default_meta_description', ''),
            'seo_canonical_base_url' => Setting::get('seo_canonical_base_url', self::guessBaseUrl()),
            'seo_default_robots' => Setting::get('seo_default_robots', 'index,follow'),
            'seo_default_social_image' => Setting::get('seo_default_social_image', ''),
            'seo_organization_name' => Setting::get('seo_organization_name', Setting::getOrConfig('app.name', Config::get('app_name', 'Fyuhls'))),
            'seo_home_title' => Setting::get('seo_home_title', ''),
            'seo_home_description' => Setting::get('seo_home_description', ''),
            'seo_home_h1' => Setting::get('seo_home_h1', ''),
            'seo_home_intro' => Setting::get('seo_home_intro', ''),
            'seo_home_robots' => Setting::get('seo_home_robots', 'index,follow'),
            'seo_home_faq_schema' => Setting::get('seo_home_faq_schema', '0'),
            'seo_home_software_schema' => Setting::get('seo_home_software_schema', '1'),
            'seo_file_title_template' => Setting::get('seo_file_title_template', '%filename% - Download | %site_name%'),
            'seo_file_description_template' => Setting::get('seo_file_description_template', 'Download %filename% on %site_name%.'),
            'seo_sitemap_enabled' => Setting::get('seo_sitemap_enabled', '1'),
            'seo_sitemap_include_files' => Setting::get('seo_sitemap_include_files', '1'),
            'seo_robots_block_auth_pages' => Setting::get('seo_robots_block_auth_pages', '1'),
            'seo_noindex_internal_pages' => Setting::get('seo_noindex_internal_pages', '1'),
            'seo_file_noindex_private' => Setting::get('seo_file_noindex_private', '1'),
            'seo_verification_google' => Setting::get('seo_verification_google', ''),
            'seo_verification_bing' => Setting::get('seo_verification_bing', ''),
            'seo_custom_head_code' => Setting::get('seo_custom_head_code', ''),
        ];
    }

    public static function getHealthReport(): array
    {
        $config = self::getAdminConfig();
        $issues = [];
        $score = 100;

        if (trim($config['seo_canonical_base_url']) === '') {
            $issues[] = self::issue('critical', 'Canonical base URL is empty', 'Set the production site URL so canonical tags, sitemap URLs, and robots.txt are generated with the right host.');
            $score -= 25;
        }

        if (trim($config['seo_home_title']) === '') {
            $issues[] = self::issue('high', 'Homepage SEO title is not set', 'Add a homepage title that targets your main search phrase instead of relying only on the generic fallback.');
            $score -= 15;
        }

        if (mb_strlen(trim($config['seo_home_description'])) < 80) {
            $issues[] = self::issue('high', 'Homepage meta description is weak or missing', 'Write a homepage description around 120 to 160 characters so search engines have a stronger summary to display.');
            $score -= 15;
        }

        if (trim($config['seo_default_meta_description']) === '') {
            $issues[] = self::issue('medium', 'Default meta description fallback is empty', 'Set a global fallback description so public pages without a custom summary still output a meaningful meta description.');
            $score -= 10;
        }

        if ($config['seo_sitemap_enabled'] !== '1') {
            $issues[] = self::issue('medium', 'XML sitemap is disabled', 'Enable sitemap generation so search engines can discover public pages and file pages faster.');
            $score -= 10;
        }

        if ($config['seo_robots_block_auth_pages'] !== '1') {
            $issues[] = self::issue('medium', 'Auth pages are allowed to index', 'Block login, register, and password reset pages in robots.txt and meta robots so low-value pages do not compete with your money pages.');
            $score -= 8;
        }

        if (trim($config['seo_default_social_image']) === '') {
            $issues[] = self::issue('low', 'Default social image is missing', 'Add a share image so homepage and documentation shares look intentional on social platforms and chat apps.');
            $score -= 7;
        }

        if (trim($config['seo_verification_google']) === '') {
            $issues[] = self::issue('low', 'Google Search Console verification is not configured', 'Add your verification token so you can submit sitemaps and monitor indexing issues directly in Search Console.');
            $score -= 5;
        }

        return [
            'score' => max(0, $score),
            'rating' => self::scoreLabel($score),
            'issues' => $issues,
            'checks' => [
                'Public sitemap' => $config['seo_sitemap_enabled'] === '1' ? 'Enabled' : 'Disabled',
                'File pages in sitemap' => $config['seo_sitemap_include_files'] === '1' ? 'Enabled' : 'Disabled',
                'Auth pages blocked' => $config['seo_robots_block_auth_pages'] === '1' ? 'Yes' : 'No',
                'Homepage FAQ schema' => $config['seo_home_faq_schema'] === '1' ? 'Enabled' : 'Disabled',
                'Homepage software schema' => $config['seo_home_software_schema'] === '1' ? 'Enabled' : 'Disabled',
            ],
        ];
    }

    public static function buildHead(array $context = []): string
    {
        $document = self::getDocumentMeta($context);
        $config = self::getAdminConfig();
        $page = $document['page'];
        $title = $document['title'];
        $description = $document['description'];
        $canonical = $document['canonical'];
        $robots = $document['robots'];
        $socialImage = trim($config['seo_default_social_image']);
        $siteName = $page['vars']['site_name'];

        $tags = [];
        $tags[] = '<meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '">';
        $tags[] = '<meta name="robots" content="' . htmlspecialchars($robots, ENT_QUOTES, 'UTF-8') . '">';
        $tags[] = '<link rel="canonical" href="' . htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') . '">';
        $tags[] = '<meta property="og:type" content="' . htmlspecialchars($page['og_type'], ENT_QUOTES, 'UTF-8') . '">';
        $tags[] = '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">';
        $tags[] = '<meta property="og:description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '">';
        $tags[] = '<meta property="og:url" content="' . htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') . '">';
        $tags[] = '<meta property="og:site_name" content="' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '">';
        $tags[] = '<meta name="twitter:card" content="summary_large_image">';
        $tags[] = '<meta name="twitter:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">';
        $tags[] = '<meta name="twitter:description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '">';

        if ($socialImage !== '') {
            $image = self::absoluteUrl($socialImage);
            $tags[] = '<meta property="og:image" content="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '">';
            $tags[] = '<meta name="twitter:image" content="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '">';
        }

        if (trim($config['seo_verification_google']) !== '') {
            $tags[] = '<meta name="google-site-verification" content="' . htmlspecialchars($config['seo_verification_google'], ENT_QUOTES, 'UTF-8') . '">';
        }

        if (trim($config['seo_verification_bing']) !== '') {
            $tags[] = '<meta name="msvalidate.01" content="' . htmlspecialchars($config['seo_verification_bing'], ENT_QUOTES, 'UTF-8') . '">';
        }

        foreach (self::structuredData($page, $config, $title, $description, $canonical) as $schema) {
            $tags[] = '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
        }

        if (trim($config['seo_custom_head_code']) !== '') {
            $tags[] = $config['seo_custom_head_code'];
        }

        return implode("\n    ", $tags);
    }

    public static function getDocumentMeta(array $context = []): array
    {
        $config = self::getAdminConfig();
        $page = self::resolvePageConfig($context, $config);

        return [
            'page' => $page,
            'title' => self::interpolate($page['title_template'], $page['vars']),
            'description' => self::interpolate($page['description_template'], $page['vars']),
            'canonical' => self::canonicalUrl($page['path']),
            'robots' => $page['robots'] ?: $config['seo_default_robots'],
        ];
    }

    public static function detectInstallBaseUrl(): string
    {
        return self::guessBaseUrl();
    }

    public static function renderRobotsTxt(): string
    {
        $config = self::getAdminConfig();
        $lines = [
            'User-agent: *',
        ];

        if ($config['seo_noindex_internal_pages'] === '1') {
            $lines[] = 'Disallow: /admin';
            $lines[] = 'Disallow: /settings';
            $lines[] = 'Disallow: /notifications';
            $lines[] = 'Disallow: /trash';
            $lines[] = 'Disallow: /recent';
            $lines[] = 'Disallow: /shared';
        }

        if ($config['seo_robots_block_auth_pages'] === '1') {
            $lines[] = 'Disallow: /login';
            $lines[] = 'Disallow: /register';
            $lines[] = 'Disallow: /forgot-password';
            $lines[] = 'Disallow: /reset-password';
            $lines[] = 'Disallow: /2fa';
        }

        $lines[] = 'Allow: /';

        if ($config['seo_sitemap_enabled'] === '1') {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . self::canonicalUrl('/sitemap.xml');
        }

        return implode("\n", $lines) . "\n";
    }

    public static function renderSitemapXml(): string
    {
        $config = self::getAdminConfig();
        $urls = [];

        $add = static function (array &$urls, string $loc, string $changefreq = 'weekly', string $priority = '0.6', ?string $lastmod = null): void {
            $urls[] = [
                'loc' => $loc,
                'changefreq' => $changefreq,
                'priority' => $priority,
                'lastmod' => $lastmod,
            ];
        };

        $add($urls, self::canonicalUrl('/'), 'daily', '1.0');
        $add($urls, self::canonicalUrl('/api'), 'weekly', '0.8');
        $add($urls, self::canonicalUrl('/faq'), 'weekly', '0.8');
        $add($urls, self::canonicalUrl('/contact'), 'monthly', '0.5');
        $add($urls, self::canonicalUrl('/dmca'), 'monthly', '0.3');

        if (FeatureService::rewardsEnabled()) {
            $add($urls, self::canonicalUrl('/rewards'), 'weekly', '0.5');
        }
        if (FeatureService::affiliateEnabled()) {
            $add($urls, self::canonicalUrl('/affiliate'), 'weekly', '0.5');
        }

        if ($config['seo_sitemap_include_files'] === '1') {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("SELECT short_id, updated_at FROM files WHERE status = 'active' AND is_public = 1 ORDER BY id DESC");
            foreach ($stmt->fetchAll() as $file) {
                if (empty($file['short_id'])) {
                    continue;
                }
                $add($urls, self::canonicalUrl('/file/' . $file['short_id']), 'weekly', '0.7', !empty($file['updated_at']) ? date('c', strtotime($file['updated_at'])) : null);
            }
        }

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($urls as $url) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1) . '</loc>';
            if ($url['lastmod']) {
                $xml[] = '    <lastmod>' . htmlspecialchars($url['lastmod'], ENT_XML1) . '</lastmod>';
            }
            $xml[] = '    <changefreq>' . $url['changefreq'] . '</changefreq>';
            $xml[] = '    <priority>' . $url['priority'] . '</priority>';
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';

        return implode("\n", $xml);
    }

    private static function resolvePageConfig(array $context, array $config): array
    {
        $siteName = Setting::getOrConfig('app.name', Config::get('app_name', 'Fyuhls'));
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $pageKey = self::pageKey($path);

        $pageTitle = trim((string)($context['title'] ?? $siteName));
        $description = trim((string)($context['metaDescription'] ?? ''));
        $robots = '';
        $ogType = 'website';
        $vars = [
            'site_name' => $siteName,
            'page_title' => $pageTitle,
            'filename' => trim((string)($context['filename'] ?? '')),
            'version' => self::appVersion(),
        ];

        if ($pageKey === 'home') {
            $pageTitle = trim($config['seo_home_title']) !== '' ? $config['seo_home_title'] : $pageTitle;
            $description = trim($config['seo_home_description']) !== '' ? $config['seo_home_description'] : $description;
            $robots = $config['seo_home_robots'];
        } elseif ($pageKey === 'file') {
            $pageTitle = self::interpolate($config['seo_file_title_template'], $vars);
            $description = self::interpolate($config['seo_file_description_template'], $vars);
            $ogType = 'article';
            if (($context['isPublic'] ?? true) === false && $config['seo_file_noindex_private'] === '1') {
                $robots = 'noindex,nofollow';
            }
        } elseif (in_array($pageKey, ['login', 'register', 'forgot-password', 'reset-password'], true) && $config['seo_robots_block_auth_pages'] === '1') {
            $robots = 'noindex,nofollow';
        } elseif (in_array($pageKey, ['settings', 'notifications', 'trash', 'recent', 'shared'], true) && $config['seo_noindex_internal_pages'] === '1') {
            $robots = 'noindex,nofollow';
        }

        if ($description === '') {
            $description = trim($config['seo_default_meta_description']) !== '' ? $config['seo_default_meta_description'] : 'Self-hosted file hosting platform with package controls, storage backends, admin tooling, and optional rewards.';
        }

        return [
            'path' => $path,
            'page_key' => $pageKey,
            'title_template' => $pageKey === 'file' ? '%page_title%' : (trim($config['seo_title_template']) ?: '%page_title% | %site_name%'),
            'description_template' => $description,
            'robots' => $robots,
            'og_type' => $ogType,
            'vars' => array_merge($vars, ['page_title' => $pageTitle]),
        ];
    }

    private static function structuredData(array $page, array $config, string $title, string $description, string $canonical): array
    {
        $schemas = [];
        $orgName = trim($config['seo_organization_name']) !== '' ? $config['seo_organization_name'] : $page['vars']['site_name'];

        $schemas[] = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $orgName,
            'url' => self::canonicalUrl('/'),
        ];

        if ($page['page_key'] === 'home' && $config['seo_home_software_schema'] === '1') {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'SoftwareApplication',
                'name' => $page['vars']['site_name'],
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Linux',
                'softwareVersion' => $page['vars']['version'],
                'description' => $description,
                'url' => $canonical,
            ];
        }

        if ($page['page_key'] === 'home' && $config['seo_home_faq_schema'] === '1') {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => [
                    [
                        '@type' => 'Question',
                        'name' => 'Does this file hosting site run on shared hosting?',
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text' => 'Yes. This install is built for Linux-based shared hosting, VPS setups, and dedicated servers with the required PHP extensions and MySQL access.',
                        ],
                    ],
                    [
                        '@type' => 'Question',
                        'name' => 'Does the installer create the database automatically?',
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text' => 'No. The database and database user should be created first, then entered during installation.',
                        ],
                    ],
                    [
                        '@type' => 'Question',
                        'name' => 'Can this site use external storage providers?',
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text' => 'Yes. The script supports local storage plus Cloudflare R2, Backblaze B2, Wasabi, and generic S3-compatible providers.',
                        ],
                    ],
                ],
            ];
        }

        if ($page['page_key'] !== 'home') {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => $title,
                'description' => $description,
                'url' => $canonical,
            ];
        }

        return array_map(static fn(array $schema): array => array_filter($schema, static fn($value) => $value !== null && $value !== ''), $schemas);
    }

    private static function canonicalUrl(string $path): string
    {
        return self::trustedBaseUrl() . '/' . ltrim($path, '/');
    }

    private static function absoluteUrl(string $value): string
    {
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        return self::canonicalUrl($value);
    }

    private static function interpolate(string $template, array $vars): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['%' . $key . '%'] = (string)$value;
        }
        return trim(strtr($template, $replacements));
    }

    private static function scoreLabel(int $score): string
    {
        return match (true) {
            $score >= 90 => 'Excellent',
            $score >= 75 => 'Strong',
            $score >= 55 => 'Needs Work',
            default => 'Critical',
        };
    }

    private static function issue(string $severity, string $title, string $description): array
    {
        return compact('severity', 'title', 'description');
    }

    private static function guessBaseUrl(): string
    {
        $configured = Config::get('base_url', '');
        if (is_string($configured) && trim($configured) !== '' && !self::isPlaceholderBaseUrl($configured)) {
            return rtrim($configured, '/');
        }

        $requestBase = self::requestBaseUrl();
        if ($requestBase !== null) {
            return $requestBase;
        }

        return 'http://localhost';
    }

    public static function trustedBaseUrl(): string
    {
        $canonical = trim((string)Setting::get('seo_canonical_base_url', ''));
        if ($canonical !== '') {
            return rtrim($canonical, '/');
        }

        $configured = trim((string)Config::get('base_url', ''));
        if ($configured !== '' && !self::isPlaceholderBaseUrl($configured)) {
            return rtrim($configured, '/');
        }

        $requestBase = self::requestBaseUrl();
        if ($requestBase !== null) {
            return $requestBase;
        }

        return 'http://localhost';
    }

    private static function requestBaseUrl(): ?string
    {
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
        }

        if ($host === '') {
            return null;
        }

        $hostOnly = strtolower((string)parse_url('http://' . $host, PHP_URL_HOST));
        $configuredAllowedHosts = Config::get('security.allowed_hosts', []);
        if (is_array($configuredAllowedHosts) && !empty($configuredAllowedHosts)) {
            $normalizedAllowedHosts = array_values(array_filter(array_map(static function ($allowedHost) {
                $allowedHost = strtolower(trim((string)$allowedHost));
                return $allowedHost !== '' ? $allowedHost : null;
            }, $configuredAllowedHosts)));

            if ($hostOnly === '' || !in_array($hostOnly, $normalizedAllowedHosts, true)) {
                return null;
            }
        }

        $scheme = 'http';
        $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto !== '') {
            $scheme = explode(',', $forwardedProto)[0] === 'https' ? 'https' : 'http';
        } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https' ? 'https' : 'http';
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        }

        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $basePath = str_replace('\\', '/', dirname($scriptName));
        if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
            $basePath = '';
        }

        return $scheme . '://' . $host . $basePath;
    }

    private static function isPlaceholderBaseUrl(string $value): bool
    {
        $host = parse_url(trim($value), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function pageKey(string $path): string
    {
        return match (true) {
            $path === '/' => 'home',
            $path === '/api' => 'api',
            $path === '/faq' => 'faq',
            $path === '/contact' => 'contact',
            $path === '/dmca' => 'dmca',
            $path === '/login' => 'login',
            $path === '/register' => 'register',
            $path === '/forgot-password' => 'forgot-password',
            str_starts_with($path, '/reset-password') => 'reset-password',
            $path === '/affiliate' => 'affiliate',
            $path === '/rewards' => 'rewards',
            str_starts_with($path, '/file/') => 'file',
            $path === '/settings' => 'settings',
            $path === '/notifications' => 'notifications',
            $path === '/trash' => 'trash',
            $path === '/recent' => 'recent',
            $path === '/shared' => 'shared',
            default => 'page',
        };
    }

    private static function appVersion(): string
    {
        $path = defined('BASE_PATH')
            ? BASE_PATH . '/config/version.php'
            : dirname(__DIR__, 2) . '/config/version.php';

        if (!file_exists($path)) {
            return '0.1';
        }

        $config = require $path;
        return is_array($config) && !empty($config['version']) ? (string)$config['version'] : '0.1';
    }
}
