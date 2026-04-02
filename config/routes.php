<?php
// Forced Sync - v3 (Enterprise Cleanup)
use App\Controller\HomeController;
use App\Controller\SeoController;
use App\Controller\AuthController;
use App\Controller\FileController;
use App\Controller\FolderController;
use App\Controller\CheckoutController;
use App\Controller\RewardsController;
use App\Controller\TwoFactorController;
use App\Controller\Admin\AdminController;
use App\Controller\Admin\UserController;
use App\Controller\Admin\TwoFactorController as AdminTwoFactorController;
use App\Controller\Admin\PluginController;
use App\Controller\Admin\SearchController;
use App\Controller\Admin\ConfigurationController;
use App\Controller\Admin\SecurityController;
use App\Controller\Admin\FileController as AdminFileController;
use App\Controller\Api\RewardsApiController;
use App\Controller\Api\UploadApiController;

// Public Routes
$router->get('/', [HomeController::class, 'index']);
$router->get('/upload', [HomeController::class, 'guestUpload']);
$router->get('/robots.txt', [SeoController::class, 'robotsTxt']);
$router->get('/sitemap.xml', [SeoController::class, 'sitemapXml']);
$router->get('/api', [HomeController::class, 'api']);
$router->get('/faq', [HomeController::class, 'faq']);
$router->get('/contact', [HomeController::class, 'contact']);
$router->post('/contact', [HomeController::class, 'contact']);
$router->get('/dmca', [HomeController::class, 'dmca']);
$router->post('/dmca', [HomeController::class, 'dmca']);
$router->get('/login', [AuthController::class, 'login']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'register']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/forgot-password', [AuthController::class, 'forgotPassword']);
$router->post('/forgot-password', [AuthController::class, 'forgotPassword']);
$router->get('/reset-password/{token}', [AuthController::class, 'resetPassword']);
$router->post('/reset-password/{token}', [AuthController::class, 'resetPassword']);
$router->get('/verify-email/{token}', [AuthController::class, 'verifyEmail']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/affiliate', [RewardsController::class, 'affiliate']);
$router->get('/rewards', [RewardsController::class, 'rewards']);
$router->post('/rewards/withdraw', [RewardsController::class, 'withdraw']);
$router->get('/2fa/verify', [TwoFactorController::class, 'showVerify']);
$router->post('/2fa/verify', [TwoFactorController::class, 'verify']);
$router->get('/2fa/setup', [TwoFactorController::class, 'showSetup']);
$router->post('/2fa/setup', [TwoFactorController::class, 'setup']);
$router->post('/2fa/recovery', [TwoFactorController::class, 'useRecoveryCode']);

// Account & Notifications
$router->get('/settings', [AuthController::class, 'settings']);
$router->post('/settings', [AuthController::class, 'settings']);
$router->post('/settings/update-monetization', [AuthController::class, 'updateMonetization']);
$router->get('/notifications', [HomeController::class, 'notifications']);
$router->post('/notifications/read', [HomeController::class, 'markNotificationsRead']);

// Checkout & Payments
$router->get('/checkout/{id}', [CheckoutController::class, 'index']);
$router->post('/checkout/process', [CheckoutController::class, 'process']);
$router->post('/payment/callback/{gateway}', [CheckoutController::class, 'callback']);
$router->get('/payment/stripe/success', [CheckoutController::class, 'stripeSuccess']);
$router->get('/payment/paypal/return', [CheckoutController::class, 'paypalReturn']);
$router->get('/payment/cancel', [CheckoutController::class, 'cancel']);

// File & Folder Routes
$router->post('/upload', [FileController::class, 'upload']);
$router->post('/upload/cancel', [FileController::class, 'cancelUpload']);
$router->post('/upload/remote', [FileController::class, 'remoteUpload']);
$router->post('/upload/remote/cancel', [FileController::class, 'cancelRemoteUpload']);
$router->post('/file/delete', [FileController::class, 'delete']);
$router->post('/file/report', [FileController::class, 'reportAbuse']);
$router->post('/bulk/delete', [FileController::class, 'bulkDelete']);
$router->post('/bulk/move', [FileController::class, 'bulkMove']);
$router->post('/bulk/trash', [FileController::class, 'bulkTrash']);
$router->post('/bulk/restore', [FileController::class, 'bulkRestore']);
$router->post('/bulk/copy', [FileController::class, 'bulkCopy']);
$router->post('/trash/empty', [FileController::class, 'emptyTrash']);
$router->get('/file/{id}', [FileController::class, 'show']);
$router->post('/file/generate-link', [FileController::class, 'generateLink']);
$router->post('/file/rename', [FileController::class, 'rename']);
$router->get('/download/{id}', [FileController::class, 'download']);
$router->get('/download/{id}/{name:.+}', [FileController::class, 'download']);
$router->get('/thumbnail/{hash:.+}', [FileController::class, 'thumbnail']);

$router->post('/folder/create', [FolderController::class, 'create']);
$router->post('/folder/rename', [FolderController::class, 'rename']);
$router->post('/folder/delete', [FolderController::class, 'delete']);
$router->get('/folders/json', [FolderController::class, 'listJson']);
$router->get('/folder/{id}', [HomeController::class, 'index']);
$router->get('/trash', [HomeController::class, 'trash']);
$router->get('/shared', [HomeController::class, 'shared']);
$router->get('/recent', [HomeController::class, 'recent']);

// --- Admin Infrastructure (Unified Configuration Hub) ---
$router->get('/admin/configuration', [ConfigurationController::class, 'index']);
$router->post('/admin/configuration/save', [ConfigurationController::class, 'save']);
$router->get('/admin/diagnostics/export', [ConfigurationController::class, 'exportDiagnostics']);
$router->post('/admin/cron/trigger', [ConfigurationController::class, 'triggerCron']);
$router->post('/admin/email/test-connection', [ConfigurationController::class, 'testSmtpConnection']);
$router->post('/admin/email/test-send', [ConfigurationController::class, 'sendTestEmail']);

// --- Admin Operations ---
$router->get('/admin', [AdminController::class, 'dashboard']);
$router->get('/admin/search', [SearchController::class, 'search']);
$router->get('/admin/users', [UserController::class, 'index']);
$router->post('/admin/users/create', [UserController::class, 'create']);
$router->post('/admin/users/action', [UserController::class, 'action']);
$router->get('/admin/users/edit/{id}', [UserController::class, 'edit']);
$router->post('/admin/users/edit/{id}', [UserController::class, 'edit']);
$router->post('/admin/users/disable-2fa', [AdminTwoFactorController::class, 'disableUser2FA']);

$router->get('/admin/files', [AdminFileController::class, 'index']);
$router->post('/admin/files/delete', [AdminFileController::class, 'delete']);

$router->get('/admin/withdrawals', [AdminController::class, 'withdrawals']);
$router->post('/admin/withdrawal/update', [AdminController::class, 'updateWithdrawal']);
$router->get('/admin/rewards-fraud', [AdminController::class, 'rewardsFraud']);
$router->post('/admin/rewards-fraud/save', [AdminController::class, 'saveRewardsFraud']);
$router->post('/admin/rewards-fraud/review', [AdminController::class, 'reviewRewardsFraud']);
$router->get('/admin/requests', [AdminController::class, 'requests']);
$router->post('/admin/requests/reply', [AdminController::class, 'replyToRequest']);
$router->post('/admin/requests/note', [AdminController::class, 'addRequestNote']);
$router->post('/admin/requests/status', [AdminController::class, 'updateRequestStatus']);
$router->get('/admin/abuse-reports', [AdminController::class, 'abuseReports']);
$router->post('/admin/abuse-reports/action', [AdminController::class, 'handleAbuseReport']);
$router->get('/admin/downloads/current', [AdminController::class, 'currentDownloadsView']);
$router->get('/admin/downloads/current/json', [AdminController::class, 'currentDownloadsData']);
$router->get('/admin/subscriptions', [AdminController::class, 'subscriptions']);
$router->get('/admin/resources', [AdminController::class, 'resources']);
$router->get('/admin/server-monitoring', [AdminController::class, 'serverMonitoringHistory']);
$router->get('/admin/file-server/migrate', [AdminController::class, 'migrateFiles']);
$router->post('/admin/file-server/migrate', [AdminController::class, 'migrateFiles']);
$router->get('/admin/file-server/add', [AdminController::class, 'addFileServer']);
$router->post('/admin/file-server/add', [AdminController::class, 'addFileServer']);
$router->get('/admin/file-server/edit/{id}', [AdminController::class, 'editFileServer']);
$router->post('/admin/file-server/edit/{id}', [AdminController::class, 'editFileServer']);
$router->get('/admin/file-server/test-delivery/{id}', [AdminController::class, 'testFileServerDelivery']);
$router->post('/admin/file-servers/delete', [AdminController::class, 'deleteFileServer']);
$router->post('/admin/file-server/set-default', [AdminController::class, 'setDefaultFileServer']);
$router->post('/admin/file-server/test', [AdminController::class, 'testFileServerConnection']);
$router->post('/admin/file-server/b2/discover', [AdminController::class, 'discoverBackblazeBuckets']);
$router->post('/admin/file-server/b2/apply-cors', [AdminController::class, 'applyBackblazeCors']);
$router->get('/admin/contacts', [AdminController::class, 'contacts']);
$router->get('/admin/dmca', [AdminController::class, 'dmcaReports']);
$router->get('/admin/packages', [AdminController::class, 'packages']);
$router->get('/admin/package/edit/{id}', [AdminController::class, 'editPackage']);
$router->post('/admin/package/edit/{id}', [AdminController::class, 'editPackage']);
$router->get('/admin/status', [AdminController::class, 'status']);
$router->post('/admin/update/apply', [AdminController::class, 'applyUpdate']);
$router->post('/admin/uploads/session/abort', [AdminController::class, 'abortUploadSession']);
$router->post('/admin/support/download', [AdminController::class, 'downloadSupportBundle']);
$router->post('/admin/support/email', [AdminController::class, 'emailSupportBundle']);
$router->get('/admin/logs', [AdminController::class, 'viewLogs']);
$router->post('/admin/logs/clear', [AdminController::class, 'clearLogs']);
$router->post('/admin/delete-setup-file', [AdminController::class, 'deleteSetupFile']);
$router->get('/admin/docs', [AdminController::class, 'documentation']);
$router->get('/admin/support', [AdminController::class, 'supportUs']);

// --- Security & PPD Actions ---
$router->post('/admin/security/sync', [SecurityController::class, 'syncCloudflare']);
$router->post('/admin/security/migrate', [SecurityController::class, 'migrateEncryption']);
$router->post('/admin/security/sync-schema', [SecurityController::class, 'syncSchema']);
$router->post('/admin/security/update', [SecurityController::class, 'updateSettings']);

// --- Plugins ---
$router->get('/admin/plugins', [PluginController::class, 'index']);
$router->post('/admin/plugins/upload', [PluginController::class, 'upload']);
$router->post('/admin/plugins/install/{dir}', [PluginController::class, 'install']);
$router->post('/admin/plugins/activate/{dir}', [PluginController::class, 'activate']);
$router->post('/admin/plugins/deactivate/{dir}', [PluginController::class, 'deactivate']);
$router->post('/admin/plugins/uninstall/{dir}', [PluginController::class, 'uninstall']);
$router->get('/admin/plugins/settings/{dir}', [PluginController::class, 'settings']);
$router->post('/admin/plugins/settings/{dir}', [PluginController::class, 'settings']);

// --- Enterprise API ---
$router->post('/api/rewards/receipt', [RewardsApiController::class, 'dropReceipt']);
$router->post('/api/callback/nginx-completed', [FileController::class, 'nginxDownloadCompleted']);
$router->post('/api/v1/uploads/sessions', [UploadApiController::class, 'createSession']);
$router->post('/api/v1/uploads/managed', [UploadApiController::class, 'createManagedUpload']);
$router->get('/api/v1/uploads/sessions/{id}', [UploadApiController::class, 'showSession']);
$router->post('/api/v1/uploads/sessions/{id}/parts/sign', [UploadApiController::class, 'signParts']);
$router->post('/api/v1/uploads/sessions/{id}/parts/report', [UploadApiController::class, 'reportPart']);
$router->post('/api/v1/uploads/sessions/{id}/complete', [UploadApiController::class, 'complete']);
$router->post('/api/v1/uploads/sessions/{id}/abort', [UploadApiController::class, 'abort']);
$router->get('/api/v1/files/{id}', [UploadApiController::class, 'fileInfo']);
$router->get('/api/v1/downloads/{id}/link', [UploadApiController::class, 'downloadLink']);

$router->get('/test', function() { echo "Router is working!"; });
