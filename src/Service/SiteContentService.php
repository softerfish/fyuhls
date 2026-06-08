<?php

namespace App\Service;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Model\Package;
use App\Model\Setting;
use PDO;

class SiteContentService
{
    public const EXPORT_SCHEMA_VERSION = '1.0';
    public const DEFAULT_LOCALE = 'en';
    public const PREVIEW_QUERY_KEY = 'content_preview';
    private const PREVIEW_TTL_SECONDS = 3600;
    private const MAX_REVISIONS_PER_PAGE_LOCALE = 10;
    private static bool $schemaEnsured = false;

    public static function editablePages(): array
    {
        $defs = self::definitions();
        return array_filter($defs, static fn (array $def): bool => !empty($def['admin_enabled']));
    }

    public static function requestLocale(?string $fallback = null): string
    {
        $requested = trim((string)($_GET['locale'] ?? ''));
        if ($requested !== '') {
            return self::normalizeLocale($requested);
        }
        return self::normalizeLocale($fallback);
    }

    public static function localizeUrl(string $url, ?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);
        if ($locale === self::DEFAULT_LOCALE) {
            return $url;
        }
        if (!str_starts_with($url, '/')) {
            return $url;
        }
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'locale=' . rawurlencode($locale);
    }

    public static function availableLocales(): array
    {
        self::ensureSchema();
        $locales = [self::DEFAULT_LOCALE];
        try {
            $pdo = Database::getInstance()->getConnection();
            $rows = $pdo->query("SELECT DISTINCT locale FROM site_content WHERE locale <> '' ORDER BY locale ASC")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $locale) {
                $locale = self::normalizeLocale((string)$locale);
                if (!in_array($locale, $locales, true)) {
                    $locales[] = $locale;
                }
            }
        } catch (\Throwable $e) {
            // ignore and keep default locale
        }
        sort($locales);
        return $locales;
    }

    public static function definitions(): array
    {
        return [
            'homepage' => [
                'label' => 'Homepage',
                'route' => '/',
                'template' => 'home/landing.php',
                'admin_enabled' => true,
                'blocks' => [
                    'hero' => [
                        'label' => 'Hero',
                        'type' => 'object',
                        'fields' => [
                            'kicker' => ['type' => 'text', 'label' => 'Kicker'],
                            'title' => ['type' => 'text', 'label' => 'Title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Intro copy'],
                            'primary_cta_label' => ['type' => 'text', 'label' => 'Primary button label'],
                            'guest_cta_label' => ['type' => 'text', 'label' => 'Guest upload button label'],
                            'features_cta_label' => ['type' => 'text', 'label' => 'Plans button label'],
                            'meta_registration_open' => ['type' => 'text', 'label' => 'Meta: registrations open'],
                            'meta_registration_closed' => ['type' => 'text', 'label' => 'Meta: registrations closed'],
                            'meta_downloads_require_account' => ['type' => 'text', 'label' => 'Meta: downloads require account'],
                            'meta_downloads_guest_allowed' => ['type' => 'text', 'label' => 'Meta: guest downloads allowed'],
                            'meta_verification_enabled' => ['type' => 'text', 'label' => 'Meta: verification enabled'],
                            'meta_verification_optional' => ['type' => 'text', 'label' => 'Meta: verification optional'],
                            'meta_guest_uploads_enabled' => ['type' => 'text', 'label' => 'Meta: guest uploads enabled'],
                            'meta_guest_uploads_disabled' => ['type' => 'text', 'label' => 'Meta: guest uploads disabled'],
                        ],
                        'default' => [
                            'kicker' => 'Hosted by {site_name}',
                            'title' => 'Upload large files, share download links, and keep everything in one place.',
                            'intro' => 'Create an account to manage your files, keep folders organized, unlock larger upload limits, and access creator rewards when they are enabled on this site.',
                            'primary_cta_label' => 'Create Account',
                            'guest_cta_label' => 'Guest Upload',
                            'features_cta_label' => 'View Plans',
                            'meta_registration_open' => 'Registrations are open',
                            'meta_registration_closed' => 'Registrations are currently closed',
                            'meta_downloads_require_account' => 'Downloads require an account',
                            'meta_downloads_guest_allowed' => 'Guest downloads are allowed',
                            'meta_verification_enabled' => 'Email verification is enabled',
                            'meta_verification_optional' => 'Email verification is optional',
                            'meta_guest_uploads_enabled' => 'Guest uploads are enabled',
                            'meta_guest_uploads_disabled' => 'Uploads require login',
                        ],
                    ],
                    'panel' => [
                        'label' => 'Hero panel',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Panel title'],
                            'account_levels_label' => ['type' => 'text', 'label' => 'Account levels label'],
                            'account_levels_summary_paid' => ['type' => 'markdown', 'label' => 'Account levels summary (paid available)'],
                            'account_levels_summary_free' => ['type' => 'markdown', 'label' => 'Account levels summary (no paid plan)'],
                            'access_style_label' => ['type' => 'text', 'label' => 'Access style label'],
                            'access_style_summary_member' => ['type' => 'markdown', 'label' => 'Access style summary (member downloads)'],
                            'access_style_summary_guest' => ['type' => 'markdown', 'label' => 'Access style summary (guest downloads)'],
                            'bullet_free_package' => ['type' => 'markdown', 'label' => 'Bullet: free package summary'],
                            'bullet_remote_upload_enabled' => ['type' => 'markdown', 'label' => 'Bullet: remote upload enabled'],
                            'bullet_remote_upload_disabled' => ['type' => 'markdown', 'label' => 'Bullet: remote upload disabled'],
                            'bullet_guest_upload_enabled' => ['type' => 'markdown', 'label' => 'Bullet: guest upload enabled'],
                            'bullet_guest_upload_disabled' => ['type' => 'markdown', 'label' => 'Bullet: guest upload disabled'],
                            'bullet_creator_summary' => ['type' => 'markdown', 'label' => 'Bullet: creator summary'],
                            'bullet_tier_summary' => ['type' => 'markdown', 'label' => 'Bullet: tier summary'],
                        ],
                        'default' => [
                            'title' => 'What this file host offers right now',
                            'account_levels_label' => 'Account Levels',
                            'account_levels_summary_paid' => 'Start with the current entry plan, then move into larger paid limits when you need them',
                            'account_levels_summary_free' => 'Built around the current guest and free access setup on this site',
                            'access_style_label' => 'Download Access',
                            'access_style_summary_member' => 'Downloads currently run through signed-in member accounts',
                            'access_style_summary_guest' => 'Guest visitors can still reach public download pages under the current rules',
                            'bullet_free_package' => 'Get started with uploads up to **{free_package_upload_limit}** and **{free_package_storage_limit}** of storage on the current **{free_package_name}** plan.',
                            'bullet_remote_upload_enabled' => 'Import files from a remote URL as well as through standard browser uploads on supported plans.',
                            'bullet_remote_upload_disabled' => 'Upload directly from your browser with the same package-based controls used across the site.',
                            'bullet_guest_upload_enabled' => 'Guests can open the dedicated upload page without creating an account first.',
                            'bullet_guest_upload_disabled' => 'Uploads currently require a signed-in account before files can be added.',
                            'bullet_creator_summary' => '{creator_summary}',
                            'bullet_tier_summary' => '{tier_summary}',
                        ],
                    ],
                    'trust_section' => [
                        'label' => 'Trust strip section',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Section title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Section intro'],
                        ],
                        'default' => [
                            'title' => 'What visitors can expect right now',
                            'intro' => 'These quick highlights pull from the live plan mix and current site settings, so the public page matches the service people are actually signing up for.',
                        ],
                    ],
                    'trust_cards' => [
                        'label' => 'Trust strip cards',
                        'type' => 'list',
                        'item_label' => 'Trust card',
                        'item_fields' => [
                            'id' => ['type' => 'hidden'],
                            'label' => ['type' => 'text', 'label' => 'Eyebrow label'],
                            'title' => ['type' => 'text', 'label' => 'Card title'],
                            'body' => ['type' => 'markdown', 'label' => 'Card body'],
                        ],
                        'default' => [
                            ['id' => 'plans', 'label' => 'Plans', 'title' => 'Live account levels', 'body' => '{plan_mix_summary}'],
                            ['id' => 'imports', 'label' => 'Imports', 'title' => 'Remote URL upload', 'body' => '{remote_upload_offer_summary}'],
                            ['id' => 'rewards', 'label' => 'Rewards', 'title' => 'Creator rewards', 'body' => '{creator_offer_summary}'],
                            ['id' => 'api', 'label' => 'Automation', 'title' => 'API access', 'body' => '{api_offer_summary}'],
                        ],
                    ],
                    'preview_section' => [
                        'label' => 'Product preview section',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Section title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Section intro'],
                        ],
                        'default' => [
                            'title' => 'See what members get after signup',
                            'intro' => 'The preview below mirrors the current package mix, file-management flow, and account tools that open up after registration.',
                        ],
                    ],
                    'account_section' => [
                        'label' => 'Why create an account section',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Section title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Section intro'],
                        ],
                        'default' => [
                            'title' => 'Why create an account',
                            'intro' => 'An account gives users a real home for uploads, folders, saved links, and larger limits whenever they need to move beyond the public guest flow.',
                        ],
                    ],
                    'account_cards' => [
                        'label' => 'Why create an account cards',
                        'type' => 'list',
                        'item_label' => 'Account benefit card',
                        'item_fields' => [
                            'id' => ['type' => 'hidden'],
                            'label' => ['type' => 'text', 'label' => 'Eyebrow label'],
                            'title' => ['type' => 'text', 'label' => 'Card title'],
                            'body' => ['type' => 'markdown', 'label' => 'Card body'],
                        ],
                        'default' => [
                            ['id' => 'workspace', 'label' => 'Files', 'title' => 'Keep uploads and folders organized', 'body' => 'Move from one-off links into a real file dashboard where uploads, folders, and account tools stay together.'],
                            ['id' => 'limits', 'label' => 'Limits', 'title' => 'Start with the current entry plan', 'body' => '{member_setup_summary}'],
                            ['id' => 'rewards', 'label' => 'Rewards', 'title' => 'Unlock creator tools when available', 'body' => '{creator_offer_summary}'],
                            ['id' => 'automation', 'label' => 'Imports', 'title' => 'Use API and remote upload tools', 'body' => '{api_offer_summary}'],
                        ],
                    ],
                    'features_section' => [
                        'label' => 'Features section',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Section title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Section intro'],
                        ],
                        'default' => [
                            'title' => 'More than a basic upload form',
                            'intro' => 'These deeper highlights show the account, delivery, and creator tools built into the file host after someone gets past the first signup decision.',
                        ],
                    ],
                    'feature_cards' => [
                        'label' => 'Feature cards',
                        'type' => 'list',
                        'item_label' => 'Feature card',
                        'item_fields' => [
                            'id' => ['type' => 'hidden'],
                            'icon' => ['type' => 'text', 'label' => 'Icon label'],
                            'label' => ['type' => 'text', 'label' => 'Eyebrow label'],
                            'title' => ['type' => 'text', 'label' => 'Card title'],
                            'body' => ['type' => 'markdown', 'label' => 'Card body'],
                        ],
                        'default' => [
                            ['id' => 'package_limits', 'icon' => 'Upload', 'label' => 'Upload', 'title' => 'Package-Based Limits', 'body' => '{free_package_name} currently allows up to **{free_package_upload_limit}** per file and **{free_package_storage_limit}** total storage.'],
                            ['id' => 'download_controls', 'icon' => 'Access', 'label' => 'Access', 'title' => 'Download Controls', 'body' => '{downloads_access_summary}'],
                            ['id' => 'onboarding_security', 'icon' => 'Security', 'label' => 'Security', 'title' => 'Onboarding Security', 'body' => '{verification_summary}'],
                            ['id' => 'creator_monetization', 'icon' => 'Rewards', 'label' => 'Rewards', 'title' => 'Creator Monetization', 'body' => '{creator_feature_summary}'],
                            ['id' => 'api_tokens', 'icon' => 'API', 'label' => 'API', 'title' => 'Public API and Tokens', 'body' => 'Personal API tokens, managed upload flows, multipart session control, file metadata access, and application-signed download links are built into the file host.'],
                            ['id' => 'multipart', 'icon' => 'Multipart', 'label' => 'Multipart', 'title' => 'Large-File Upload Path', 'body' => 'Fyuhls supports resumable multipart upload sessions for object storage, so larger installs can move file bytes directly to storage instead of routing everything through PHP.'],
                            ['id' => 'fraud', 'icon' => 'Fraud', 'label' => 'Fraud', 'title' => 'Rewards Fraud Review', 'body' => 'When rewards are enabled, admins can hold earnings, inspect suspicious traffic, and review uploader or network risk signals from a dedicated fraud console.'],
                            ['id' => 'ops', 'icon' => 'Ops', 'label' => 'Ops', 'title' => 'Live Operations', 'body' => 'Admins can monitor current downloads, review system status, export sanitized support bundles, and manage storage or delivery behavior without leaving the control surface.'],
                        ],
                    ],
                    'quick_faq_section' => [
                        'label' => 'Quick FAQ section',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Section title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Section intro'],
                        ],
                        'default' => [
                            'title' => 'Quick answers before someone signs up',
                            'intro' => 'Quick answers about accounts, downloads, rewards, and how the service works.',
                        ],
                    ],
                    'quick_faq_cards' => [
                        'label' => 'Quick FAQ cards',
                        'type' => 'list',
                        'item_label' => 'FAQ card',
                        'item_fields' => [
                            'id' => ['type' => 'hidden'],
                            'question' => ['type' => 'text', 'label' => 'Question'],
                            'answer' => ['type' => 'markdown', 'label' => 'Answer'],
                        ],
                        'default' => [
                            ['id' => 'downloads', 'question' => 'Do downloads require an account?', 'answer' => '{downloads_require_account_answer}'],
                            ['id' => 'registration', 'question' => 'Can users register right now?', 'answer' => '{registration_answer}'],
                            ['id' => 'rewards', 'question' => 'Are creator rewards enabled?', 'answer' => '{rewards_answer}'],
                            ['id' => 'tiers', 'question' => 'What kind of account levels are available?', 'answer' => '{tiers_answer}'],
                        ],
                    ],
                    'pricing_section' => [
                        'label' => 'Pricing section',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Section title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Section intro'],
                        ],
                        'default' => [
                            'title' => 'Account Levels',
                            'intro' => 'The plan cards below are generated from the current live package configuration, so visitors see the same file limits, upgrade path, and download experience the site is actually offering.',
                        ],
                    ],
                    'conversion_cta' => [
                        'label' => 'Final signup call to action',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Section title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Section intro'],
                            'primary_open_label' => ['type' => 'text', 'label' => 'Primary button label (registrations open)'],
                            'primary_closed_label' => ['type' => 'text', 'label' => 'Primary button label (registrations closed)'],
                            'secondary_label' => ['type' => 'text', 'label' => 'Secondary button label'],
                        ],
                        'default' => [
                            'title' => 'Ready to start?',
                            'intro' => 'Create an account to manage your files, compare live plan limits, and move into larger account tiers whenever you need more room.',
                            'primary_open_label' => 'Create Account',
                            'primary_closed_label' => 'Login',
                            'secondary_label' => 'Read FAQ',
                        ],
                    ],
                ],
            ],
            'faq' => [
                'label' => 'FAQ Page',
                'route' => '/faq',
                'template' => 'home/faq.php',
                'admin_enabled' => true,
                'blocks' => [
                    'header' => [
                        'label' => 'Page header',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Page title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Intro copy'],
                        ],
                        'default' => [
                            'title' => 'Frequently Asked Questions',
                            'intro' => 'Find answers about uploads, downloads, accounts, storage limits, rewards, and how the service works.',
                        ],
                    ],
                    'items' => [
                        'label' => 'FAQ items',
                        'type' => 'list',
                        'item_label' => 'FAQ item',
                        'item_fields' => [
                            'id' => ['type' => 'hidden'],
                            'category' => [
                                'type' => 'select',
                                'label' => 'Category',
                                'options' => [
                                    'uploads' => 'Uploads',
                                    'downloads' => 'Downloads',
                                    'accounts' => 'Accounts',
                                    'billing' => 'Plans & Billing',
                                    'creator_rewards' => 'Creator Rewards',
                                    'safety' => 'Privacy & Abuse',
                                    'api' => 'API',
                                ],
                            ],
                            'question' => ['type' => 'text', 'label' => 'Question'],
                            'answer' => ['type' => 'markdown', 'label' => 'Answer'],
                        ],
                        'default' => [
                            ['id' => 'upload_limit', 'category' => 'uploads', 'question' => 'Is there a limit to the file size I can upload?', 'answer' => 'Upload limits depend on the package assigned to your account. {faq_upload_limits_summary}'],
                            ['id' => 'storage_limit', 'category' => 'uploads', 'question' => 'Is there a limit to the storage space I can use?', 'answer' => 'Total storage depends on your package. {faq_storage_summary}'],
                            ['id' => 'large_uploads', 'category' => 'uploads', 'question' => 'Does this site support large-file uploads?', 'answer' => 'Yes. Large uploads are supported through package-based limits, and bigger transfers can use multipart-friendly upload handling when storage is set up for it.'],
                            ['id' => 'remote_uploads', 'category' => 'uploads', 'question' => 'Can I import files from a remote URL?', 'answer' => '{faq_remote_upload_answer}'],
                            ['id' => 'download_access', 'category' => 'downloads', 'question' => 'Do downloads have access restrictions?', 'answer' => '{faq_download_access_answer}'],
                            ['id' => 'speed', 'category' => 'downloads', 'question' => 'Are there speed or bandwidth limits?', 'answer' => 'Download speed and daily transfer limits vary by package. {faq_speed_summary}'],
                            ['id' => 'retention', 'category' => 'downloads', 'question' => 'How long are my files stored?', 'answer' => 'Retention is based on package rules and is measured from the last download date. {faq_retention_summary}'],
                            ['id' => 'sharing', 'category' => 'downloads', 'question' => 'Can I share my files with others?', 'answer' => 'Yes. Each uploaded file can generate its own shareable link. Visibility and direct-link behavior still depend on the package and site rules set by the admin.'],
                            ['id' => 'account_upgrade', 'category' => 'accounts', 'question' => 'How do I create or upgrade an account?', 'answer' => '{faq_upgrade_answer}'],
                            ['id' => 'email_verification', 'category' => 'accounts', 'question' => 'Do I need to verify my email address?', 'answer' => '{faq_verification_answer}'],
                            ['id' => 'private_files', 'category' => 'accounts', 'question' => 'Can I keep files private?', 'answer' => '{faq_private_files_answer}'],
                            ['id' => 'delete_restore', 'category' => 'accounts', 'question' => 'What happens when I delete a file?', 'answer' => '{faq_delete_restore_answer}'],
                            ['id' => 'plan_changes', 'category' => 'billing', 'question' => 'What changes when I move to a different plan?', 'answer' => '{faq_plan_changes_answer}'],
                            ['id' => 'rewards_qualify', 'category' => 'creator_rewards', 'question' => 'How do creator rewards qualify?', 'answer' => '{faq_rewards_qualification_answer}'],
                            ['id' => 'payout_timing', 'category' => 'creator_rewards', 'question' => 'When are earnings ready for payout?', 'answer' => '{faq_payout_timing_answer}'],
                            ['id' => 'content_removal', 'category' => 'safety', 'question' => 'What happens if a file is reported for abuse or DMCA?', 'answer' => '{faq_content_removal_answer}'],
                            ['id' => 'api', 'category' => 'api', 'question' => 'Does the platform include an API?', 'answer' => 'Yes. The service includes a public API with personal API tokens, multipart upload session support, managed upload shortcuts, file metadata access, and application-controlled download links.'],
                        ],
                    ],
                    'cta' => [
                        'label' => 'Closing call to action',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'CTA title'],
                            'body' => ['type' => 'markdown', 'label' => 'CTA body'],
                            'button_label' => ['type' => 'text', 'label' => 'Button label'],
                        ],
                        'default' => [
                            'title' => 'Still have questions?',
                            'body' => 'If you are unsure which package or rule applies to your account, contact support directly.',
                            'button_label' => 'Contact Support',
                        ],
                    ],
                ],
            ],
            'affiliate' => [
                'label' => 'Affiliate Page',
                'route' => '/affiliate',
                'template' => 'home/affiliate.php',
                'admin_enabled' => true,
                'blocks' => [
                    'header' => [
                        'label' => 'Header copy',
                        'type' => 'object',
                        'fields' => [
                            'toolbar_enabled' => ['type' => 'markdown', 'label' => 'Toolbar note (affiliate enabled)'],
                            'toolbar_disabled' => ['type' => 'markdown', 'label' => 'Toolbar note (affiliate disabled)'],
                            'hero_title' => ['type' => 'text', 'label' => 'Hero title'],
                            'hero_intro_enabled' => ['type' => 'markdown', 'label' => 'Hero intro (affiliate enabled)'],
                            'hero_intro_disabled' => ['type' => 'markdown', 'label' => 'Hero intro (affiliate disabled)'],
                        ],
                        'default' => [
                            'toolbar_enabled' => 'Choose your earning model, review current PPD rates, and manage the referral link tied to your account.',
                            'toolbar_disabled' => 'Choose your earning model, review current PPD rates, and track the rewards tools available on your account.',
                            'hero_title' => 'Earn with {site_name}',
                            'hero_intro_enabled' => 'Choose the reward model that fits your traffic, track earnings clearly, and share your referral link when referrals are available.',
                            'hero_intro_disabled' => 'Choose the reward model that fits your traffic, review current payout rates, and track the earning tools available on your account.',
                        ],
                    ],
                    'models_section' => [
                        'label' => 'Earning models section',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Section title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Section intro'],
                        ],
                        'default' => [
                            'title' => 'Choose how you want to earn',
                            'intro' => 'Pick the reward model that best matches your traffic. You can switch later if your audience or conversion style changes.',
                        ],
                    ],
                    'mixed_program' => [
                        'label' => 'Hybrid program card',
                        'type' => 'object',
                        'fields' => [
                            'badge_default' => ['type' => 'text', 'label' => 'Default badge'],
                            'badge_current' => ['type' => 'text', 'label' => 'Current badge'],
                            'title' => ['type' => 'text', 'label' => 'Card title'],
                            'body' => ['type' => 'markdown', 'label' => 'Card body'],
                        ],
                        'default' => [
                            'badge_default' => 'Hybrid Model',
                            'badge_current' => 'Your Current Model',
                            'title' => 'PPD + PPS Hybrid',
                            'body' => "Combine both reward types.\n\n- **{mixed_ppd_percent}%** of PPD tier rates\n- **{mixed_pps_percent}%** of premium sales\n- **{referral_commission_percent}%** referral commission when referral signups are enabled\n- Useful when your traffic includes both download-heavy and conversion-heavy sources",
                        ],
                    ],
                    'ppd_program' => [
                        'label' => 'PPD program card',
                        'type' => 'object',
                        'fields' => [
                            'badge_default' => ['type' => 'text', 'label' => 'Default badge'],
                            'badge_current' => ['type' => 'text', 'label' => 'Current badge'],
                            'title' => ['type' => 'text', 'label' => 'Card title'],
                            'body' => ['type' => 'markdown', 'label' => 'Card body'],
                        ],
                        'default' => [
                            'badge_default' => 'PPD Program',
                            'badge_current' => 'Your Current Model',
                            'title' => 'Pay Per Download',
                            'body' => "Earn from qualifying downloads based on the current geographic rate tiers.\n\n- Rates come from the current tier table below\n- Qualification depends on IP, file size, and progress rules\n- Use your rewards dashboard to review cleared balances and payout requests",
                        ],
                    ],
                    'pps_program' => [
                        'label' => 'PPS program card',
                        'type' => 'object',
                        'fields' => [
                            'badge_default' => ['type' => 'text', 'label' => 'Default badge'],
                            'badge_current' => ['type' => 'text', 'label' => 'Current badge'],
                            'title' => ['type' => 'text', 'label' => 'Card title'],
                            'body' => ['type' => 'markdown', 'label' => 'Card body'],
                        ],
                        'default' => [
                            'badge_default' => 'PPS Program',
                            'badge_current' => 'Your Current Model',
                            'title' => 'Pay Per Sale',
                            'body' => "Earn when premium purchases are attributed to your files and download flow.\n\n- **{pps_commission_percent}%** of premium sales\n- **{referral_commission_percent}%** referral commission when referral signups are enabled\n- Best for conversion-heavy traffic where your files lead directly to premium purchases",
                        ],
                    ],
                    'tier_section' => [
                        'label' => 'Tier table section',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Section title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Section intro'],
                            'empty_state' => ['type' => 'text', 'label' => 'Empty state'],
                        ],
                        'default' => [
                            'title' => 'Current PPD Tier Rates',
                            'intro' => 'Rates vary by visitor country group and apply to qualifying downloads.',
                            'empty_state' => 'No PPD tiers are available right now.',
                        ],
                    ],
                    'guidance_cards' => [
                        'label' => 'Guidance cards',
                        'type' => 'list',
                        'item_label' => 'Guidance card',
                        'item_fields' => [
                            'id' => ['type' => 'hidden'],
                            'title' => ['type' => 'text', 'label' => 'Card title'],
                            'body' => ['type' => 'markdown', 'label' => 'Card body'],
                        ],
                        'default' => [
                            ['id' => 'qualify', 'title' => 'How earnings qualify', 'body' => "Qualifying activity depends on the reward model you choose.\n\n- Downloads must pass fraud and verification checks\n- PPD activity can depend on IP, file size, and progress rules\n- Cleared earnings become available for payout requests"],
                            ['id' => 'referrals', 'title' => 'Referral program', 'body' => "Referral commissions are separate from your reward model.\n\n- Share your referral link to credit new signups to your account\n- Referral commissions follow the current site rules\n- If referrals are off, you can still earn through the reward models above"],
                            ['id' => 'payouts', 'title' => 'Getting paid', 'body' => "Use your account dashboard to review cleared earnings and request payouts.\n\n- Cleared balances and payout requests are tracked from your account area\n- Keep your payout details up to date in settings\n- Use support if you need help with missing credit or payout questions"],
                        ],
                    ],
                    'guest_cta' => [
                        'label' => 'Guest call to action',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'CTA title'],
                            'body_enabled' => ['type' => 'markdown', 'label' => 'CTA body (affiliate enabled)'],
                            'body_disabled' => ['type' => 'markdown', 'label' => 'CTA body (affiliate disabled)'],
                            'button_label' => ['type' => 'text', 'label' => 'Button label'],
                        ],
                        'default' => [
                            'title' => 'Create an account to start earning',
                            'body_enabled' => 'Create an account to unlock rewards, referral tools, payout requests, and your earnings dashboard.',
                            'body_disabled' => 'Create an account to unlock rewards, payout requests, and your earnings dashboard.',
                            'button_label' => 'Create My Account',
                        ],
                    ],
                    'member_cta' => [
                        'label' => 'Signed-in call to action',
                        'type' => 'object',
                        'fields' => [
                            'referral_title' => ['type' => 'text', 'label' => 'Referral title'],
                            'referral_body' => ['type' => 'markdown', 'label' => 'Referral body'],
                            'copy_button_label' => ['type' => 'text', 'label' => 'Copy button label'],
                            'referrals_off_title' => ['type' => 'text', 'label' => 'Referrals disabled title'],
                            'referrals_off_body' => ['type' => 'markdown', 'label' => 'Referrals disabled body'],
                            'referrals_off_button' => ['type' => 'text', 'label' => 'Referrals disabled button'],
                        ],
                        'default' => [
                            'referral_title' => 'Your referral link',
                            'referral_body' => 'Share this link when you want signups credited to your account. When referred users earn through rewards, your referral commission follows the current site rules.',
                            'copy_button_label' => 'Copy',
                            'referrals_off_title' => 'Referral commissions are currently off',
                            'referrals_off_body' => 'Rewards are still available, but referral signups and referral commissions are currently disabled.',
                            'referrals_off_button' => 'Open Rewards Dashboard',
                        ],
                    ],
                ],
            ],
            'api' => [
                'label' => 'API Page',
                'route' => '/api',
                'template' => 'home/api.php',
                'admin_enabled' => true,
                'blocks' => [
                    'hero' => [
                        'label' => 'Hero copy',
                        'type' => 'object',
                        'fields' => [
                            'eyebrow' => ['type' => 'text', 'label' => 'Eyebrow'],
                            'title' => ['type' => 'text', 'label' => 'Title'],
                            'lead' => ['type' => 'markdown', 'label' => 'Lead copy'],
                        ],
                        'default' => [
                            'eyebrow' => 'Developer API',
                            'title' => '{site_name} API Reference',
                            'lead' => 'The current `/api/v1/` API is built for account-based integrations. It supports personal API tokens, direct-to-storage multipart uploads, managed upload shortcuts for desktop tools, owner-scoped file metadata, and application-controlled download links.',
                        ],
                    ],
                    'overview' => [
                        'label' => 'Overview copy',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Section title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Intro copy'],
                            'openapi_note' => ['type' => 'markdown', 'label' => 'OpenAPI note'],
                            'delivery_note' => ['type' => 'markdown', 'label' => 'Delivery note'],
                        ],
                        'default' => [
                            'title' => 'Overview',
                            'intro' => 'This API is designed for real integrations, not just browser calls. Every API token belongs to a specific user account, so uploads, quotas, folders, visibility rules, and package limits all run in that user context.',
                            'openapi_note' => 'OpenAPI discovery is available at `/api/v1/openapi.json` for clients that want to generate their own request wrappers.',
                            'delivery_note' => 'Uploads go directly to storage, while download links stay application-controlled. Your client requests a signed link from `{site_name}`, and the service handles delivery based on the user package, transfer rules, and protection checks in place.',
                        ],
                    ],
                    'auth' => [
                        'label' => 'Authentication copy',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Section title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Intro copy'],
                            'tool_note' => ['type' => 'markdown', 'label' => 'Uploader tool note'],
                        ],
                        'default' => [
                            'title' => 'Authentication',
                            'intro' => 'Third-party tools should use personal API tokens. Browser-session calls still work for the site itself, but tokens are the intended public integration method.',
                            'tool_note' => 'If someone is using a third-party uploader, they should log into their account, open `/settings`, create a personal API token with the needed scopes, and paste that token into the tool. They should not use their account password or browser cookies.',
                        ],
                    ],
                ],
            ],
            'contact' => [
                'label' => 'Contact Page',
                'route' => '/contact',
                'template' => 'home/contact.php',
                'admin_enabled' => true,
                'blocks' => [
                    'page' => [
                        'label' => 'Page copy',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Page title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Intro copy'],
                            'submit_label' => ['type' => 'text', 'label' => 'Submit button label'],
                        ],
                        'default' => [
                            'title' => 'Contact Us',
                            'intro' => 'Have a question or feedback? We\'d love to hear from you.',
                            'submit_label' => 'Send Message',
                        ],
                    ],
                    'fields' => [
                        'label' => 'Form labels',
                        'type' => 'object',
                        'fields' => [
                            'name_label' => ['type' => 'text', 'label' => 'Name field label'],
                            'email_label' => ['type' => 'text', 'label' => 'Email field label'],
                            'subject_label' => ['type' => 'text', 'label' => 'Subject field label'],
                            'message_label' => ['type' => 'text', 'label' => 'Message field label'],
                        ],
                        'default' => [
                            'name_label' => 'Your Name',
                            'email_label' => 'Email Address',
                            'subject_label' => 'Subject',
                            'message_label' => 'Message',
                        ],
                    ],
                ],
            ],
            'dmca' => [
                'label' => 'DMCA Page',
                'route' => '/dmca',
                'template' => 'home/dmca.php',
                'admin_enabled' => true,
                'blocks' => [
                    'page' => [
                        'label' => 'Page copy',
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'text', 'label' => 'Page title'],
                            'intro' => ['type' => 'markdown', 'label' => 'Intro copy'],
                            'submit_label' => ['type' => 'text', 'label' => 'Submit button label'],
                        ],
                        'default' => [
                            'title' => 'DMCA Takedown Notice',
                            'intro' => 'If you believe that your copyrighted work has been infringed, please complete the form below.',
                            'submit_label' => 'Submit Takedown Notice',
                        ],
                    ],
                    'fields' => [
                        'label' => 'Form labels and help',
                        'type' => 'object',
                        'fields' => [
                            'name_label' => ['type' => 'text', 'label' => 'Name field label'],
                            'email_label' => ['type' => 'text', 'label' => 'Email field label'],
                            'url_label' => ['type' => 'text', 'label' => 'URL field label'],
                            'url_help' => ['type' => 'markdown', 'label' => 'URL help text'],
                            'description_label' => ['type' => 'text', 'label' => 'Description field label'],
                            'signature_label' => ['type' => 'text', 'label' => 'Signature field label'],
                            'signature_help' => ['type' => 'markdown', 'label' => 'Signature help text'],
                            'confirmation_title' => ['type' => 'text', 'label' => 'Confirmation title'],
                            'confirmation_body' => ['type' => 'markdown', 'label' => 'Confirmation body'],
                        ],
                        'default' => [
                            'name_label' => 'Full Name',
                            'email_label' => 'Email Address',
                            'url_label' => 'Infringing URL(s)',
                            'url_help' => 'Paste one or more URLs here. You can paste a block of links and fyuhls will sort them into a one-link-per-line list.',
                            'description_label' => 'Detailed Description',
                            'signature_label' => 'Electronic Signature',
                            'signature_help' => 'Typing your full name here acts as your digital signature.',
                            'confirmation_title' => 'Confirmation:',
                            'confirmation_body' => 'I have a good faith belief that the use of the material is not authorized by the copyright owner, its agent, or the law.',
                        ],
                    ],
                ],
            ],
            'footer' => [
                'label' => 'Footer',
                'route' => '/',
                'template' => 'home/footer.php',
                'admin_enabled' => true,
                'blocks' => [
                    'brand' => [
                        'label' => 'Footer text',
                        'type' => 'object',
                        'fields' => [
                            'tagline' => ['type' => 'text', 'label' => 'Footer tagline'],
                        ],
                        'default' => [
                            'tagline' => 'Secure Cloud Storage',
                        ],
                    ],
                    'custom_links' => [
                        'label' => 'Custom footer links',
                        'type' => 'list',
                        'item_label' => 'Custom link',
                        'item_fields' => [
                            'id' => ['type' => 'hidden'],
                            'label' => ['type' => 'text', 'label' => 'Link label'],
                            'url' => ['type' => 'url', 'label' => 'URL'],
                            'target' => [
                                'type' => 'select',
                                'label' => 'Open behavior',
                                'options' => [
                                    '_self' => 'Open in current tab',
                                    '_blank' => 'Open in new tab',
                                ],
                            ],
                        ],
                        'default' => [],
                    ],
                ],
            ],
        ];
    }

    public static function page(string $pageKey, ?string $locale = null): array
    {
        $definitions = self::definitions();
        if (!isset($definitions[$pageKey])) {
            return [];
        }

        $locale = self::normalizeLocale($locale);
        $page = self::currentSnapshot($pageKey, $locale);
        $previewToken = self::previewTokenFromRequest();
        if ($previewToken !== null) {
            $previewPayload = self::resolvePreviewToken($pageKey, $previewToken, Auth::id(), $locale);
            if ($previewPayload !== null) {
                $page = self::mergeSnapshotWithDefaults($pageKey, $previewPayload);
            }
        }

        return $page;
    }

    public static function currentSnapshot(string $pageKey, ?string $locale = null): array
    {
        $locale = self::normalizeLocale($locale);
        $defs = self::definitions();
        if (!isset($defs[$pageKey])) {
            return [];
        }

        $page = [];
        foreach ($defs[$pageKey]['blocks'] as $blockKey => $blockDef) {
            $page[$blockKey] = self::defaultBlockValue($blockDef);
        }

        foreach (self::fetchStoredRows($pageKey, $locale) as $row) {
            $blockKey = (string)($row['block_key'] ?? '');
            if ($blockKey === '' || !isset($defs[$pageKey]['blocks'][$blockKey])) {
                continue;
            }

            $decoded = json_decode((string)$row['content_json'], true);
            if (!is_array($decoded)) {
                continue;
            }

            $page[$blockKey] = self::mergeBlockValue($defs[$pageKey]['blocks'][$blockKey], $decoded);
        }

        return $page;
    }

    public static function defaultSnapshot(string $pageKey): array
    {
        return self::mergeSnapshotWithDefaults($pageKey, []);
    }

    public static function pageDefinition(string $pageKey): ?array
    {
        $defs = self::definitions();
        return $defs[$pageKey] ?? null;
    }

    public static function savePage(string $pageKey, array $input, int $adminId, ?string $locale = null, string $reason = 'save', ?int $restoredFromRevisionId = null): void
    {
        self::ensureSchema();
        $defs = self::definitions();
        if (!isset($defs[$pageKey])) {
            throw new \RuntimeException('Unknown content page.');
        }

        $locale = self::normalizeLocale($locale);
        $snapshot = self::normalizePagePayload($pageKey, $input);

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            self::savePageSnapshotWithConnection($pdo, $pageKey, $snapshot, $adminId, $locale, $reason, $restoredFromRevisionId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function savePageSnapshotWithConnection(\PDO $pdo, string $pageKey, array $snapshot, int $adminId, string $locale, string $reason = 'save', ?int $restoredFromRevisionId = null): void
    {
        $defs = self::definitions();
        foreach ($defs[$pageKey]['blocks'] as $blockKey => $blockDef) {
            $contentType = $blockDef['type'] === 'list' ? 'list' : 'object';
            $contentJson = json_encode($snapshot[$blockKey], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = $pdo->prepare("
                INSERT INTO site_content (page_key, block_key, locale, content_type, content_json, updated_by)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE content_type = VALUES(content_type), content_json = VALUES(content_json), updated_by = VALUES(updated_by)
            ");
            $stmt->execute([$pageKey, $blockKey, $locale, $contentType, $contentJson, $adminId]);
        }

        $revisionStmt = $pdo->prepare("
            INSERT INTO site_content_revisions (page_key, locale, snapshot_json, change_reason, restored_from_revision_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $revisionStmt->execute([
            $pageKey,
            $locale,
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $reason,
            $restoredFromRevisionId,
            $adminId,
        ]);

        $pruneStmt = $pdo->prepare("
            DELETE FROM site_content_revisions
            WHERE page_key = ? AND locale = ?
              AND id NOT IN (
                  SELECT id FROM (
                      SELECT id
                      FROM site_content_revisions
                      WHERE page_key = ? AND locale = ?
                      ORDER BY created_at DESC, id DESC
                      LIMIT " . self::MAX_REVISIONS_PER_PAGE_LOCALE . "
                  ) AS retained_revisions
              )
        ");
        $pruneStmt->execute([$pageKey, $locale, $pageKey, $locale]);
    }

    public static function createPreviewToken(string $pageKey, array $input, int $adminId, ?string $locale = null): string
    {
        self::ensureSchema();
        $defs = self::definitions();
        if (!isset($defs[$pageKey])) {
            throw new \RuntimeException('Unknown preview page.');
        }

        $locale = self::normalizeLocale($locale);
        $payload = self::normalizePagePayload($pageKey, $input);
        $rawToken = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + self::PREVIEW_TTL_SECONDS);

        $pdo = Database::getInstance()->getConnection();
        $cleanup = $pdo->prepare("DELETE FROM site_content_preview_tokens WHERE page_key = ? AND created_by = ? AND expires_at < NOW()");
        $cleanup->execute([$pageKey, $adminId]);

        $stmt = $pdo->prepare("
            INSERT INTO site_content_preview_tokens (token_hash, page_key, locale, payload_json, created_by, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $tokenHash,
            $pageKey,
            $locale,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $adminId,
            $expiresAt,
        ]);

        return $rawToken;
    }

    public static function buildPreviewUrl(string $pageKey, string $rawToken, ?string $locale = null): string
    {
        $definition = self::pageDefinition($pageKey);
        $route = (string)($definition['route'] ?? '/');
        $params = [self::PREVIEW_QUERY_KEY => $rawToken];
        $locale = self::normalizeLocale($locale);
        if ($locale !== self::DEFAULT_LOCALE) {
            $params['locale'] = $locale;
        }
        $separator = str_contains($route, '?') ? '&' : '?';
        return $route . $separator . http_build_query($params);
    }

    public static function previewTokenFromRequest(): ?string
    {
        $token = trim((string)($_GET[self::PREVIEW_QUERY_KEY] ?? ''));
        return $token !== '' ? $token : null;
    }

    public static function previewIsActiveForPage(string $pageKey, ?string $locale = null): bool
    {
        $token = self::previewTokenFromRequest();
        if ($token === null) {
            return false;
        }

        return self::resolvePreviewToken($pageKey, $token, Auth::id(), self::normalizeLocale($locale)) !== null;
    }

    public static function previewHeadHtml(string $pageKey, ?string $locale = null): string
    {
        if (!self::previewIsActiveForPage($pageKey, $locale)) {
            return '';
        }

        return '<meta name="robots" content="noindex,nofollow,noarchive">' . "\n"
            . '<meta name="googlebot" content="noindex,nofollow,noarchive">';
    }

    public static function getRevisions(string $pageKey, ?string $locale = null, int $limit = 20): array
    {
        self::ensureSchema();
        $locale = self::normalizeLocale($locale);
        $limit = max(1, min(100, $limit));
        try {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("
                SELECT r.*, u.username
                FROM site_content_revisions r
                LEFT JOIN users u ON u.id = r.created_by
                WHERE r.page_key = ? AND r.locale = ?
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([$pageKey, $locale]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['username'] = isset($row['username']) ? EncryptionService::decrypt((string)$row['username']) : null;
            $snapshot = json_decode((string)($row['snapshot_json'] ?? ''), true);
            $row['summary'] = is_array($snapshot) ? self::summarizeSnapshot($pageKey, $snapshot) : [
                'block_count' => 0,
                'list_item_count' => 0,
                'non_empty_field_count' => 0,
            ];
        }

        return $rows;
    }

    public static function getRevisionDetails(int $revisionId, ?string $expectedPageKey = null, ?string $expectedLocale = null): ?array
    {
        self::ensureSchema();
        try {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("
                SELECT r.*, u.username
                FROM site_content_revisions r
                LEFT JOIN users u ON u.id = r.created_by
                WHERE r.id = ?
                LIMIT 1
            ");
            $stmt->execute([$revisionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        $pageKey = (string)($row['page_key'] ?? '');
        $locale = self::normalizeLocale((string)($row['locale'] ?? self::DEFAULT_LOCALE));
        if ($expectedPageKey !== null && $pageKey !== $expectedPageKey) {
            return null;
        }
        if ($expectedLocale !== null && $locale !== self::normalizeLocale($expectedLocale)) {
            return null;
        }

        $row['username'] = isset($row['username']) ? EncryptionService::decrypt((string)$row['username']) : null;
        $snapshot = json_decode((string)($row['snapshot_json'] ?? ''), true);
        if (!is_array($snapshot)) {
            return null;
        }
        $snapshot = self::mergeSnapshotWithDefaults($pageKey, $snapshot);
        $current = self::currentSnapshot($pageKey, $locale);
        $row['snapshot'] = $snapshot;
        $row['summary'] = self::summarizeSnapshot($pageKey, $snapshot);
        $row['diff_against_current'] = self::diffSnapshots($pageKey, $snapshot, $current);
        return $row;
    }

    public static function restoreRevision(int $revisionId, int $adminId): array
    {
        self::ensureSchema();
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM site_content_revisions WHERE id = ? LIMIT 1");
        $stmt->execute([$revisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Revision not found.');
        }

        $pageKey = (string)$row['page_key'];
        $locale = self::normalizeLocale((string)($row['locale'] ?? self::DEFAULT_LOCALE));
        $snapshot = json_decode((string)$row['snapshot_json'], true);
        if (!is_array($snapshot)) {
            throw new \RuntimeException('Revision snapshot is invalid.');
        }

        self::savePage($pageKey, $snapshot, $adminId, $locale, 'restore', $revisionId);

        return [
            'page_key' => $pageKey,
            'locale' => $locale,
        ];
    }

    public static function exportAll(?string $locale = null): array
    {
        self::ensureSchema();
        $locale = self::normalizeLocale($locale);
        $pages = [];
        foreach (self::definitions() as $pageKey => $def) {
            $pages[$pageKey] = self::currentSnapshot($pageKey, $locale);
        }

        return [
            'schema_version' => self::EXPORT_SCHEMA_VERSION,
            'exported_at' => gmdate('c'),
            'locale' => $locale,
            'pages' => $pages,
        ];
    }

    public static function importAll(array $payload, int $adminId): array
    {
        self::ensureSchema();
        $schemaVersion = (string)($payload['schema_version'] ?? '');
        if ($schemaVersion !== self::EXPORT_SCHEMA_VERSION) {
            throw new \RuntimeException('Unsupported import schema version.');
        }

        $locale = self::normalizeLocale((string)($payload['locale'] ?? self::DEFAULT_LOCALE));
        $pages = $payload['pages'] ?? null;
        if (!is_array($pages)) {
            throw new \RuntimeException('Import payload does not contain any pages.');
        }

        $normalizedPages = [];
        foreach ($pages as $pageKey => $snapshot) {
            if (!isset(self::definitions()[$pageKey]) || !is_array($snapshot)) {
                continue;
            }
            $normalizedPages[(string)$pageKey] = self::normalizePagePayload((string)$pageKey, $snapshot);
        }

        $imported = [];
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            foreach ($normalizedPages as $pageKey => $snapshot) {
                self::savePageSnapshotWithConnection($pdo, $pageKey, $snapshot, $adminId, $locale, 'import');
                $imported[] = $pageKey;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'locale' => $locale,
            'pages' => $imported,
        ];
    }

    public static function summarizeSnapshot(string $pageKey, array $snapshot): array
    {
        $definition = self::pageDefinition($pageKey);
        $blocks = (array)($definition['blocks'] ?? []);
        $blockCount = 0;
        $listItemCount = 0;
        $nonEmptyFieldCount = 0;

        foreach ($blocks as $blockKey => $blockDef) {
            if (!array_key_exists($blockKey, $snapshot)) {
                continue;
            }
            $blockCount++;
            $blockValue = $snapshot[$blockKey];
            if (($blockDef['type'] ?? 'object') === 'list') {
                $items = is_array($blockValue) ? $blockValue : [];
                $listItemCount += count($items);
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    foreach (($blockDef['item_fields'] ?? []) as $fieldKey => $fieldDef) {
                        if ($fieldKey === 'id') {
                            continue;
                        }
                        if (trim((string)($item[$fieldKey] ?? '')) !== '') {
                            $nonEmptyFieldCount++;
                        }
                    }
                }
                continue;
            }

            foreach (($blockDef['fields'] ?? []) as $fieldKey => $fieldDef) {
                if (trim((string)($blockValue[$fieldKey] ?? '')) !== '') {
                    $nonEmptyFieldCount++;
                }
            }
        }

        return [
            'block_count' => $blockCount,
            'list_item_count' => $listItemCount,
            'non_empty_field_count' => $nonEmptyFieldCount,
        ];
    }

    public static function diffSnapshots(string $pageKey, array $fromSnapshot, array $toSnapshot): array
    {
        $definition = self::pageDefinition($pageKey);
        $blocks = (array)($definition['blocks'] ?? []);
        $diffs = [];

        foreach ($blocks as $blockKey => $blockDef) {
            $label = (string)($blockDef['label'] ?? ucfirst($blockKey));
            $from = $fromSnapshot[$blockKey] ?? self::defaultBlockValue($blockDef);
            $to = $toSnapshot[$blockKey] ?? self::defaultBlockValue($blockDef);

            if (($blockDef['type'] ?? 'object') === 'list') {
                $fromItems = is_array($from) ? $from : [];
                $toItems = is_array($to) ? $to : [];
                $fromIds = array_values(array_map(static fn(array $item): string => (string)($item['id'] ?? ''), array_filter($fromItems, 'is_array')));
                $toIds = array_values(array_map(static fn(array $item): string => (string)($item['id'] ?? ''), array_filter($toItems, 'is_array')));

                $added = array_values(array_diff($toIds, $fromIds));
                $removed = array_values(array_diff($fromIds, $toIds));
                $common = array_values(array_intersect($fromIds, $toIds));
                $changed = [];
                foreach ($common as $id) {
                    $fromItem = self::findListItemById($fromItems, $id);
                    $toItem = self::findListItemById($toItems, $id);
                    if ($fromItem !== $toItem) {
                        $changed[] = $id;
                    }
                }
                $reordered = $fromIds !== $toIds && $added === [] && $removed === [];
                if ($added !== [] || $removed !== [] || $changed !== [] || $reordered) {
                    $parts = [];
                    if ($added !== []) {
                        $parts[] = count($added) . ' added';
                    }
                    if ($removed !== []) {
                        $parts[] = count($removed) . ' removed';
                    }
                    if ($changed !== []) {
                        $parts[] = count($changed) . ' changed';
                    }
                    if ($reordered) {
                        $parts[] = 'reordered';
                    }
                    $diffs[] = [
                        'block_key' => $blockKey,
                        'label' => $label,
                        'summary' => implode(', ', $parts),
                    ];
                }
                continue;
            }

            $changedFields = [];
            foreach (($blockDef['fields'] ?? []) as $fieldKey => $fieldDef) {
                $left = (string)($from[$fieldKey] ?? '');
                $right = (string)($to[$fieldKey] ?? '');
                if ($left !== $right) {
                    $changedFields[] = (string)($fieldDef['label'] ?? $fieldKey);
                }
            }
            if ($changedFields !== []) {
                $diffs[] = [
                    'block_key' => $blockKey,
                    'label' => $label,
                    'summary' => implode(', ', $changedFields),
                ];
            }
        }

        return $diffs;
    }

    public static function renderMarkdown(string $markdown, array $context = []): string
    {
        $markdown = self::applyTokens($markdown, $context);
        $markdown = trim(str_replace(["\r\n", "\r"], "\n", $markdown));
        if ($markdown === '') {
            return '';
        }

        $lines = explode("\n", $markdown);
        $html = [];
        $inList = false;
        $listType = null;
        $inCode = false;
        $codeBuffer = [];
        $paragraph = [];
        $blockquote = [];

        $flushParagraph = static function () use (&$html, &$paragraph): void {
            if ($paragraph === []) {
                return;
            }
            $text = trim(implode(' ', $paragraph));
            if ($text !== '') {
                $html[] = '<p>' . SiteContentService::renderInlineMarkdown($text, []) . '</p>';
            }
            $paragraph = [];
        };

        $flushList = static function () use (&$html, &$inList, &$listType): void {
            if ($inList) {
                $html[] = '</' . $listType . '>';
                $inList = false;
                $listType = null;
            }
        };

        $flushBlockquote = static function () use (&$html, &$blockquote): void {
            if ($blockquote === []) {
                return;
            }
            $inner = implode("\n", $blockquote);
            $html[] = '<blockquote>' . SiteContentService::renderMarkdown($inner, []) . '</blockquote>';
            $blockquote = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^```/', $line) === 1) {
                $flushParagraph();
                $flushList();
                $flushBlockquote();
                if ($inCode) {
                    $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeBuffer), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                    $codeBuffer = [];
                    $inCode = false;
                } else {
                    $inCode = true;
                }
                continue;
            }

            if ($inCode) {
                $codeBuffer[] = $line;
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                $flushParagraph();
                $flushList();
                $flushBlockquote();
                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.+)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $flushList();
                $flushBlockquote();
                $level = min(3, strlen($m[1]));
                $html[] = '<h' . $level . '>' . self::renderInlineMarkdown($m[2], []) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $flushList();
                $blockquote[] = $m[1];
                continue;
            }

            if (preg_match('/^([-*])\s+(.+)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $flushBlockquote();
                if (!$inList || $listType !== 'ul') {
                    $flushList();
                    $html[] = '<ul>';
                    $inList = true;
                    $listType = 'ul';
                }
                $html[] = '<li>' . self::renderInlineMarkdown($m[2], []) . '</li>';
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $flushBlockquote();
                if (!$inList || $listType !== 'ol') {
                    $flushList();
                    $html[] = '<ol>';
                    $inList = true;
                    $listType = 'ol';
                }
                $html[] = '<li>' . self::renderInlineMarkdown($m[1], []) . '</li>';
                continue;
            }

            $flushList();
            $flushBlockquote();
            $paragraph[] = $trimmed;
        }

        if ($inCode) {
            $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeBuffer), ENT_QUOTES, 'UTF-8') . '</code></pre>';
        }

        $flushParagraph();
        $flushList();
        $flushBlockquote();

        return implode("\n", $html);
    }

    public static function renderInlineMarkdown(string $text, array $context = []): string
    {
        $text = self::applyTokens($text, $context);
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace_callback('/\[(.+?)\]\((.+?)\)/', static function (array $matches): string {
            $label = $matches[1];
            $url = html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8');
            $normalized = SiteContentService::normalizeAllowedUrl($url);
            if ($normalized === null) {
                return htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            }
            $target = str_starts_with($normalized, 'http') ? ' target="_blank" rel="noopener noreferrer"' : '';
            return '<a href="' . htmlspecialchars($normalized, ENT_QUOTES, 'UTF-8') . '"' . $target . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        }, $escaped) ?? $escaped;
        return $escaped;
    }

    public static function markdownHelpLines(): array
    {
        return [
            '# Heading',
            '## Smaller heading',
            '**bold** and *italic*',
            '- bullet list item',
            '1. numbered list item',
            '> blockquote',
            '`inline code` and fenced code blocks with ```',
            '[link text](https://example.com)',
        ];
    }

    public static function availableTokens(): array
    {
        return [
            '{site_name}',
            '{package_count}',
            '{free_package_name}',
            '{free_package_upload_limit}',
            '{free_package_storage_limit}',
            '{paid_package_name}',
            '{creator_summary}',
            '{tier_summary}',
            '{downloads_access_summary}',
            '{verification_summary}',
            '{creator_feature_summary}',
            '{downloads_require_account_answer}',
            '{registration_answer}',
            '{rewards_answer}',
            '{tiers_answer}',
            '{faq_upload_limits_summary}',
            '{faq_retention_summary}',
            '{faq_storage_summary}',
            '{faq_download_access_answer}',
            '{faq_speed_summary}',
            '{faq_upgrade_answer}',
        ];
    }

    public static function getThemeCompatibilityWarnings(): array
    {
        $warnings = [];
        $definitions = self::editablePages();
        $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $activeTheme = trim((string)Config::get('theme.name', ''));

        foreach ($definitions as $pageKey => $definition) {
            $template = (string)($definition['template'] ?? '');
            if ($template === '') {
                continue;
            }

            $paths = [$root . '/themes/custom/' . $template];
            if ($activeTheme !== '' && $activeTheme !== 'custom') {
                $paths[] = $root . '/themes/' . $activeTheme . '/views/' . $template;
            }

            foreach ($paths as $path) {
                if (!is_file($path)) {
                    continue;
                }

                $contents = @file_get_contents($path);
                if ($contents === false) {
                    continue;
                }

                if (!str_contains($contents, 'SiteContentService::page(')) {
                    $warnings[] = [
                        'page_key' => $pageKey,
                        'label' => $definition['label'],
                        'path' => $path,
                    ];
                }
            }
        }

        return $warnings;
    }

    private static function normalizePagePayload(string $pageKey, array $input): array
    {
        $defs = self::definitions();
        $pageDef = $defs[$pageKey] ?? null;
        if ($pageDef === null) {
            throw new \RuntimeException('Unknown content page.');
        }

        $normalized = [];
        foreach ($pageDef['blocks'] as $blockKey => $blockDef) {
            $blockInput = $input[$blockKey] ?? null;
            if (!is_array($blockInput)) {
                $blockInput = [];
            }

            if ($blockDef['type'] === 'list') {
                $items = [];
                foreach (array_values($blockInput) as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $normalizedItem = [];
                    foreach ($blockDef['item_fields'] as $fieldKey => $fieldDef) {
                        if ($fieldKey === 'id') {
                            $normalizedItem['id'] = self::normalizeItemId((string)($item['id'] ?? ''), $blockKey, $index);
                            continue;
                        }
                        $normalizedItem[$fieldKey] = self::normalizeFieldValue($item[$fieldKey] ?? '', $fieldDef);
                    }
                    if (self::itemHasVisibleContent($normalizedItem, (array)($blockDef['item_fields'] ?? []))) {
                        $items[] = $normalizedItem;
                    }
                }

                $normalized[$blockKey] = $items;
                continue;
            }

            $blockValue = [];
            foreach ($blockDef['fields'] as $fieldKey => $fieldDef) {
                $defaultValue = (string)($blockDef['default'][$fieldKey] ?? '');
                $raw = $blockInput[$fieldKey] ?? $defaultValue;
                $normalizedValue = self::normalizeFieldValue($raw, $fieldDef);
                if (($fieldDef['type'] ?? 'text') !== 'hidden' && trim((string)$normalizedValue) === '') {
                    $normalizedValue = $defaultValue;
                }
                $blockValue[$fieldKey] = $normalizedValue;
            }
            $normalized[$blockKey] = $blockValue;
        }

        return $normalized;
    }

    private static function normalizeFieldValue(mixed $value, array $fieldDef, bool $strict = true): mixed
    {
        $type = (string)($fieldDef['type'] ?? 'text');
        $string = trim(str_replace(["\r\n", "\r"], "\n", (string)$value));
        if ($type === 'markdown') {
            return $string;
        }
        if ($type === 'url') {
            if ($string === '') {
                return '';
            }
            $normalized = self::normalizeAllowedUrl($string);
            if ($normalized === null) {
                if ($strict) {
                    throw new \RuntimeException(sprintf('"%s" must be a relative /path, https:// URL, mailto:, or tel: link.', (string)($fieldDef['label'] ?? 'URL')));
                }
                return '';
            }
            return $normalized;
        }
        if ($type === 'select') {
            $options = $fieldDef['options'] ?? [];
            if (!is_array($options) || $options === []) {
                return $string;
            }
            if ($string !== '' && array_key_exists($string, $options)) {
                return $string;
            }
            return (string)array_key_first($options);
        }
        return $string;
    }

    private static function itemHasVisibleContent(array $item, array $fieldDefs = []): bool
    {
        foreach ($item as $key => $value) {
            if ($key === 'id') {
                continue;
            }
            $fieldType = (string)($fieldDefs[$key]['type'] ?? '');
            if ($fieldType === 'hidden' || $fieldType === 'select') {
                continue;
            }
            if (trim((string)$value) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function normalizeItemId(string $value, string $blockKey, int $index): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_-]/', '', $value) ?? '';
        if ($value !== '') {
            return $value;
        }
        return $blockKey . '_' . ($index + 1) . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private static function resolvePreviewToken(string $pageKey, string $rawToken, ?int $adminId, ?string $locale = null): ?array
    {
        self::ensureSchema();
        if ($adminId === null || $adminId <= 0 || !Auth::isAdmin()) {
            return null;
        }
        $locale = self::normalizeLocale($locale);

        try {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("
                SELECT payload_json
                FROM site_content_preview_tokens
                WHERE token_hash = ? AND page_key = ? AND locale = ? AND created_by = ? AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([hash('sha256', $rawToken), $pageKey, $locale, $adminId]);
            $payload = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private static function mergeSnapshotWithDefaults(string $pageKey, array $snapshot): array
    {
        $defs = self::definitions();
        if (!isset($defs[$pageKey])) {
            return [];
        }

        $merged = [];
        foreach ($defs[$pageKey]['blocks'] as $blockKey => $blockDef) {
            $default = self::defaultBlockValue($blockDef);
            $incoming = $snapshot[$blockKey] ?? $default;
            if (!is_array($incoming)) {
                $incoming = $default;
            }
            $merged[$blockKey] = self::mergeBlockValue($blockDef, $incoming);
        }

        return $merged;
    }

    private static function mergeBlockValue(array $blockDef, array $value): array
    {
        if (($blockDef['type'] ?? 'object') === 'list') {
            $items = [];
            $defaultItems = is_array($blockDef['default'] ?? null) ? $blockDef['default'] : [];
            foreach ($value as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemId = self::normalizeItemId((string)($item['id'] ?? ''), $blockDef['label'] ?? 'item', (int)$index);
                $defaultItem = self::findListItemById($defaultItems, $itemId);
                $row = [];
                foreach ($blockDef['item_fields'] as $fieldKey => $fieldDef) {
                    if ($fieldKey === 'id') {
                        $row['id'] = $itemId;
                        continue;
                    }
                    $fallback = $defaultItem[$fieldKey] ?? '';
                    $row[$fieldKey] = (string)self::normalizeFieldValue($item[$fieldKey] ?? $fallback, $fieldDef, false);
                }
                if (self::itemHasVisibleContent($row, (array)($blockDef['item_fields'] ?? []))) {
                    $items[] = $row;
                }
            }
            return $items;
        }

        $merged = [];
        foreach ($blockDef['fields'] as $fieldKey => $fieldDef) {
            $merged[$fieldKey] = (string)self::normalizeFieldValue($value[$fieldKey] ?? ($blockDef['default'][$fieldKey] ?? ''), $fieldDef, false);
        }
        return $merged;
    }

    private static function defaultBlockValue(array $blockDef): array
    {
        if (($blockDef['type'] ?? 'object') === 'list') {
            return $blockDef['default'] ?? [];
        }
        return $blockDef['default'] ?? [];
    }

    private static function fetchStoredRows(string $pageKey, string $locale): array
    {
        self::ensureSchema();
        try {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("SELECT block_key, content_json FROM site_content WHERE page_key = ? AND locale = ?");
            $stmt->execute([$pageKey, $locale]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function normalizeLocale(?string $locale): string
    {
        $locale = trim((string)$locale);
        if ($locale === '') {
            return self::DEFAULT_LOCALE;
        }
        return preg_match('/^[a-z]{2,5}(?:-[A-Z]{2})?$/', $locale) === 1 ? $locale : self::DEFAULT_LOCALE;
    }

    private static function findListItemById(array $items, string $id): array
    {
        foreach ($items as $item) {
            if (is_array($item) && (string)($item['id'] ?? '') === $id) {
                return $item;
            }
        }
        foreach (self::listItemIdAliases($id) as $legacyId) {
            foreach ($items as $item) {
                if (is_array($item) && (string)($item['id'] ?? '') === $legacyId) {
                    return $item;
                }
            }
        }
        return [];
    }

    private static function listItemIdAliases(string $id): array
    {
        return match ($id) {
            'account_upgrade' => ['upgrades'],
            'upgrades' => ['account_upgrade'],
            default => [],
        };
    }

    public static function normalizeAllowedUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return preg_match('~^/[A-Za-z0-9/_.?=&%+\-#]*$~', $url) === 1 ? $url : null;
        }

        if (preg_match('#^https://#i', $url) === 1) {
            return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
        }

        if (preg_match('#^(mailto:|tel:)#i', $url) === 1) {
            return preg_match('#^(mailto:[^\\s@]+@[^\\s@]+\\.[^\\s@]+|tel:\\+?[0-9()\\-\\s]+)$#i', $url) === 1 ? $url : null;
        }

        return null;
    }

    public static function tokenContext(): array
    {
        $siteName = Setting::getOrConfig('app.name', Config::get('app_name', 'Fyuhls'));
        $packages = array_values(array_filter(Package::getAll(), static fn(array $pkg): bool => ($pkg['level_type'] ?? '') !== 'admin'));
        $allowRegistrations = Setting::get('allow_registrations', '1') === '1';
        $requireVerification = Setting::get('require_email_verification', '0') === '1';
        $requireAccountToDownload = Setting::get('require_account_to_download', '0') === '1';
        $guestUploadsAllowed = Setting::get('upload_login_required', '0') !== '1';
        $rewardsEnabled = FeatureService::rewardsEnabled();
        $affiliateEnabled = FeatureService::affiliateEnabled();

        $guestPackage = null;
        $freePackage = null;
        $paidPackage = null;
        $supportsRemoteUpload = false;
        $hasPaidPlan = false;
        foreach ($packages as $pkg) {
            if (($pkg['level_type'] ?? '') === 'guest' && $guestPackage === null) {
                $guestPackage = $pkg;
            }
            if (($pkg['level_type'] ?? '') === 'free' && $freePackage === null) {
                $freePackage = $pkg;
            }
            if (($pkg['level_type'] ?? '') === 'paid' && $paidPackage === null) {
                $paidPackage = $pkg;
            }
            if (!empty($pkg['allow_remote_upload'])) {
                $supportsRemoteUpload = true;
            }
            if (($pkg['level_type'] ?? '') === 'paid') {
                $hasPaidPlan = true;
            }
        }

        $creatorSummary = $rewardsEnabled
            ? ($affiliateEnabled
                ? 'Eligible users can unlock both creator rewards and affiliate referrals from the same account area.'
                : 'Eligible users can unlock creator rewards alongside the site\'s upload and sharing tools.')
            : 'Share files with package-based rules, account controls, and a cleaner download workflow.';

        $tierSummary = $paidPackage
            ? ($paidPackage['name'] . ' is available for users who need more speed, larger upload limits, or fewer restrictions.')
            : 'Everything here is currently focused on the site\'s active access tiers without a paid upgrade step.';

        $downloadsAccessSummary = $requireAccountToDownload
            ? 'This site currently requires a registered account before downloads can begin.'
            : 'This site currently allows guest access to download pages, subject to package and security rules.';

        $verificationSummary = $requireVerification
            ? 'New accounts must confirm their email address before they can start using the platform.'
            : 'Account creation is streamlined right now because email verification is optional.';

        $creatorFeatureSummary = $rewardsEnabled
            ? ($affiliateEnabled
                ? 'Eligible users can access rewards and affiliate tools from their account area.'
                : 'Eligible users can access rewards and payout tools from their account area.')
            : 'Rewards are currently unavailable, so accounts focus on storage, file links, and downloads.';

        $planMixSummary = $hasPaidPlan
            ? 'This site currently offers **' . count($packages) . '** live account levels, including **' . (string)($paidPackage['name'] ?? 'the current paid tier') . '** for users who need more room, larger uploads, or fewer restrictions.'
            : 'This site currently offers **' . count($packages) . '** live account levels built around the active guest and free plan mix.';

        $remoteUploadOfferSummary = $supportsRemoteUpload
            ? 'Remote URL imports are available on supported plans, so users can fetch files directly into their account instead of re-uploading everything from a device.'
            : 'The current plan mix focuses on browser uploads right now, without remote URL imports enabled.';

        $apiOfferSummary = 'Personal API tokens, managed upload flows, multipart session control, and application-signed downloads are built into the file host.';

        $creatorOfferSummary = $rewardsEnabled
            ? ($affiliateEnabled
                ? 'Eligible users can access creator rewards and affiliate referrals from the same account area.'
                : 'Eligible users can access creator rewards and payout tracking from their account area.')
            : 'Creator rewards are currently off, so accounts stay focused on uploads, links, and delivery.';

        $memberSetupSummary = isset($freePackage['name'])
            ? 'The current **' . (string)$freePackage['name'] . '** plan starts with **' . self::formatBytes((int)($freePackage['max_upload_size'] ?? 0), true) . '** uploads and **' . self::formatBytes((int)($freePackage['max_storage_bytes'] ?? 0), true) . '** of storage.'
            : 'The current live account setup is driven by the package limits configured on this site.';

        $guestAccessSummary = $guestUploadsAllowed
            ? 'Guests can still use the public upload route before committing to a full account.'
            : 'Uploads currently begin from signed-in member accounts instead of guest sessions.';

        $uploadLimitSummary = [];
        $retentionSummary = [];
        $storageSummary = [];
        $speedSummary = [];
        foreach ($packages as $pkg) {
            $name = strtolower((string)($pkg['name'] ?? 'package'));
            $uploadLimitSummary[] = 'A ' . $name . ' account can upload files up to ' . self::formatBytes((int)($pkg['max_upload_size'] ?? 0), true) . '.';
            $days = (int)($pkg['file_expiry_days'] ?? 0);
            $retentionSummary[] = 'Files uploaded under ' . $name . ' are ' . ($days === 0 ? 'stored indefinitely' : "stored for {$days} days after the most recent download") . '.';
            $storageSummary[] = 'A ' . $name . ' account has ' . self::formatBytes((int)($pkg['max_storage_bytes'] ?? 0), true) . ' of storage.';
            $speedSummary[] = ucfirst((string)($pkg['name'] ?? 'Package')) . ' gets ' . self::formatSpeed((int)($pkg['download_speed'] ?? 0)) . ' with ' . self::formatDailyLimit((int)($pkg['max_daily_downloads'] ?? 0)) . '.';
        }

        return [
            'site_name' => $siteName,
            'package_count' => (string)count($packages),
            'free_package_name' => (string)($freePackage['name'] ?? 'Free'),
            'free_package_upload_limit' => self::formatBytes((int)($freePackage['max_upload_size'] ?? 0), true),
            'free_package_storage_limit' => self::formatBytes((int)($freePackage['max_storage_bytes'] ?? 0), true),
            'paid_package_name' => (string)($paidPackage['name'] ?? 'Premium'),
            'mixed_ppd_percent' => (string)Setting::get('mixed_ppd_percent', '30'),
            'mixed_pps_percent' => (string)Setting::get('mixed_pps_percent', '30'),
            'pps_commission_percent' => (string)Setting::get('pps_commission_percent', '50'),
            'referral_commission_percent' => (string)Setting::get('referral_commission_percent', '50'),
            'creator_summary' => $creatorSummary,
            'tier_summary' => $tierSummary,
            'plan_mix_summary' => $planMixSummary,
            'remote_upload_offer_summary' => $remoteUploadOfferSummary,
            'api_offer_summary' => $apiOfferSummary,
            'creator_offer_summary' => $creatorOfferSummary,
            'member_setup_summary' => $memberSetupSummary,
            'guest_access_summary' => $guestAccessSummary,
            'downloads_access_summary' => $downloadsAccessSummary,
            'verification_summary' => $verificationSummary,
            'creator_feature_summary' => $creatorFeatureSummary,
            'downloads_require_account_answer' => $requireAccountToDownload
                ? 'Yes. This site currently requires users to register and log in before downloading files.'
                : 'No. Guest downloads are currently allowed, although package and security rules can still apply.',
            'registration_answer' => $allowRegistrations
                ? 'Yes. New account registration is currently open.'
                : 'Not right now. Registration is currently closed by the administrator.',
            'rewards_answer' => $rewardsEnabled
                ? ($affiliateEnabled
                    ? 'Yes. Rewards and referral commissions are available for eligible users.'
                    : 'Yes. Rewards are available for eligible users, while referral commissions are currently off.')
                : 'Not right now. This site is currently focused on file hosting and sharing without reward payouts.',
            'tiers_answer' => $hasPaidPlan
                ? 'This site offers multiple account levels, including ' . (string)($paidPackage['name'] ?? 'the current premium plan') . ' as the current upgrade option.'
                : 'This site currently uses guest and free account levels without a paid upgrade option.',
            'faq_upload_limits_summary' => implode(' ', $uploadLimitSummary),
            'faq_retention_summary' => implode(' ', $retentionSummary),
            'faq_storage_summary' => implode(' ', $storageSummary),
            'faq_download_access_answer' => $requireAccountToDownload
                ? 'Yes. This site currently requires you to create an account and log in before a download can begin.'
                : 'Guest downloads are currently allowed, although package rules and security checks may still apply.',
            'faq_speed_summary' => implode(' ', $speedSummary),
            'faq_upgrade_answer' => $allowRegistrations
                ? 'You can register directly from the site. If a paid package is available, upgrade links appear on the pricing area and checkout pages.'
                : 'New registrations are currently closed. Existing users can still log in and use the site normally.',
            'faq_verification_answer' => $requireVerification
                ? 'Yes. New accounts need to confirm their email address before they can use the full account workflow.'
                : 'Not usually. This site currently keeps email verification optional unless the admin changes the security policy.',
            'faq_remote_upload_answer' => $supportsRemoteUpload
                ? 'Yes. Remote URL imports are available on supported packages, so you can fetch a file directly into your account without re-uploading it from your device.'
                : 'Not on the current plan mix. Right now uploads are handled through the browser or API instead of remote URL imports.',
            'faq_private_files_answer' => 'Yes. Files can stay private inside your account until you choose to share them. Public-link behavior still depends on package permissions and site rules.',
            'faq_delete_restore_answer' => 'Deleted files usually move to Trash first so you can restore them before they are cleared permanently. Admin removals for abuse or DMCA are tracked separately from your own Trash actions.',
            'faq_plan_changes_answer' => $hasPaidPlan
                ? 'Changing plans can affect storage, upload size, download speed, daily transfer limits, remote-upload access, and reward participation. The exact differences depend on the package you move into.'
                : 'The available plan levels control storage, upload size, and download behavior. If the site adds more packages later, those differences will appear on the plans page.',
            'faq_rewards_qualification_answer' => $rewardsEnabled
                ? 'Qualifying reward activity depends on the active reward model, anti-fraud checks, and the site hold rules. Downloads or sales may stay pending until they clear review.'
                : 'Creator rewards are not active right now, so downloads and shares do not generate reward earnings on this site.',
            'faq_payout_timing_answer' => $rewardsEnabled
                ? 'Earnings usually move through a hold or review window before they become withdrawable. Once they clear, you can track payout readiness from your rewards dashboard.'
                : 'Payout requests are only available when creator rewards are enabled.',
            'faq_content_removal_answer' => 'If a file is reported for abuse, copyright, or another policy issue, staff can remove it from public access or from the account entirely. Support can help explain moderation actions when needed.',
            'supports_remote_upload' => $supportsRemoteUpload ? '1' : '0',
            'guest_uploads_allowed' => $guestUploadsAllowed ? '1' : '0',
        ];
    }

    public static function applyTokens(string $value, array $context = []): string
    {
        $context = array_merge(self::tokenContext(), $context);
        return (string)preg_replace_callback('/\{[a-z0-9_]+\}/i', static function (array $matches) use ($context): string {
            $token = trim($matches[0], '{}');
            return array_key_exists($token, $context) ? (string)$context[$token] : $matches[0];
        }, $value);
    }

    private static function formatBytes(int $bytes, bool $unlimitedWord = false): string
    {
        if ($bytes <= 0) {
            return $unlimitedWord ? 'Unlimited' : 'unlimited';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = min((int)floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $pow);
        return round($value, $pow >= 2 ? 1 : 0) . ' ' . $units[$pow];
    }

    private static function formatSpeed(int $bytesPerSecond): string
    {
        if ($bytesPerSecond <= 0) {
            return 'unlimited speed';
        }
        if ($bytesPerSecond >= 1048576) {
            return round($bytesPerSecond / 1048576, 2) . ' MB/s';
        }
        return round($bytesPerSecond / 1024, 2) . ' KB/s';
    }

    private static function formatDailyLimit(int $bytes): string
    {
        if ($bytes <= 0) {
            return 'no daily transfer cap';
        }
        return 'a daily transfer limit of ' . self::formatBytes($bytes, true);
    }

    private static function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        \App\Service\Database\SchemaService::ensureTables([
            'site_content',
            'site_content_revisions',
            'site_content_preview_tokens',
        ], false);

        self::$schemaEnsured = true;
    }
}
