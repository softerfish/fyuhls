<?php

namespace App\Service;

use App\Core\Config;
use App\Model\Setting;

class DownloadPageService
{
    public function buildPublicShareFields(array $file): array
    {
        if (empty($file['is_public']) || empty($file['short_id'])) {
            return [];
        }

        $baseUrl = rtrim(SeoService::trustedBaseUrl(), '/');
        $pageUrl = $baseUrl . '/file/' . rawurlencode((string)$file['short_id']);
        $safeFilename = htmlspecialchars((string)($file['filename'] ?? ''), ENT_QUOTES, 'UTF-8');
        $pageUrlHtml = htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8');
        $shareFields = [
            [
                'label' => 'Page Link',
                'value' => $pageUrl,
            ],
            [
                'label' => 'HTML Code',
                'value' => '<a href="' . $pageUrlHtml . '" target="_blank" rel="noopener">' . $safeFilename . '</a>',
            ],
            [
                'label' => 'Forum Code',
                'value' => '[url=' . $pageUrl . ']' . (string)($file['filename'] ?? '') . '[/url]',
            ],
        ];

        $thumbnailUrl = null;
        $isImageFile = str_starts_with($this->resolveDisplayMimeType($file), 'image/');
        if ($isImageFile && !empty($file['file_hash']) && !empty($file['storage_path'])) {
            $pathParts = explode('/', trim((string)$file['storage_path'], '/'));
            if (count($pathParts) >= 3) {
                $thumbnailUrl = $baseUrl . '/thumbnail/' . rawurlencode($pathParts[0]) . '/' . rawurlencode($pathParts[1]) . '/' . rawurlencode((string)$file['file_hash']) . '.jpg';
            }
        }

        if ($thumbnailUrl !== null) {
            $thumbHtml = htmlspecialchars($thumbnailUrl, ENT_QUOTES, 'UTF-8');
            $shareFields[] = [
                'label' => 'Embed HTML Code',
                'value' => '<a href="' . $pageUrlHtml . '" target="_blank" rel="noopener"><img src="' . $thumbHtml . '" alt="' . $safeFilename . '"></a>',
            ];
            $shareFields[] = [
                'label' => 'Embed Forum Code',
                'value' => '[url=' . $pageUrl . '][img]' . $thumbnailUrl . '[/img][/url]',
            ];
        }

        return $shareFields;
    }

    public function buildStatePageViewModel(
        string $titleText,
        string $heading,
        string $message,
        int $statusCode = 200,
        ?array $package = null,
        ?array $file = null,
        array $shareFields = []
    ): array {
        $showAds = (bool)($package['show_ads'] ?? false);

        return [
            'statusCode' => $statusCode,
            'title' => $titleText,
            'metaDescription' => $message,
            'heading' => $heading,
            'message' => $message,
            'file' => $file,
            'shareFields' => $shareFields,
            'adLeft' => $showAds ? Setting::get('ad_download_left', '') : '',
            'adRight' => $showAds ? Setting::get('ad_download_right', '') : '',
            'adTop' => $showAds ? Setting::get('ad_download_top', '') : '',
            'adBottom' => $showAds ? Setting::get('ad_download_bottom', '') : '',
        ];
    }

    public function buildShowViewModel(array $file, array $package): array
    {
        $isGuest = !\App\Core\Auth::check();
        $captchaSiteKey = Setting::get('captcha_site_key', '');
        $captchaDownload = false;
        if ($captchaSiteKey !== '') {
            if ($isGuest && Setting::get('captcha_download_guest', '0') === '1') {
                $captchaDownload = true;
            }
            if (!$isGuest && Setting::get('captcha_download_free', '0') === '1') {
                $captchaDownload = true;
            }
        }

        $waitEnabled = ((int)($package['wait_time_enabled'] ?? 0)) === 1;
        $waitTime = $waitEnabled ? max(0, (int)($package['wait_time'] ?? 0)) : 0;
        $showAds = (bool)($package['show_ads'] ?? false);
        $streamingEnabled = Setting::get('streaming_support_enabled', '0') === '1';
        $streamingEligible = $streamingEnabled && $this->isVideoFile($file);

        return [
            'title' => 'Download: ' . (string)$file['filename'],
            'metaDescription' => 'Download ' . (string)$file['filename'] . ' from ' . Setting::getOrConfig('app.name', Config::get('app_name', 'Fyuhls')) . '.',
            'filename' => (string)$file['filename'],
            'isPublic' => !empty($file['is_public']),
            'file' => $file,
            'package' => $package,
            'captchaSiteKey' => $captchaSiteKey,
            'captchaDownload' => $captchaDownload,
            'waitTime' => $waitTime,
            'showAds' => $showAds,
            'adLeft' => $showAds ? Setting::get('ad_download_left', '') : '',
            'adRight' => $showAds ? Setting::get('ad_download_right', '') : '',
            'adTop' => $showAds ? Setting::get('ad_download_top', '') : '',
            'adBottom' => $showAds ? Setting::get('ad_download_bottom', '') : '',
            'adOverlay' => $showAds ? Setting::get('ad_download_overlay', '') : '',
            'streamingEligible' => $streamingEligible,
            'shareFields' => $this->buildPublicShareFields($file),
            'reportCaptchaEnabled' => Setting::get('captcha_report_file', '0') === '1',
            'reportCaptchaSiteKey' => Setting::get('captcha_site_key', Config::get('turnstile.site_key')),
            'abuseReportsEnabled' => Setting::get('enable_abuse_reports', '1') === '1',
        ];
    }

    public function resolveDisplayMimeType(array $file): string
    {
        $mimeType = (string)($file['mime_type'] ?? 'application/octet-stream');
        if (str_starts_with($mimeType, 'ENC:')) {
            $mimeType = EncryptionService::decrypt($mimeType);
        }

        return preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mimeType)
            ? $mimeType
            : 'application/octet-stream';
    }

    public function isVideoFile(array $file): bool
    {
        return str_starts_with($this->resolveDisplayMimeType($file), 'video/');
    }
}
