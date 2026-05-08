<?php

namespace App\Controller\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Service\DemoModeService;
use App\Service\SiteContentService;

class SiteContentController
{
    private function checkAuth(): void
    {
        Auth::requireAdmin();
    }

    private function ensureWritable(): void
    {
        if (!DemoModeService::currentViewerIsDemoAdmin()) {
            return;
        }

        $pageKey = $this->activePageKey();
        $locale = $this->activeLocale();
        $_SESSION['site_content_error'] = 'This demo admin account is read-only while demo mode is enabled.';
        header('Location: /admin/site-content?page=' . rawurlencode($pageKey) . '&locale=' . rawurlencode($locale));
        exit;
    }

    private function activePageKey(): string
    {
        $pageKey = trim((string)($_GET['page'] ?? $_POST['page_key'] ?? 'homepage'));
        $editablePages = SiteContentService::editablePages();
        return isset($editablePages[$pageKey]) ? $pageKey : 'homepage';
    }

    private function activeLocale(): string
    {
        return SiteContentService::requestLocale((string)($_POST['locale'] ?? \App\Service\SiteContentService::DEFAULT_LOCALE));
    }

    public function index(): void
    {
        $this->checkAuth();

        $pageKey = $this->activePageKey();
        $locale = $this->activeLocale();
        $pageDefinitions = SiteContentService::editablePages();
        $revisionId = (int)($_GET['revision'] ?? 0);
        $previewUrl = (string)($_SESSION['site_content_preview_url'] ?? '');
        $success = (string)($_SESSION['site_content_success'] ?? '');
        $error = (string)($_SESSION['site_content_error'] ?? '');
        $importResult = $_SESSION['site_content_import'] ?? null;
        unset($_SESSION['site_content_preview_url'], $_SESSION['site_content_success'], $_SESSION['site_content_error'], $_SESSION['site_content_import']);
        $selectedRevision = $revisionId > 0 ? SiteContentService::getRevisionDetails($revisionId, $pageKey, $locale) : null;

        View::render('admin/site_content.php', [
            'pageDefinitions' => $pageDefinitions,
            'activePageKey' => $pageKey,
            'activeLocale' => $locale,
            'availableLocales' => SiteContentService::availableLocales(),
            'pageDefinition' => $pageDefinitions[$pageKey],
            'pageContent' => SiteContentService::currentSnapshot($pageKey, $locale),
            'pageRevisions' => SiteContentService::getRevisions($pageKey, $locale),
            'selectedRevision' => $selectedRevision,
            'previewUrl' => $previewUrl,
            'successMessage' => $success,
            'errorMessage' => $error,
            'importResult' => $importResult,
            'markdownHelpLines' => SiteContentService::markdownHelpLines(),
            'availableTokens' => SiteContentService::availableTokens(),
            'themeWarnings' => SiteContentService::getThemeCompatibilityWarnings(),
            'previewTtlMinutes' => 60,
        ]);
    }

    public function save(): void
    {
        $this->checkAuth();
        $this->ensureWritable();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            exit('CSRF Mismatch');
        }

        $pageKey = $this->activePageKey();
        $locale = $this->activeLocale();
        try {
            if (($_POST['reset_page'] ?? '') === '1') {
                SiteContentService::savePage($pageKey, SiteContentService::defaultSnapshot($pageKey), (int)(Auth::id() ?? 0), $locale, 'reset');
                $_SESSION['site_content_success'] = 'Page content was reset to the built-in defaults and saved as a new revision.';
            } else {
                SiteContentService::savePage($pageKey, (array)($_POST['blocks'] ?? []), (int)(Auth::id() ?? 0), $locale);
                $_SESSION['site_content_success'] = 'Site content was saved live and a new revision was recorded.';
            }
        } catch (\Throwable $e) {
            $_SESSION['site_content_error'] = $e->getMessage();
        }

        header('Location: /admin/site-content?page=' . rawurlencode($pageKey) . '&locale=' . rawurlencode($locale));
        exit;
    }

    public function preview(): void
    {
        $this->checkAuth();
        $this->ensureWritable();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            exit('CSRF Mismatch');
        }

        $pageKey = $this->activePageKey();
        $locale = $this->activeLocale();
        try {
            $token = SiteContentService::createPreviewToken($pageKey, (array)($_POST['blocks'] ?? []), (int)(Auth::id() ?? 0), $locale);
            $_SESSION['site_content_preview_url'] = SiteContentService::buildPreviewUrl($pageKey, $token, $locale);
            $_SESSION['site_content_success'] = 'Preview link generated. It stays valid for 60 minutes and only works for your current admin session.';
        } catch (\Throwable $e) {
            $_SESSION['site_content_error'] = $e->getMessage();
        }

        header('Location: /admin/site-content?page=' . rawurlencode($pageKey) . '&locale=' . rawurlencode($locale));
        exit;
    }

    public function restore(): void
    {
        $this->checkAuth();
        $this->ensureWritable();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            exit('CSRF Mismatch');
        }

        $revisionId = (int)($_POST['revision_id'] ?? 0);
        $pageKey = $this->activePageKey();
        $locale = $this->activeLocale();
        try {
            $restored = SiteContentService::restoreRevision($revisionId, (int)(Auth::id() ?? 0));
            $pageKey = (string)($restored['page_key'] ?? $pageKey);
            $locale = (string)($restored['locale'] ?? $locale);
            $_SESSION['site_content_success'] = 'Revision restored. The restore itself was saved as a new revision entry.';
        } catch (\Throwable $e) {
            $_SESSION['site_content_error'] = $e->getMessage();
        }

        header('Location: /admin/site-content?page=' . rawurlencode($pageKey) . '&locale=' . rawurlencode($locale));
        exit;
    }

    public function export(): void
    {
        $this->checkAuth();

        $pageKey = $this->activePageKey();
        $locale = $this->activeLocale();
        $payload = SiteContentService::exportAll($locale);
        $filename = 'site-content-export-' . date('Ymd-His') . '.json';
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function import(): void
    {
        $this->checkAuth();
        $this->ensureWritable();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            exit('CSRF Mismatch');
        }

        $json = trim((string)($_POST['import_json'] ?? ''));
        if ($json === '' && isset($_FILES['import_file']) && is_uploaded_file($_FILES['import_file']['tmp_name'] ?? '')) {
            $json = (string)file_get_contents($_FILES['import_file']['tmp_name']);
        }

        if ($json === '') {
            $_SESSION['site_content_error'] = 'Paste a JSON export or upload an export file to import.';
            $pageKey = $this->activePageKey();
            $locale = $this->activeLocale();
            header('Location: /admin/site-content?page=' . rawurlencode($pageKey) . '&locale=' . rawurlencode($locale));
            exit;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $result = SiteContentService::importAll($decoded, (int)(Auth::id() ?? 0));
            $_SESSION['site_content_success'] = 'Site content import completed successfully.';
            $_SESSION['site_content_import'] = $result;
        } catch (\Throwable $e) {
            $_SESSION['site_content_error'] = $e->getMessage();
        }

        $pageKey = $this->activePageKey();
        $locale = $this->activeLocale();
        header('Location: /admin/site-content?page=' . rawurlencode($pageKey) . '&locale=' . rawurlencode($locale));
        exit;
    }
}
