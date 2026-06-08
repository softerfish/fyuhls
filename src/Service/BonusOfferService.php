<?php

namespace App\Service;

use App\Core\Database;
use App\Model\File;
use App\Model\Package;
use App\Model\Setting;
use App\Model\User;
use App\Service\Database\SchemaService;
use App\Service\PackageTargetLockService;
use PDO;

class BonusOfferService
{
    private static bool $runtimeSchemaValidated = false;
    private static bool $runtimeSchemaRepaired = false;
    private static bool $legacyBackfillsApplied = false;
    private static bool $runtimeUnavailable = false;
    private static array $evaluationGuard = [];
    private static array $queuedTouches = [];

    public static function editFingerprint(array $offer): string
    {
        $payload = [
            'id' => (int)($offer['id'] ?? 0),
            'name' => (string)($offer['name'] ?? ''),
            'public_title' => (string)($offer['public_title'] ?? ''),
            'public_description' => (string)($offer['public_description'] ?? ''),
            'status' => (string)($offer['status'] ?? ''),
            'offer_kind' => (string)($offer['offer_kind'] ?? ''),
            'metric_key' => (string)($offer['metric_key'] ?? ''),
            'threshold_value' => (string)($offer['threshold_value'] ?? ''),
            'threshold_unit' => (string)($offer['threshold_unit'] ?? ''),
            'trigger_style' => (string)($offer['trigger_style'] ?? ''),
            'reward_type' => (string)($offer['reward_type'] ?? ''),
            'reward_value' => (string)($offer['reward_value'] ?? ''),
            'schedule_mode' => (string)($offer['schedule_mode'] ?? ''),
            'start_at' => (string)($offer['start_at'] ?? ''),
            'end_at' => (string)($offer['end_at'] ?? ''),
            'timezone' => (string)($offer['timezone'] ?? ''),
            'weekday_json' => (string)($offer['weekday_json'] ?? ''),
            'audience_type' => (string)($offer['audience_type'] ?? ''),
            'audience_json' => (string)($offer['audience_json'] ?? ''),
            'public_visibility' => (int)($offer['public_visibility'] ?? 0),
            'award_mode' => (string)($offer['award_mode'] ?? ''),
            'fraud_hold' => (int)($offer['fraud_hold'] ?? 0),
            'count_cleared_only' => (int)($offer['count_cleared_only'] ?? 0),
            'notify_on_start' => (int)($offer['notify_on_start'] ?? 0),
            'email_on_start' => (int)($offer['email_on_start'] ?? 0),
            'notify_on_earned' => (int)($offer['notify_on_earned'] ?? 0),
            'email_on_earned' => (int)($offer['email_on_earned'] ?? 0),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private static function rewardsRuntimeEnabled(): bool
    {
        return FeatureService::rewardsEnabled();
    }

    private static function assertRewardsRuntimeEnabledForCredit(): void
    {
        if (!self::rewardsRuntimeEnabled()) {
            throw new \RuntimeException('Bonus awards cannot be credited while rewards are disabled.');
        }
    }

    public static function definitions(): array
    {
        return [
            'offerKinds' => [
                'milestone' => 'Reach a goal',
                'limited_time' => 'Limited-time promotion',
                'referral' => 'Referral bonus',
            ],
            'metrics' => [
                'approved_payouts' => 'Get approved payouts',
                'uploaded_files' => 'Upload files',
                'rewarded_downloads' => 'Get rewarded downloads',
                'cleared_earnings_amount' => 'Reach cleared earnings',
                'verified_referrals' => 'Get verified referrals',
                'premium_referrals' => 'Get premium referrals',
                'no_fraud_reversal_days' => 'Go days without fraud reversals',
            ],
            'triggerStyles' => [
                'once' => 'Award the first time they reach the goal',
                'every_multiple' => 'Award every time they hit another goal multiple',
            ],
            'rewardTypes' => [
                'fixed' => 'Pay a fixed cash bonus',
                'multiplier' => 'Multiply qualifying earnings',
                'percent' => 'Add a percent bonus to qualifying earnings',
            ],
            'scheduleModes' => [
                'always' => 'Run continuously after it starts',
                'date_range' => 'Run between start and end dates',
                'date_range_weekdays' => 'Run on selected days inside a date range',
            ],
            'audienceTypes' => [
                'all_rewards' => 'Anyone using rewards',
                'all_affiliates' => 'Legacy: rewards users when affiliate mode is on',
                'free_only' => 'Free users only',
                'premium_only' => 'Premium users only',
                'selected_packages' => 'Only selected packages',
                'selected_users' => 'Only selected users',
            ],
            'awardModes' => [
                'pending_review' => 'Wait for admin approval',
                'auto_credit' => 'Credit automatically when earned',
            ],
            'weekdays' => [
                '0' => 'Sunday',
                '1' => 'Monday',
                '2' => 'Tuesday',
                '3' => 'Wednesday',
                '4' => 'Thursday',
                '5' => 'Friday',
                '6' => 'Saturday',
            ],
        ];
    }

    public static function allowedMetricsByOfferKind(): array
    {
        $allMetrics = array_keys(self::definitions()['metrics']);

        return [
            'milestone' => $allMetrics,
            'limited_time' => $allMetrics,
            'referral' => ['verified_referrals', 'premium_referrals'],
        ];
    }

    public static function metricDescriptions(): array
    {
        return [
            'approved_payouts' => 'Counts approved or paid withdrawal requests made by the user.',
            'uploaded_files' => 'Counts uploads that are still live and usable, excluding quarantined, deleted, pending-purge, abandoned, and failed files.',
            'rewarded_downloads' => 'Counts download rewards earned by the user during the offer window.',
            'cleared_earnings_amount' => 'Measures the user\'s cleared reward and referral earnings total.',
            'verified_referrals' => 'Counts referred users who signed up through a referral link and verified their email.',
            'premium_referrals' => 'Counts referred users who signed up through a referral link and are currently on a paid package.',
            'no_fraud_reversal_days' => 'Measures how many days the user has gone without a flagged, reversed, or cancelled download reward.',
        ];
    }

    private static function maintenanceRepairAllowed(): bool
    {
        return Setting::get('maintenance_mode', '0') === '1';
    }

    public static function ensureSchema(bool $allowRepair = true, bool $allowBackfill = true): void
    {
        $allowRuntimeRepair = $allowRepair && self::maintenanceRepairAllowed();
        $allowRuntimeBackfill = $allowBackfill && $allowRuntimeRepair;

        if ($allowRuntimeRepair) {
            if (!self::$runtimeSchemaRepaired) {
                SchemaService::withRepairWindow(static function (): void {
                    SchemaService::ensureTables(['bonus_offers', 'bonus_offer_awards', 'bonus_offer_announcements', 'users', 'files'], true);
                });
                self::$runtimeSchemaRepaired = true;
                self::$runtimeSchemaValidated = true;
                self::$runtimeUnavailable = false;
            }
        } elseif (!self::$runtimeSchemaValidated && !self::$runtimeSchemaRepaired) {
            SchemaService::ensureTables(['bonus_offers', 'bonus_offer_awards', 'bonus_offer_announcements', 'users', 'files'], false);
            self::$runtimeSchemaValidated = true;
            self::$runtimeUnavailable = false;
        }

        if (!$allowRuntimeBackfill || self::$legacyBackfillsApplied) {
            return;
        }

        if (!self::$runtimeSchemaRepaired) {
            SchemaService::withRepairWindow(static function (): void {
                SchemaService::ensureTables(['bonus_offers', 'bonus_offer_awards', 'bonus_offer_announcements', 'users', 'files'], true);
            });
            self::$runtimeSchemaRepaired = true;
            self::$runtimeSchemaValidated = true;
        }

        $db = Database::getInstance()->getConnection();

        try {
            $db->exec("
                UPDATE users u
                LEFT JOIN (
                    SELECT user_id, MAX(created_at) AS latest_premium_started_at
                    FROM subscriptions
                    WHERE status = 'active'
                    GROUP BY user_id
                ) s ON s.user_id = u.id
                LEFT JOIN packages p ON p.id = u.package_id
                SET u.premium_started_at = COALESCE(u.premium_started_at, s.latest_premium_started_at, u.updated_at)
                WHERE p.level_type = 'paid'
                  AND u.status = 'active'
                  AND u.premium_started_at IS NULL
            ");
        } catch (\Throwable $e) {
            // Keep legacy backfill non-fatal once the strict schema path confirms the supporting column exists.
        }

        try {
            $db->exec("UPDATE files SET creation_origin = 'upload' WHERE creation_origin IS NULL OR creation_origin = ''");
        } catch (\Throwable $e) {
            // Keep legacy data backfill non-fatal once the strict schema path confirms the supporting column exists.
        }

        self::$legacyBackfillsApplied = true;
    }

    public static function runtimeAvailable(): bool
    {
        if (self::$runtimeUnavailable) {
            return false;
        }

        try {
            self::ensureSchema(false, false);
            return true;
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('bonus offer runtime schema unavailable', [
                'error' => $e->getMessage(),
            ]);
            self::$runtimeUnavailable = true;
            return false;
        }
    }

    public static function getAdminData(?int $editingOfferId = null): array
    {
        if (!self::runtimeAvailable()) {
            return [
                'bonusOfferDefinitions' => self::definitions(),
                'bonusOffers' => [],
                'bonusPendingAwards' => [],
                'bonusRecentAwards' => [],
                'bonusEditingOffer' => null,
            ];
        }
        self::syncStatuses(false, false);

        $db = Database::getInstance()->getConnection();
        $offersStmt = $db->query("SELECT * FROM bonus_offers ORDER BY FIELD(status, 'active', 'scheduled', 'draft', 'paused', 'ended', 'archived'), created_at DESC");
        $offers = $offersStmt->fetchAll();

        $pendingStmt = $db->query("
            SELECT a.*, o.public_title, o.reward_type, o.reward_value, u.username
            FROM bonus_offer_awards a
            INNER JOIN bonus_offers o ON o.id = a.offer_id
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.status = 'pending_review'
            ORDER BY a.earned_at DESC
            LIMIT 100
        ");
        $pendingAwards = $pendingStmt->fetchAll();

        $recentStmt = $db->query("
            SELECT a.*, o.public_title, u.username
            FROM bonus_offer_awards a
            INNER JOIN bonus_offers o ON o.id = a.offer_id
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.status IN ('credited', 'rejected', 'reversed')
            ORDER BY COALESCE(a.reviewed_at, a.credited_at, a.earned_at) DESC
            LIMIT 50
        ");
        $recentAwards = $recentStmt->fetchAll();

        return [
            'bonusOfferDefinitions' => self::definitions(),
            'bonusOffers' => $offers,
            'bonusPendingAwards' => $pendingAwards,
            'bonusRecentAwards' => $recentAwards,
            'bonusEditingOffer' => $editingOfferId ? self::findOffer($editingOfferId) : null,
        ];
    }

    public static function saveOfferFromInput(array $input, int $actorId, ?callable $auditWriter = null): int
    {
        self::ensureSchema(false, false);

        $db = Database::getInstance()->getConnection();
        $id = (int)($input['offer_id'] ?? 0);
        $timezone = trim((string)($input['timezone'] ?? 'UTC'));
        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        $status = self::normalizeEnum((string)($input['status'] ?? 'draft'), ['draft', 'scheduled', 'active', 'paused', 'ended', 'archived'], 'draft');
        $offerKind = self::normalizeEnum((string)($input['offer_kind'] ?? 'milestone'), array_keys(self::definitions()['offerKinds']), 'milestone');
        $metricKey = self::normalizeEnum((string)($input['metric_key'] ?? 'rewarded_downloads'), array_keys(self::definitions()['metrics']), 'rewarded_downloads');
        $triggerStyle = self::normalizeEnum((string)($input['trigger_style'] ?? 'once'), array_keys(self::definitions()['triggerStyles']), 'once');
        $rewardType = self::normalizeEnum((string)($input['reward_type'] ?? 'fixed'), array_keys(self::definitions()['rewardTypes']), 'fixed');
        $scheduleMode = self::normalizeEnum((string)($input['schedule_mode'] ?? 'always'), array_keys(self::definitions()['scheduleModes']), 'always');
        $audienceType = self::normalizeEnum((string)($input['audience_type'] ?? 'all_rewards'), array_keys(self::definitions()['audienceTypes']), 'all_rewards');
        $awardMode = self::normalizeEnum((string)($input['award_mode'] ?? 'pending_review'), array_keys(self::definitions()['awardModes']), 'pending_review');

        $thresholdValue = max(0, (float)($input['threshold_value'] ?? 0));
        $rewardValue = max(0, (float)($input['reward_value'] ?? 0));
        $thresholdUnit = trim((string)($input['threshold_unit'] ?? 'count'));
        if ($thresholdUnit === '') {
            $thresholdUnit = 'count';
        }

        $weekdayValues = array_values(array_unique(array_filter(array_map(
            static function ($value): ?string {
                if (!is_scalar($value)) {
                    return null;
                }
                $normalized = (string)(int)$value;
                return in_array($normalized, ['0', '1', '2', '3', '4', '5', '6'], true) ? $normalized : null;
            },
            (array)($input['active_weekdays'] ?? [])
        ))));
        sort($weekdayValues);

        $audienceIds = self::normalizeAudienceIds((string)($input['audience_ids'] ?? ''));
        $startAt = self::localDateTimeToUtc(trim((string)($input['start_at'] ?? '')), $timezone);
        $endAt = self::localDateTimeToUtc(trim((string)($input['end_at'] ?? '')), $timezone);

        $payload = [
            'name' => trim((string)($input['name'] ?? '')),
            'public_title' => trim((string)($input['public_title'] ?? '')),
            'public_description' => trim((string)($input['public_description'] ?? '')),
            'status' => $status,
            'offer_kind' => $offerKind,
            'metric_key' => $metricKey,
            'threshold_value' => $thresholdValue,
            'threshold_unit' => $thresholdUnit,
            'trigger_style' => $triggerStyle,
            'reward_type' => $rewardType,
            'reward_value' => $rewardValue,
            'schedule_mode' => $scheduleMode,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'timezone' => $timezone,
            'weekday_json' => $weekdayValues !== [] ? json_encode($weekdayValues, JSON_UNESCAPED_SLASHES) : null,
            'audience_type' => $audienceType,
            'audience_json' => $audienceIds !== [] ? json_encode($audienceIds, JSON_UNESCAPED_SLASHES) : null,
            'public_visibility' => isset($input['public_visibility']) ? 1 : 0,
            'award_mode' => $awardMode,
            'fraud_hold' => isset($input['fraud_hold']) ? 1 : 0,
            'count_cleared_only' => isset($input['count_cleared_only']) ? 1 : 0,
            'notify_on_start' => isset($input['notify_on_start']) ? 1 : 0,
            'email_on_start' => isset($input['email_on_start']) ? 1 : 0,
            'notify_on_earned' => isset($input['notify_on_earned']) ? 1 : 0,
            'email_on_earned' => isset($input['email_on_earned']) ? 1 : 0,
        ];

        if ($payload['name'] === '' || $payload['public_title'] === '') {
            throw new \RuntimeException('Bonus offers need both an internal name and a public title.');
        }

        $allowedMetrics = self::allowedMetricsByOfferKind();
        if (!in_array($payload['metric_key'], $allowedMetrics[$payload['offer_kind']] ?? [], true)) {
            if ($payload['offer_kind'] === 'referral') {
                throw new \RuntimeException('Referral bonuses can only use verified referrals or premium referrals as the goal.');
            }

            throw new \RuntimeException('The selected goal does not match this bonus style.');
        }

        if ($payload['threshold_value'] <= 0) {
            throw new \RuntimeException('Threshold value must be greater than zero.');
        }

        if ($payload['reward_value'] <= 0) {
            throw new \RuntimeException('Reward value must be greater than zero.');
        }

        if ($payload['end_at'] !== null && $payload['start_at'] !== null && strtotime((string)$payload['end_at']) < strtotime((string)$payload['start_at'])) {
            throw new \RuntimeException('Bonus offer end date must be after the start date.');
        }

        if (in_array($payload['schedule_mode'], ['date_range', 'date_range_weekdays'], true)) {
            if ($payload['start_at'] === null || $payload['end_at'] === null) {
                throw new \RuntimeException('Date-based bonus offers require both a start date and an end date.');
            }
        }

        if ($payload['status'] === 'active' && $payload['start_at'] !== null && strtotime((string)$payload['start_at']) > time()) {
            throw new \RuntimeException('Live now cannot be used with a future start date. Use Waiting for start date instead.');
        }

        if ($payload['status'] === 'active' && $payload['end_at'] !== null && strtotime((string)$payload['end_at']) <= time()) {
            throw new \RuntimeException('Live now cannot be used with an end date that has already passed. Use Finished or update the end date.');
        }

        if ($payload['status'] === 'scheduled') {
            if ($payload['start_at'] === null) {
                throw new \RuntimeException('Waiting for start date requires a start date and time.');
            }

            if (strtotime((string)$payload['start_at']) <= time()) {
                throw new \RuntimeException('Waiting for start date requires a future start date and time.');
            }
        }

        if ($rewardType !== 'fixed' && !in_array($metricKey, ['rewarded_downloads', 'cleared_earnings_amount'], true)) {
            throw new \RuntimeException('Multiplier and percentage bonuses currently require a rewards-based metric.');
        }

        if (in_array($payload['audience_type'], ['selected_packages', 'selected_users'], true) && $audienceIds === []) {
            throw new \RuntimeException('Selected users and selected packages offers need at least one ID.');
        }

        $lockedPackageKeys = [];
        if ($payload['audience_type'] === 'selected_packages') {
            $selectedPackageIds = PackageTargetLockService::normalizePackageIds(array_map('intval', $audienceIds));
            if ($selectedPackageIds === []) {
                throw new \RuntimeException('Selected packages offers need at least one package ID.');
            }
            $lockedPackageKeys = PackageTargetLockService::lockPackageIds($db, $selectedPackageIds);
            PackageTargetLockService::assertPackagesExist(
                $db,
                $selectedPackageIds,
                'Selected package bonus offers can only target packages that still exist.'
            );
            $payload['audience_json'] = json_encode(array_map('strval', $selectedPackageIds), JSON_UNESCAPED_SLASHES);
        }

        try {
            $db->beginTransaction();

            $lockedExistingOffer = null;
            if ($id > 0) {
                $existingStmt = $db->prepare("
                    SELECT id, name, public_title, public_description, status, offer_kind, metric_key, threshold_value,
                           threshold_unit, trigger_style, reward_type, reward_value, schedule_mode, start_at, end_at,
                           timezone, weekday_json, audience_type, audience_json, public_visibility, award_mode,
                           fraud_hold, count_cleared_only, notify_on_start, email_on_start, notify_on_earned,
                           email_on_earned
                    FROM bonus_offers
                    WHERE id = ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $existingStmt->execute([$id]);
                $lockedExistingOffer = $existingStmt->fetch();
                if (!$lockedExistingOffer) {
                    throw new \RuntimeException('That bonus offer could not be found.');
                }

                $expectedFingerprint = trim((string)($input['offer_edit_fingerprint'] ?? ''));
                if ($expectedFingerprint === '' || !hash_equals(self::editFingerprint($lockedExistingOffer), $expectedFingerprint)) {
                    throw new \RuntimeException('This bonus offer changed while you were editing it. Reload the page and review the latest settings before saving again.');
                }

                if ($audienceType === 'all_affiliates') {
                    $isLegacyAffiliateOffer = ((string)($lockedExistingOffer['audience_type'] ?? '')) === 'all_affiliates';
                    if (!$isLegacyAffiliateOffer) {
                        throw new \RuntimeException('The legacy affiliate-wide audience can no longer be assigned to new or non-legacy bonus offers.');
                    }
                }

                if (in_array($payload['status'], ['ended', 'archived'], true)) {
                    $currentStatus = (string)($lockedExistingOffer['status'] ?? '');
                    if ($currentStatus !== $payload['status']) {
                        throw new \RuntimeException('Finished and archived bonus statuses are system-managed states and cannot be set manually here.');
                    }
                }
            } elseif ($audienceType === 'all_affiliates') {
                throw new \RuntimeException('The legacy affiliate-wide audience can no longer be assigned to new or non-legacy bonus offers.');
            }

            if ($id > 0) {
                $sql = "
                    UPDATE bonus_offers
                    SET name = :name,
                        public_title = :public_title,
                        public_description = :public_description,
                        status = :status,
                        offer_kind = :offer_kind,
                        metric_key = :metric_key,
                        threshold_value = :threshold_value,
                        threshold_unit = :threshold_unit,
                        trigger_style = :trigger_style,
                        reward_type = :reward_type,
                        reward_value = :reward_value,
                        schedule_mode = :schedule_mode,
                        start_at = :start_at,
                        end_at = :end_at,
                        timezone = :timezone,
                        weekday_json = :weekday_json,
                        audience_type = :audience_type,
                        audience_json = :audience_json,
                        public_visibility = :public_visibility,
                        award_mode = :award_mode,
                        fraud_hold = :fraud_hold,
                        count_cleared_only = :count_cleared_only,
                        notify_on_start = :notify_on_start,
                        email_on_start = :email_on_start,
                        notify_on_earned = :notify_on_earned,
                        email_on_earned = :email_on_earned
                    WHERE id = :id
                ";
                $stmt = $db->prepare($sql);
                $payload['id'] = $id;
                $stmt->execute($payload);
                self::syncStatuses(true, true);
                if ($auditWriter !== null) {
                    $auditWriter($db, $id, $payload, 'updated');
                }
                $db->commit();
                return $id;
            }

            $payload['public_id'] = self::generatePublicId($db, 'bonus_offers', 'public_id');
            $payload['created_by_user_id'] = $actorId;
            $sql = "
                INSERT INTO bonus_offers (
                    public_id, name, public_title, public_description, status, offer_kind, metric_key,
                    threshold_value, threshold_unit, trigger_style, reward_type, reward_value,
                    schedule_mode, start_at, end_at, timezone, weekday_json, audience_type,
                    audience_json, public_visibility, award_mode, fraud_hold, count_cleared_only,
                    notify_on_start, email_on_start, notify_on_earned, email_on_earned, created_by_user_id
                ) VALUES (
                    :public_id, :name, :public_title, :public_description, :status, :offer_kind, :metric_key,
                    :threshold_value, :threshold_unit, :trigger_style, :reward_type, :reward_value,
                    :schedule_mode, :start_at, :end_at, :timezone, :weekday_json, :audience_type,
                    :audience_json, :public_visibility, :award_mode, :fraud_hold, :count_cleared_only,
                    :notify_on_start, :email_on_start, :notify_on_earned, :email_on_earned, :created_by_user_id
                )
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($payload);
            $offerId = (int)$db->lastInsertId();
            self::syncStatuses(true, true);
            if ($auditWriter !== null) {
                $auditWriter($db, $offerId, $payload, 'created');
            }
            $db->commit();
            return $offerId;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        } finally {
            PackageTargetLockService::releaseLocks($db, $lockedPackageKeys);
        }
    }

    public static function deleteOffer(int $offerId, ?callable $auditWriter = null): void
    {
        self::ensureSchema(false, false);
        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        try {
            $offerStmt = $db->prepare("SELECT id FROM bonus_offers WHERE id = ? LIMIT 1 FOR UPDATE");
            $offerStmt->execute([$offerId]);
            if ((int)($offerStmt->fetchColumn() ?: 0) !== $offerId) {
                throw new \RuntimeException('That bonus offer could not be found.');
            }

            $awardCountStmt = $db->prepare("SELECT COUNT(*) FROM bonus_offer_awards WHERE offer_id = ?");
            $awardCountStmt->execute([$offerId]);
            $hasAwards = (int)$awardCountStmt->fetchColumn() > 0;

            $earningCountStmt = $db->prepare("
                SELECT COUNT(*)
                FROM earnings
                WHERE type = 'bonus'
                  AND JSON_EXTRACT(metadata, '$.bonus_offer_id') = ?
            ");
            $earningCountStmt->execute([$offerId]);
            $hasCreditedLedger = (int)$earningCountStmt->fetchColumn() > 0;

            if ($hasAwards || $hasCreditedLedger) {
                $archive = $db->prepare("
                    UPDATE bonus_offers
                    SET status = 'archived',
                        public_visibility = 0,
                        end_at = COALESCE(end_at, NOW()),
                        notify_on_start = 0,
                        email_on_start = 0
                    WHERE id = ?
                ");
                $archive->execute([$offerId]);
            } else {
                $db->prepare("DELETE FROM bonus_offer_announcements WHERE offer_id = ?")->execute([$offerId]);
                $db->prepare("DELETE FROM bonus_offers WHERE id = ?")->execute([$offerId]);
            }

            if ($auditWriter !== null) {
                $auditWriter($db, $offerId);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function reviewAward(int $awardId, string $decision, int $reviewerId, string $note = '', ?callable $auditWriter = null): string
    {
        self::ensureSchema(false, false);
        if ($decision === 'approve') {
            return self::creditAward($awardId, $reviewerId, $note, true, $auditWriter);
        }

        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                SELECT a.*, o.public_title, o.public_description, o.notify_on_earned, o.email_on_earned
                FROM bonus_offer_awards a
                INNER JOIN bonus_offers o ON o.id = a.offer_id
                WHERE a.id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$awardId]);
            $award = $stmt->fetch();
            if (!$award || ($award['status'] ?? '') !== 'pending_review') {
                throw new \RuntimeException('That bonus award is no longer waiting for review.');
            }

            ReviewIntegrityService::assertNotSelfBonusAwardReview($reviewerId, (int)($award['user_id'] ?? 0));

            $update = $db->prepare("
                UPDATE bonus_offer_awards
                SET status = 'rejected',
                    reviewed_at = NOW(),
                    reviewed_by_user_id = ?,
                    note = ?
                WHERE id = ?
            ");
            $update->execute([$reviewerId, $note !== '' ? $note : 'Rejected by admin review.', $awardId]);
            if ($auditWriter !== null) {
                $auditWriter($db, $award, 'rejected');
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        try {
            NotificationService::sendEvent(
                (int)$award['user_id'],
                'promotions',
                'bonus-award-rejected:' . (int)$award['id'],
                'Bonus review was declined',
                'A bonus from "' . (string)$award['public_title'] . '" was reviewed and not credited.',
                'warning',
                '/rewards'
            );
        } catch (\Throwable $e) {
            error_log('Bonus rejection notification failed for award ' . (int)$awardId . ' user ' . (int)$award['user_id'] . ': ' . $e->getMessage());
        }

        return 'rejected';
    }

    public static function hasVisiblePromotions(?int $userId = null): bool
    {
        if (!self::rewardsRuntimeEnabled()) {
            return false;
        }
        if (!self::runtimeAvailable()) {
            return false;
        }
        self::syncStatuses(false, false);

        if ($userId === null || $userId <= 0) {
            return self::listPublicOffers() !== [];
        }

        self::evaluateForUser((int)$userId);
        return self::listOffersForUser((int)$userId) !== [];
    }

    public static function listPublicOffers(): array
    {
        if (!self::rewardsRuntimeEnabled()) {
            return [];
        }
        if (!self::runtimeAvailable()) {
            return [];
        }
        self::syncStatuses(false, false);
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT * FROM bonus_offers WHERE status = 'active' AND public_visibility = 1 ORDER BY created_at DESC");
        $offers = [];
        $publicAudienceTypes = ['all_rewards', 'free_only', 'premium_only'];
        foreach ($stmt->fetchAll() as $offer) {
            if (!in_array((string)($offer['audience_type'] ?? 'all_rewards'), $publicAudienceTypes, true)) {
                continue;
            }
            $offers[] = $offer;
        }
        return $offers;
    }

    public static function listOffersForUser(int $userId, bool $includeHidden = false): array
    {
        if (!self::rewardsRuntimeEnabled()) {
            return [];
        }
        if (!self::runtimeAvailable()) {
            return [];
        }
        self::syncStatuses(false, false);

        $user = User::find($userId);
        if (!$user) {
            return [];
        }

        if (strtolower((string)($user['status'] ?? 'active')) !== 'active') {
            return [];
        }

        $package = Package::getUserPackage($userId) ?: [];
        $db = Database::getInstance()->getConnection();
        $sql = "SELECT * FROM bonus_offers WHERE status = 'active'";
        if (!$includeHidden) {
            $sql .= " AND public_visibility = 1";
        }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $db->query($sql);
        $offers = [];

        foreach ($stmt->fetchAll() as $offer) {
            if (!self::offerMatchesAudience($offer, $user, $package)) {
                continue;
            }

            $progress = self::measureOfferProgress($offer, $userId, $user, $package);
            $offer['progress_value'] = $progress['progress_value'];
            $offer['progress_label'] = $progress['progress_label'];
            $offer['reward_preview'] = self::formatRewardPreview($offer, $progress);
            $offer['schedule_label'] = self::formatOfferSchedule($offer);
            $offers[] = $offer;
        }

        return $offers;
    }

    public static function evaluateForUser(int $userId): void
    {
        if (!self::rewardsRuntimeEnabled()) {
            return;
        }
        if (!self::runtimeAvailable()) {
            return;
        }
        self::syncStatuses(false, false);

        if ($userId <= 0 || isset(self::$evaluationGuard[$userId])) {
            return;
        }
        self::$evaluationGuard[$userId] = true;
        try {
            $user = User::find($userId);
            if (!$user) {
                return;
            }
            if (strtolower((string)($user['status'] ?? 'active')) !== 'active') {
                self::reconcileAwardsForEligibility($userId, null, []);
                return;
            }

            $package = Package::getUserPackage($userId) ?: [];
            self::reconcileAwardsForEligibility($userId, $user, $package);
            $offers = self::listOffersForUser($userId, true);

            foreach ($offers as $offer) {
                self::maybeAnnounceOfferToUser($offer, $user, $package);
                $progress = self::measureOfferProgress($offer, $userId, $user, $package);
                self::createAwardsForProgress($offer, $user, $package, $progress);
            }
        } finally {
            unset(self::$evaluationGuard[$userId]);
        }
    }

    public static function queueUserTouch(int $userId, bool $includeReferrer = false): void
    {
        if ($userId <= 0) {
            return;
        }

        if (!isset(self::$queuedTouches[$userId])) {
            self::$queuedTouches[$userId] = false;
        }

        self::$queuedTouches[$userId] = self::$queuedTouches[$userId] || $includeReferrer;
    }

    public static function flushQueuedTouches(): void
    {
        if (self::$queuedTouches === []) {
            return;
        }

        if (!self::rewardsRuntimeEnabled()) {
            self::$queuedTouches = [];
            return;
        }

        if (!self::runtimeAvailable()) {
            self::$queuedTouches = [];
            return;
        }
        $touches = self::$queuedTouches;
        self::$queuedTouches = [];

        $directUserIds = array_keys($touches);
        foreach ($directUserIds as $userId) {
            try {
                self::evaluateForUser((int)$userId);
            } catch (\Throwable $e) {
                error_log('Bonus touch evaluation failed for user ' . (int)$userId . ': ' . $e->getMessage());
            }
        }

        $referrerSourceUserIds = [];
        foreach ($touches as $userId => $includeReferrer) {
            if ($includeReferrer) {
                $referrerSourceUserIds[] = (int)$userId;
            }
        }

        foreach (self::lookupReferralReferrers($referrerSourceUserIds) as $referrerId) {
            try {
                self::evaluateForUser($referrerId);
            } catch (\Throwable $e) {
                error_log('Bonus touch evaluation failed for referrer ' . (int)$referrerId . ': ' . $e->getMessage());
            }
        }
    }

    public static function touchUser(int $userId, bool $includeReferrer = false): void
    {
        self::queueUserTouch($userId, $includeReferrer);
        self::flushQueuedTouches();
    }

    public static function touchUserFailSoft(int $userId, bool $includeReferrer = false, array $context = []): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            self::touchUser($userId, $includeReferrer);
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Bonus touch skipped after a successful workflow because the rewards side effect could not complete.', [
                'user_id' => $userId,
                'include_referrer' => $includeReferrer,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function flushQueuedTouchesFailSoft(array $context = []): void
    {
        try {
            self::flushQueuedTouches();
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Queued bonus touches were skipped after a successful workflow because the rewards side effect could not complete.', [
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function touchUsersFailSoft(array $userIds, bool $includeReferrer = false, array $context = []): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return;
        }

        try {
            self::touchUsers($userIds, $includeReferrer);
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Bonus touch batch skipped after a successful workflow because the rewards side effect could not complete.', [
                'user_ids' => $userIds,
                'include_referrer' => $includeReferrer,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function touchUsers(array $userIds, bool $includeReferrer = false): void
    {
        foreach ($userIds as $userId) {
            self::queueUserTouch((int)$userId, $includeReferrer);
        }
        self::flushQueuedTouches();
    }

    public static function getRewardsBonusSummary(int $userId): array
    {
        if (!self::runtimeAvailable()) {
            return [
                'cleared_bonus_value' => 0.0,
                'pending_bonus_review' => 0.0,
                'reversed_bonus_value' => 0.0,
                'recent_credit_count' => 0,
            ];
        }
        $db = Database::getInstance()->getConnection();

        $summary = [
            'cleared_bonus_value' => 0.0,
            'pending_bonus_review' => 0.0,
            'credited_bonus_total' => 0.0,
            'paid_bonus_total' => 0.0,
        ];

        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM earnings WHERE user_id = ? AND type = 'bonus' AND status = 'cleared'");
        $stmt->execute([$userId]);
        $summary['cleared_bonus_value'] = (float)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM bonus_offer_awards WHERE user_id = ? AND status = 'pending_review'");
        $stmt->execute([$userId]);
        $summary['pending_bonus_review'] = (float)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) FROM earnings WHERE user_id = ? AND type = 'bonus' AND status IN ('cleared', 'paid')");
        $stmt->execute([$userId]);
        $summary['credited_bonus_total'] = (float)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM earnings WHERE user_id = ? AND type = 'bonus' AND status = 'paid'");
        $stmt->execute([$userId]);
        $summary['paid_bonus_total'] = (float)$stmt->fetchColumn();

        return $summary;
    }

    public static function getRewardsBonusHistory(int $userId, int $limit = 50): array
    {
        if (!self::runtimeAvailable()) {
            return [];
        }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT a.*, o.public_title
            FROM bonus_offer_awards a
            INNER JOIN bonus_offers o ON o.id = a.offer_id
            WHERE a.user_id = ?
            ORDER BY COALESCE(
                CASE WHEN a.status IN ('reversed', 'rejected') THEN a.reviewed_at END,
                a.earned_at
            ) DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function findOffer(int $offerId): ?array
    {
        if (!self::runtimeAvailable()) {
            return null;
        }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM bonus_offers WHERE id = ? LIMIT 1");
        $stmt->execute([$offerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function syncStatuses(bool $allowRepair = true, bool $allowBackfill = true): void
    {
        self::ensureSchema($allowRepair, $allowBackfill);
        $db = Database::getInstance()->getConnection();
        $rows = $db->query("SELECT id, status, start_at, end_at, timezone, schedule_mode FROM bonus_offers")->fetchAll();
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($rows as $row) {
            $currentStatus = (string)($row['status'] ?? 'draft');
            if (in_array($currentStatus, ['draft', 'paused', 'archived'], true)) {
                continue;
            }

            $nextStatus = $currentStatus;
            $startAt = !empty($row['start_at']) ? new \DateTimeImmutable((string)$row['start_at'], new \DateTimeZone('UTC')) : null;
            $endAt = !empty($row['end_at']) ? new \DateTimeImmutable((string)$row['end_at'], new \DateTimeZone('UTC')) : null;

            if ($startAt !== null && $nowUtc < $startAt) {
                $nextStatus = 'scheduled';
            } elseif ($endAt !== null && $nowUtc > $endAt) {
                $nextStatus = 'ended';
            } else {
                $nextStatus = 'active';
            }

            if ($nextStatus !== $currentStatus) {
                $update = $db->prepare("UPDATE bonus_offers SET status = ? WHERE id = ?");
                $update->execute([$nextStatus, (int)$row['id']]);
            }
        }
    }

    private static function maybeAnnounceOfferToUser(array $offer, array $user, array $package): void
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return;
        }
        if ((int)($offer['public_visibility'] ?? 1) !== 1) {
            return;
        }

        $eventKey = 'bonus-offer-start:' . (int)$offer['id'] . ':' . $userId;
        $message = (string)($offer['public_description'] ?? '');
        if ($message === '') {
            $message = 'A new promotion is active for your account.';
        }

        $db = Database::getInstance()->getConnection();
        $offerStateStmt = $db->prepare("SELECT id, status, public_visibility FROM bonus_offers WHERE id = ? LIMIT 1");
        $offerStateStmt->execute([(int)$offer['id']]);
        $offerState = $offerStateStmt->fetch();
        if (
            !$offerState
            || (string)($offerState['status'] ?? '') !== 'active'
            || (int)($offerState['public_visibility'] ?? 0) !== 1
        ) {
            return;
        }
        $legacyCheck = $db->prepare("SELECT id FROM bonus_offer_announcements WHERE offer_id = ? AND user_id = ? AND event_type = 'start' LIMIT 1");
        $legacyCheck->execute([(int)$offer['id'], $userId]);
        if ($legacyCheck->fetchColumn()) {
            return;
        }

        if ((int)($offer['notify_on_start'] ?? 0) === 1) {
            $trackNotify = $db->prepare("INSERT IGNORE INTO bonus_offer_announcements (offer_id, user_id, event_type, sent_at) VALUES (?, ?, 'start_notify', NOW())");
            $trackNotify->execute([(int)$offer['id'], $userId]);
            if ($trackNotify->rowCount() > 0) {
                try {
                    NotificationService::sendEvent(
                        $userId,
                        'promotions',
                        $eventKey,
                        'New promotion: ' . (string)$offer['public_title'],
                        $message . ' ' . self::formatOfferSchedule($offer),
                        'info',
                        '/promotions'
                    );
                } catch (\Throwable $e) {
                    $rollback = $db->prepare("DELETE FROM bonus_offer_announcements WHERE offer_id = ? AND user_id = ? AND event_type = 'start_notify'");
                    $rollback->execute([(int)$offer['id'], $userId]);
                    error_log('Bonus offer start notification failed for offer ' . (int)$offer['id'] . ' user ' . $userId . ': ' . $e->getMessage());
                }
            }
        }

        if ((int)($offer['email_on_start'] ?? 0) === 1 && !empty($user['email'])) {
            $trackEmail = $db->prepare("INSERT IGNORE INTO bonus_offer_announcements (offer_id, user_id, event_type, sent_at) VALUES (?, ?, 'start_email', NOW())");
            $trackEmail->execute([(int)$offer['id'], $userId]);
            if ($trackEmail->rowCount() > 0) {
                try {
                    MailService::sendTemplate((string)$user['email'], 'bonus_offer_started', [
                        '{username}' => (string)($user['username'] ?? 'User'),
                        '{offer_title}' => (string)$offer['public_title'],
                        '{offer_description}' => $message,
                        '{bonus_value}' => self::formatRewardPreview($offer, ['base_reward_amount' => 0]),
                        '{deadline}' => self::formatOfferSchedule($offer),
                        '{timezone}' => (string)($offer['timezone'] ?? 'UTC'),
                    ], 'low');
                } catch (\Throwable $e) {
                    $rollback = $db->prepare("DELETE FROM bonus_offer_announcements WHERE offer_id = ? AND user_id = ? AND event_type = 'start_email'");
                    $rollback->execute([(int)$offer['id'], $userId]);
                    error_log('Bonus offer start email failed for offer ' . (int)$offer['id'] . ' user ' . $userId . ': ' . $e->getMessage());
                }
            }
        }
    }

    private static function canCreditAwardUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT status FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $status = $stmt->fetchColumn();

        return is_string($status) && strtolower($status) === 'active';
    }

    private static function canCreditAwardForCurrentEligibility(array $award): bool
    {
        return self::currentAwardEligibility($award)['eligible'];
    }

    private static function currentAwardEligibility(array $award): array
    {
        $userId = (int)($award['user_id'] ?? 0);
        if (!self::canCreditAwardUser($userId)) {
            return [
                'eligible' => false,
                'reason' => 'Bonus could not be credited because the user account is no longer active.',
            ];
        }

        $user = User::find($userId);
        if (!$user) {
            return [
                'eligible' => false,
                'reason' => 'Bonus could not be credited because the user account is no longer active.',
            ];
        }

        $package = Package::getUserPackage($userId) ?: [];
        $offer = self::hydrateAwardOfferState($award);
        if (!self::offerMatchesAudience($offer, $user, $package)) {
            return [
                'eligible' => false,
                'reason' => 'Bonus could not be credited because the account no longer matches this promotion.',
            ];
        }

        $threshold = (float)($award['threshold_value'] ?? 0);
        if ($threshold <= 0) {
            return [
                'eligible' => false,
                'reason' => 'Bonus could not be credited because the promotion rules changed.',
            ];
        }

        $bucket = self::awardBucketFromAwardKey((string)($award['award_key'] ?? ''));
        $progress = self::measureOfferProgress($offer, $userId, $user, $package);
        $progressValue = (float)($progress['progress_value'] ?? 0);
        $requiredProgress = $threshold * max(1, $bucket);
        if (($progressValue + 0.0001) < $requiredProgress) {
            return [
                'eligible' => false,
                'reason' => 'Bonus could not be credited because the qualifying activity later fell below this milestone.',
            ];
        }

        $maxBucket = ((string)($offer['trigger_style'] ?? 'once') === 'every_multiple')
            ? (int)floor($progressValue / $threshold)
            : ($progressValue >= $threshold ? 1 : 0);
        $desiredAmount = self::calculateAwardAmount(
            $offer,
            $progress,
            max(1, $bucket),
            max(1, $maxBucket),
            $userId,
            self::resolveWindow($offer)
        );

        if ($desiredAmount <= 0 || abs(((float)($award['amount'] ?? 0)) - $desiredAmount) >= 0.0001) {
            return [
                'eligible' => false,
                'reason' => 'Bonus could not be credited because the qualifying activity for this milestone changed.',
            ];
        }

        return [
            'eligible' => true,
            'reason' => '',
        ];
    }

    private static function hydrateAwardOfferState(array $award): array
    {
        $offer = $award;
        $mappedFields = [
            'offer_metric_key' => 'metric_key',
            'offer_trigger_style' => 'trigger_style',
            'offer_reward_type' => 'reward_type',
            'offer_reward_value' => 'reward_value',
            'offer_schedule_mode' => 'schedule_mode',
            'offer_audience_type' => 'audience_type',
            'offer_audience_json' => 'audience_json',
            'offer_count_cleared_only' => 'count_cleared_only',
            'offer_fraud_hold' => 'fraud_hold',
        ];

        foreach ($mappedFields as $sourceKey => $targetKey) {
            if (array_key_exists($sourceKey, $award)) {
                $offer[$targetKey] = $award[$sourceKey];
            }
        }

        return $offer;
    }

    private static function awardBucketFromAwardKey(string $awardKey): int
    {
        if (preg_match('/:bucket:(\d+)(?:$|:)/', $awardKey, $matches) !== 1) {
            return 1;
        }

        return max(1, (int)$matches[1]);
    }

    private static function reconcileAwardsForEligibility(int $userId, ?array $user, array $package): void
    {
        if ($userId <= 0) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT a.id, a.status, o.*
            FROM bonus_offer_awards a
            INNER JOIN bonus_offers o ON o.id = a.offer_id
            WHERE a.user_id = ?
              AND a.status IN ('pending_review', 'credited')
        ");
        $stmt->execute([$userId]);

        $userIsActive = $user !== null && strtolower((string)($user['status'] ?? 'active')) === 'active';
        foreach ($stmt->fetchAll() as $award) {
            if ($userIsActive && self::offerMatchesAudience($award, $user, $package)) {
                continue;
            }

            $reason = $userIsActive
                ? 'Bonus was removed because this account no longer matches the promotion audience.'
                : 'Bonus was removed because the account is no longer active.';

            self::reverseAwardForLostQualification((int)($award['id'] ?? 0), $reason);
        }
    }

    private static function rejectAwardForIneligibleUser(PDO $db, array $award, ?int $reviewerId, string $note): void
    {
        $update = $db->prepare("
            UPDATE bonus_offer_awards
            SET status = 'rejected',
                reviewed_at = NOW(),
                reviewed_by_user_id = ?,
                note = ?
            WHERE id = ?
        ");
        $update->execute([
            $reviewerId,
            $note,
            (int)$award['id'],
        ]);
    }

    private static function createAwardsForProgress(array $offer, array $user, array $package, array $progress): void
    {
        $userId = (int)($user['id'] ?? 0);
        $threshold = (float)($offer['threshold_value'] ?? 0);
        if ($userId <= 0 || $threshold <= 0) {
            return;
        }

        $progressValue = (float)($progress['progress_value'] ?? 0);
        $maxBucket = ((string)($offer['trigger_style'] ?? 'once') === 'every_multiple')
            ? (int)floor($progressValue / $threshold)
            : ($progressValue >= $threshold ? 1 : 0);

        $window = self::resolveWindow($offer);
        self::reconcileAwardsForProgress($offer, $userId, max(0, $maxBucket), $progress);

        if ($maxBucket < 1) {
            return;
        }

        for ($bucket = 1; $bucket <= $maxBucket; $bucket++) {
            $baseAwardKey = self::baseAwardKey((int)$offer['id'], $userId, $bucket);
            $amount = self::calculateAwardAmount($offer, $progress, $bucket, $maxBucket, $userId, $window);
            if ($amount <= 0) {
                continue;
            }

            self::insertAward($offer, $user, $baseAwardKey, $amount, $progress);
        }
    }

    private static function insertAward(array $offer, array $user, string $baseAwardKey, float $amount, array $progress): void
    {
        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        try {
            $offerStmt = $db->prepare("
                SELECT id, status
                FROM bonus_offers
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $offerStmt->execute([(int)$offer['id']]);
            $currentOffer = $offerStmt->fetch();
            if (
                !$currentOffer
                || !in_array((string)($currentOffer['status'] ?? ''), ['active', 'scheduled'], true)
            ) {
                $db->commit();
                return;
            }

            $lockStmt = $db->prepare("
                SELECT id, award_key, status
                FROM bonus_offer_awards
                WHERE award_key = ?
                   OR award_key LIKE ?
                FOR UPDATE
            ");
            $lockStmt->execute([$baseAwardKey, $baseAwardKey . ':cycle:%']);
            $existingAwards = $lockStmt->fetchAll();
            foreach ($existingAwards as $existingAward) {
                if (in_array((string)($existingAward['status'] ?? ''), ['pending_review', 'credited'], true)) {
                    $db->commit();
                    return;
                }
            }

            $awardKey = self::nextAwardInsertKeyFromRows($baseAwardKey, $existingAwards);
            $insert = $db->prepare("
                INSERT INTO bonus_offer_awards (
                    offer_id, user_id, award_key, status, amount, progress_value, threshold_value,
                    source_started_at, source_ended_at, earned_at, metadata_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ");
            $shouldAutoCredit = ((string)($offer['award_mode'] ?? 'pending_review') === 'auto_credit')
                && empty($progress['requires_review_hold']);
            $status = 'pending_review';
            $metadata = json_encode([
                'metric_key' => (string)$offer['metric_key'],
                'progress_label' => (string)($progress['progress_label'] ?? ''),
                'base_reward_amount' => (float)($progress['base_reward_amount'] ?? 0),
                'requires_review_hold' => !empty($progress['requires_review_hold']),
            ], JSON_UNESCAPED_SLASHES);
            $insert->execute([
                (int)$offer['id'],
                (int)$user['id'],
                $awardKey,
                $status,
                $amount,
                (float)($progress['progress_value'] ?? 0),
                (float)($offer['threshold_value'] ?? 0),
                $progress['source_started_at'] ?? null,
                $progress['source_ended_at'] ?? null,
                $metadata,
            ]);
            $awardId = (int)$db->lastInsertId();

            $finalStatus = $status;
            if ($shouldAutoCredit) {
                $finalStatus = self::creditAward($awardId, null, 'Auto-credited by bonus offer rule.', false);
            }
            if ($db->inTransaction()) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return;
            }
            throw $e;
        }

        if ($finalStatus === 'credited' || $finalStatus === 'pending_review') {
            self::notifyEarnedAward($offer, $user, $amount, $finalStatus === 'credited', $awardKey);
        }
    }

    private static function creditAward(int $awardId, ?int $reviewerId, string $note = '', bool $sendNotifications = true, ?callable $auditWriter = null): string
    {
        self::assertRewardsRuntimeEnabledForCredit();
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $stmt = $db->prepare("
                SELECT a.*, o.public_title, o.public_description, o.notify_on_earned, o.email_on_earned,
                       o.start_at, o.end_at, o.timezone, o.weekday_json, o.metric_key AS offer_metric_key,
                       o.trigger_style AS offer_trigger_style, o.reward_type AS offer_reward_type,
                       o.reward_value AS offer_reward_value, o.schedule_mode AS offer_schedule_mode,
                       o.audience_type AS offer_audience_type, o.audience_json AS offer_audience_json,
                       o.count_cleared_only AS offer_count_cleared_only, o.fraud_hold AS offer_fraud_hold
                FROM bonus_offer_awards a
                INNER JOIN bonus_offers o ON o.id = a.offer_id
                WHERE a.id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$awardId]);
            $award = $stmt->fetch();
            if (!$award) {
                throw new \RuntimeException('Bonus award not found.');
            }
            if (!in_array((string)($award['status'] ?? ''), ['pending_review', 'credited'], true)) {
                throw new \RuntimeException('That bonus award is no longer waiting for credit.');
            }

            ReviewIntegrityService::assertNotSelfBonusAwardReview($reviewerId, (int)($award['user_id'] ?? 0));
            if (($award['status'] ?? '') === 'pending_review') {
                $eligibility = self::currentAwardEligibility($award);
                if (!$eligibility['eligible']) {
                    self::rejectAwardForIneligibleUser(
                        $db,
                        $award,
                        $reviewerId,
                        (string)($eligibility['reason'] ?? 'Bonus could not be credited because the qualifying activity for this milestone changed.')
                    );
                    if ($ownTransaction && $db->inTransaction()) {
                        $db->commit();
                    }
                    return 'rejected';
                }
            }

            $earningId = (int)($award['credited_earning_id'] ?? 0);
            if (($award['status'] ?? '') === 'credited' && $earningId > 0) {
                if ($ownTransaction && $db->inTransaction()) {
                    $db->commit();
                }
                return 'credited';
            } elseif ($earningId <= 0) {
                $metaJson = json_encode([
                    'bonus_offer_id' => (int)$award['offer_id'],
                    'bonus_award_id' => (int)$award['id'],
                    'award_key' => (string)$award['award_key'],
                ], JSON_UNESCAPED_SLASHES);

                $earning = $db->prepare("
                    INSERT INTO earnings (user_id, amount, type, status, reviewed_by, reviewed_at, review_note, description, metadata, created_at)
                    VALUES (?, ?, 'bonus', 'cleared', ?, NOW(), ?, ?, ?, NOW())
                ");
                $earning->execute([
                    (int)$award['user_id'],
                    (float)$award['amount'],
                    $reviewerId,
                    $note !== '' ? $note : 'Bonus offer credited.',
                    'Bonus offer: ' . (string)$award['public_title'],
                    $metaJson,
                ]);
                $earningId = (int)$db->lastInsertId();
            }

            $update = $db->prepare("
                UPDATE bonus_offer_awards
                SET status = 'credited',
                    reviewed_at = NOW(),
                    credited_at = NOW(),
                    reviewed_by_user_id = ?,
                    credited_earning_id = ?,
                    note = ?
                WHERE id = ?
            ");
            $update->execute([
                $reviewerId,
                $earningId,
                $note !== '' ? $note : 'Bonus credited to main balance.',
                $awardId,
            ]);

            if ($auditWriter !== null) {
                $auditWriter($db, $award, 'credited');
            }

            if ($ownTransaction && $db->inTransaction()) {
                $db->commit();
            }

            $user = $sendNotifications ? User::find((int)$award['user_id']) : null;
            if ($user) {
                self::notifyEarnedAward($award, $user, (float)$award['amount'], true, (string)($award['award_key'] ?? ('award:' . $awardId)));
            }
            return 'credited';
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function reconcileAwardsForProgress(array $offer, int $userId, int $maxBucket, array $progress): void
    {
        $offerId = (int)($offer['id'] ?? 0);
        if ($offerId <= 0 || $userId <= 0) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT id, award_key, status, amount
            FROM bonus_offer_awards
            WHERE offer_id = ?
              AND user_id = ?
              AND award_key LIKE ?
              AND status IN ('pending_review', 'credited')
        ");
        $stmt->execute([
            $offerId,
            $userId,
            'offer:' . $offerId . ':user:' . $userId . ':bucket:%',
        ]);

        foreach ($stmt->fetchAll() as $awardRow) {
            if (!preg_match('/:bucket:(\d+)(?:$|:)/', (string)$awardRow['award_key'], $matches)) {
                continue;
            }

            $bucket = (int)$matches[1];
            if ($bucket <= $maxBucket) {
                $desiredAmount = self::calculateAwardAmount($offer, $progress, $bucket, max(1, $maxBucket), $userId, self::resolveWindow($offer));
                if (abs(((float)$awardRow['amount']) - $desiredAmount) < 0.0001) {
                    continue;
                }

                self::reverseAwardForLostQualification(
                    (int)$awardRow['id'],
                    'Bonus was recalculated because the qualifying activity for this milestone changed.'
                );
                continue;
            }

            self::reverseAwardForLostQualification(
                (int)$awardRow['id'],
                'Bonus was removed because the qualifying activity later fell below this milestone.'
            );
        }
    }

    private static function reverseAwardForLostQualification(int $awardId, string $reason): void
    {
        if ($awardId <= 0) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        $shouldNotify = false;
        $notificationAward = null;

        try {
            $stmt = $db->prepare("
                SELECT a.*, o.public_title, o.public_description, o.notify_on_earned, o.email_on_earned,
                       o.start_at, o.end_at, o.timezone, o.weekday_json
                FROM bonus_offer_awards a
                INNER JOIN bonus_offers o ON o.id = a.offer_id
                WHERE a.id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$awardId]);
            $award = $stmt->fetch();
            if (!$award) {
                if ($ownTransaction && $db->inTransaction()) {
                    $db->commit();
                }
                return;
            }

            $status = (string)($award['status'] ?? '');
            if ($status === 'reversed' || $status === 'rejected') {
                if ($ownTransaction && $db->inTransaction()) {
                    $db->commit();
                }
                return;
            }

            if ($status === 'pending_review') {
                $update = $db->prepare("
                    UPDATE bonus_offer_awards
                    SET status = 'rejected',
                        reviewed_at = NOW(),
                        note = ?
                    WHERE id = ?
                ");
                $update->execute([
                    $reason,
                    $awardId,
                ]);
                if ($ownTransaction && $db->inTransaction()) {
                    $db->commit();
                }
                return;
            }

            if ($status !== 'credited') {
                if ($ownTransaction && $db->inTransaction()) {
                    $db->commit();
                }
                return;
            }

            $metaJson = json_encode([
                'bonus_offer_id' => (int)$award['offer_id'],
                'bonus_award_id' => (int)$award['id'],
                'award_key' => (string)$award['award_key'],
                'reversal_of_earning_id' => (int)($award['credited_earning_id'] ?? 0),
            ], JSON_UNESCAPED_SLASHES);

            $earning = $db->prepare("
                INSERT INTO earnings (user_id, amount, type, status, reviewed_by, reviewed_at, review_note, description, metadata, created_at)
                VALUES (?, ?, 'bonus', 'cleared', NULL, NOW(), ?, ?, ?, NOW())
            ");
            $earning->execute([
                (int)$award['user_id'],
                -abs((float)$award['amount']),
                $reason,
                'Bonus reversal: ' . (string)$award['public_title'],
                $metaJson,
            ]);

            $update = $db->prepare("
                UPDATE bonus_offer_awards
                SET status = 'reversed',
                    reviewed_at = NOW(),
                    note = ?
                WHERE id = ?
            ");
            $update->execute([
                $reason,
                $awardId,
            ]);
            $shouldNotify = true;
            $notificationAward = $award;

            if ($ownTransaction && $db->inTransaction()) {
                $db->commit();
            }

            if ($shouldNotify) {
                $user = User::find((int)$award['user_id']);
                if ($user) {
                    self::notifyReversedAward($notificationAward, $user, (float)$award['amount'], $reason);
                }
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function archiveAwardKey(string $awardKey, string $suffix): string
    {
        $extra = ':' . $suffix . ':' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(2));
        $maxBaseLength = max(1, 191 - strlen($extra));
        return substr($awardKey, 0, $maxBaseLength) . $extra;
    }

    private static function baseAwardKey(int $offerId, int $userId, int $bucket): string
    {
        return 'offer:' . $offerId . ':user:' . $userId . ':bucket:' . $bucket;
    }

    private static function liveAwardExistsForBaseKey(string $baseAwardKey): bool
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT id
            FROM bonus_offer_awards
            WHERE (award_key = ? OR award_key LIKE ?)
              AND status IN ('pending_review', 'credited')
            LIMIT 1
        ");
        $stmt->execute([$baseAwardKey, $baseAwardKey . ':cycle:%']);
        return (bool)$stmt->fetchColumn();
    }

    private static function nextAwardInsertKeyFromRows(string $baseAwardKey, array $existingRows): string
    {
        foreach ($existingRows as $row) {
            if ((string)($row['award_key'] ?? '') === $baseAwardKey) {
                return self::archiveAwardKey($baseAwardKey, 'cycle');
            }
        }

        return $baseAwardKey;
    }

    private static function nextAwardInsertKey(string $baseAwardKey): string
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id FROM bonus_offer_awards WHERE award_key = ? LIMIT 1");
        $stmt->execute([$baseAwardKey]);
        if (!$stmt->fetchColumn()) {
            return $baseAwardKey;
        }

        return self::archiveAwardKey($baseAwardKey, 'cycle');
    }

    private static function notifyEarnedAward(array $offer, array $user, float $amount, bool $credited, ?string $eventSuffix = null): void
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $uniquePart = $eventSuffix !== null && $eventSuffix !== ''
            ? $eventSuffix
            : md5((string)$amount . '|' . (string)($offer['public_title'] ?? ''));
        $eventKey = ($credited ? 'bonus-award-credited:' : 'bonus-award-pending:') . (int)$offer['id'] . ':' . $userId . ':' . $uniquePart;
        $title = $credited ? 'Bonus added to your balance' : 'Bonus earned and waiting for review';
        $message = $credited
            ? 'You earned $' . number_format($amount, 2) . ' from "' . (string)$offer['public_title'] . '".'
            : 'You reached a promotion milestone in "' . (string)$offer['public_title'] . '". Staff review is required before it is added to your balance.';

        if ((int)($offer['notify_on_earned'] ?? 0) === 1) {
            try {
                NotificationService::sendEvent(
                    $userId,
                    'promotions',
                    $eventKey,
                    $title,
                    $message,
                    $credited ? 'success' : 'info',
                    '/rewards'
                );
            } catch (\Throwable $e) {
                error_log('Bonus earned notification failed for offer ' . (int)($offer['id'] ?? 0) . ' user ' . $userId . ': ' . $e->getMessage());
            }
        }

        if ((int)($offer['email_on_earned'] ?? 0) === 1 && !empty($user['email'])) {
            try {
                MailService::sendTemplate((string)$user['email'], $credited ? 'bonus_offer_credited' : 'bonus_offer_earned_pending', [
                    '{username}' => (string)($user['username'] ?? 'User'),
                    '{offer_title}' => (string)$offer['public_title'],
                    '{offer_description}' => (string)($offer['public_description'] ?? ''),
                    '{bonus_amount}' => '$' . number_format($amount, 2),
                    '{deadline}' => self::formatOfferSchedule($offer),
                    '{timezone}' => (string)($offer['timezone'] ?? 'UTC'),
                ], 'low');
            } catch (\Throwable $e) {
                error_log('Bonus earned email failed for offer ' . (int)($offer['id'] ?? 0) . ' user ' . $userId . ': ' . $e->getMessage());
            }
        }
    }

    private static function notifyReversedAward(array $offer, array $user, float $amount, string $reason): void
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $eventKey = 'bonus-award-reversed:' . (int)($offer['id'] ?? 0) . ':' . $userId . ':' . md5((string)($offer['award_key'] ?? '') . '|' . $reason);
        $message = 'A previously credited bonus from "' . (string)$offer['public_title'] . '" was removed from your balance because the qualifying activity no longer meets the promotion rules.';

        if ((int)($offer['notify_on_earned'] ?? 0) === 1) {
            try {
                NotificationService::sendEvent(
                    $userId,
                    'promotions',
                    $eventKey,
                    'Bonus removed from your balance',
                    $message,
                    'warning',
                    '/rewards'
                );
            } catch (\Throwable $e) {
                error_log('Bonus reversal notification failed for offer ' . (int)($offer['offer_id'] ?? $offer['id'] ?? 0) . ' user ' . $userId . ': ' . $e->getMessage());
            }
        }

        if ((int)($offer['email_on_earned'] ?? 0) === 1 && !empty($user['email'])) {
            try {
                MailService::sendTemplate((string)$user['email'], 'bonus_offer_reversed', [
                    '{username}' => (string)($user['username'] ?? 'User'),
                    '{offer_title}' => (string)$offer['public_title'],
                    '{offer_description}' => (string)($offer['public_description'] ?? ''),
                    '{bonus_amount}' => '$' . number_format($amount, 2),
                    '{deadline}' => self::formatOfferSchedule($offer),
                    '{timezone}' => (string)($offer['timezone'] ?? 'UTC'),
                    '{reversal_reason}' => $reason,
                ], 'low');
            } catch (\Throwable $e) {
                error_log('Bonus reversal email failed for offer ' . (int)($offer['offer_id'] ?? $offer['id'] ?? 0) . ' user ' . $userId . ': ' . $e->getMessage());
            }
        }
    }

    private static function measureOfferProgress(array $offer, int $userId, array $user, array $package): array
    {
        $db = Database::getInstance()->getConnection();
        $window = self::resolveWindow($offer);
        $metric = (string)($offer['metric_key'] ?? 'rewarded_downloads');
        $countClearedOnly = (int)($offer['count_cleared_only'] ?? 1) === 1;
        $progress = 0.0;
        $baseRewardAmount = 0.0;

        if (!empty($window['force_inactive'])) {
            return [
                'progress_value' => 0.0,
                'progress_label' => self::formatProgressLabel($offer, 0.0),
                'base_reward_amount' => 0.0,
                'source_started_at' => $window['start'] ?? null,
                'source_ended_at' => $window['end'] ?? null,
                'requires_review_hold' => false,
            ];
        }

        if ($metric === 'approved_payouts') {
            $sql = "SELECT COUNT(*) FROM withdrawals WHERE user_id = ? AND status IN ('approved', 'paid')";
            [$sql, $params] = self::appendWindow($sql, [$userId], 'COALESCE(processed_at, created_at)', $window);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $progress = (float)$stmt->fetchColumn();
        } elseif ($metric === 'uploaded_files') {
            $qualifyingStatuses = File::storageConsumingStatuses();
            if ($qualifyingStatuses !== []) {
                $statusPlaceholders = implode(', ', array_fill(0, count($qualifyingStatuses), '?'));
                $sql = "SELECT COUNT(*) FROM files WHERE user_id = ? AND creation_origin = 'upload' AND status IN ($statusPlaceholders)";
                [$sql, $params] = self::appendWindow($sql, array_merge([$userId], $qualifyingStatuses), 'created_at', $window);
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $progress = (float)$stmt->fetchColumn();
            }
        } elseif ($metric === 'rewarded_downloads') {
            $statuses = $countClearedOnly ? ['cleared', 'paid'] : ['held', 'cleared', 'paid', 'pending'];
            $statusList = "'" . implode("','", $statuses) . "'";
            $sql = "SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM earnings WHERE user_id = ? AND type = 'download_reward' AND status IN ($statusList)";
            [$sql, $params] = self::appendWindow($sql, [$userId], 'created_at', $window);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $progress = (float)($row[0] ?? 0);
            $baseRewardAmount = (float)($row[1] ?? 0);
        } elseif ($metric === 'cleared_earnings_amount') {
            $statuses = $countClearedOnly ? ['cleared', 'paid'] : ['held', 'cleared', 'paid', 'pending'];
            $statusList = "'" . implode("','", $statuses) . "'";
            $sql = "SELECT COALESCE(SUM(amount), 0) FROM earnings WHERE user_id = ? AND type IN ('download_reward', 'pps_reward', 'referral') AND status IN ($statusList)";
            [$sql, $params] = self::appendWindow($sql, [$userId], 'created_at', $window);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $progress = (float)$stmt->fetchColumn();
            $baseRewardAmount = $progress;
        } elseif ($metric === 'verified_referrals') {
            User::ensureRuntimeColumns($db);
            $sql = "
                SELECT id, referrer_id, referrer_source, status, email_lookup, payment_method, payment_details, created_at
                FROM users
                WHERE referrer_id = ?
                  AND COALESCE(referrer_source, '') = 'referral'
                  AND email_verified = 1
            ";
            [$sql, $params] = self::appendWindow($sql, [$userId], 'created_at', $window);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $progress = (float)self::countEligibleReferralUsers($user, $stmt->fetchAll());
        } elseif ($metric === 'premium_referrals') {
            User::ensureRuntimeColumns($db);
            $sql = "
                SELECT DISTINCT u.id, u.referrer_id, u.referrer_source, u.status, u.email_lookup, u.payment_method, u.payment_details, COALESCE(u.premium_started_at, s.created_at, u.created_at) AS measured_at
                FROM users u
                INNER JOIN packages p ON p.id = u.package_id
                LEFT JOIN subscriptions s ON s.user_id = u.id AND s.package_id = u.package_id AND s.status = 'active'
                WHERE u.referrer_id = ?
                  AND COALESCE(u.referrer_source, '') = 'referral'
                  AND p.level_type = 'paid'
                  AND u.status = 'active'
            ";
            [$sql, $params] = self::appendWindow($sql, [$userId], 'COALESCE(u.premium_started_at, s.created_at, u.created_at)', $window);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $progress = (float)self::countEligibleReferralUsers($user, $stmt->fetchAll());
        } elseif ($metric === 'no_fraud_reversal_days') {
            $stmt = $db->prepare("
                SELECT MAX(created_at)
                FROM earnings
                WHERE user_id = ?
                  AND type = 'download_reward'
                  AND status IN ('flagged_review', 'reversed', 'cancelled')
            ");
            $stmt->execute([$userId]);
            $lastBad = $stmt->fetchColumn();
            $anchor = $lastBad ? strtotime((string)$lastBad) : strtotime((string)($user['created_at'] ?? 'now'));
            $windowStart = !empty($window['start']) ? strtotime((string)$window['start']) : null;
            $windowEnd = !empty($window['end']) ? min(time(), strtotime((string)$window['end'])) : time();
            if ($windowStart !== null) {
                $anchor = max($anchor, $windowStart);
            }
            $endTs = max($anchor, (int)$windowEnd);
            if (!empty($window['weekday_ranges']) && is_array($window['weekday_ranges'])) {
                $eligibleSeconds = 0;
                foreach ($window['weekday_ranges'] as $range) {
                    $rangeStart = !empty($range['start']) ? strtotime((string)$range['start']) : false;
                    $rangeEnd = !empty($range['end']) ? strtotime((string)$range['end']) : false;
                    if ($rangeStart === false || $rangeEnd === false) {
                        continue;
                    }
                    $overlapStart = max($anchor, (int)$rangeStart);
                    $overlapEnd = min($endTs, (int)$rangeEnd);
                    if ($overlapEnd > $overlapStart) {
                        $eligibleSeconds += ($overlapEnd - $overlapStart);
                    }
                }
                $progress = (float)max(0, floor($eligibleSeconds / 86400));
            } else {
                $progress = (float)max(0, floor(($endTs - $anchor) / 86400));
            }
        }

        return [
            'progress_value' => $progress,
            'progress_label' => self::formatProgressLabel($offer, $progress),
            'base_reward_amount' => $baseRewardAmount,
            'source_started_at' => $window['start'] ?? null,
            'source_ended_at' => $window['end'] ?? null,
            'requires_review_hold' => self::progressNeedsFraudHold($offer, $metric, $window, $userId),
        ];
    }

    private static function offerMatchesAudience(array $offer, array $user, array $package): bool
    {
        $audienceType = (string)($offer['audience_type'] ?? 'all_rewards');
        $audienceIds = self::decodeJsonList($offer['audience_json'] ?? null);
        $packageId = (int)($package['id'] ?? 0);
        $isPaid = strtolower((string)($package['level_type'] ?? 'free')) === 'paid';

        return match ($audienceType) {
            'all_rewards' => FeatureService::rewardsEnabled(),
            'all_affiliates' => FeatureService::rewardsEnabled() && FeatureService::affiliateEnabled(),
            'free_only' => !$isPaid,
            'premium_only' => $isPaid,
            'selected_packages' => in_array((string)$packageId, $audienceIds, true),
            'selected_users' => in_array((string)($user['id'] ?? 0), $audienceIds, true),
            default => false,
        };
    }

    private static function resolveWindow(array $offer): array
    {
        $window = ['start' => null, 'end' => null];
        $startAt = !empty($offer['start_at']) ? (string)$offer['start_at'] : null;
        $endAt = !empty($offer['end_at']) ? (string)$offer['end_at'] : null;
        $scheduleMode = (string)($offer['schedule_mode'] ?? 'always');
        $timezone = (string)($offer['timezone'] ?? 'UTC');

        if (in_array($scheduleMode, ['date_range', 'date_range_weekdays'], true)) {
            $window['start'] = $startAt;
            $window['end'] = $endAt;
        }

        if ($scheduleMode === 'date_range_weekdays') {
            $weekdayJson = self::decodeJsonList($offer['weekday_json'] ?? null);
            if ($weekdayJson !== []) {
                $ranges = self::buildWeekdayUtcRanges($startAt, $endAt, $timezone, $weekdayJson);
                if ($ranges === []) {
                    $window['force_inactive'] = true;
                } else {
                    $window['weekday_ranges'] = $ranges;
                }
            }
        }

        return $window;
    }

    private static function appendWindow(string $sql, array $params, string $column, array $window): array
    {
        if (!empty($window['force_inactive'])) {
            $sql .= " AND 1 = 0";
            return [$sql, $params];
        }

        if (!empty($window['weekday_ranges']) && is_array($window['weekday_ranges'])) {
            $parts = [];
            foreach ($window['weekday_ranges'] as $range) {
                if (empty($range['start']) || empty($range['end'])) {
                    continue;
                }
                $parts[] = "({$column} >= ? AND {$column} < ?)";
                $params[] = $range['start'];
                $params[] = $range['end'];
            }

            if ($parts === []) {
                $sql .= " AND 1 = 0";
            } else {
                $sql .= " AND (" . implode(' OR ', $parts) . ")";
            }
            return [$sql, $params];
        }

        if (!empty($window['start'])) {
            $sql .= " AND {$column} >= ?";
            $params[] = $window['start'];
        }
        if (!empty($window['end'])) {
            $sql .= " AND {$column} <= ?";
            $params[] = $window['end'];
        }
        return [$sql, $params];
    }

    private static function calculateAwardAmount(array $offer, array $progress, int $bucket, int $maxBucket, int $userId, array $window): float
    {
        $rewardType = (string)($offer['reward_type'] ?? 'fixed');
        $rewardValue = (float)($offer['reward_value'] ?? 0);
        $metric = (string)($offer['metric_key'] ?? 'rewarded_downloads');
        $threshold = (float)($offer['threshold_value'] ?? 0);

        if ($rewardType === 'fixed') {
            return round($rewardValue, 4);
        }

        $base = (float)($progress['base_reward_amount'] ?? 0);
        if ($metric === 'rewarded_downloads') {
            $base = self::rewardedDownloadsBucketBaseAmount($offer, $userId, $bucket, $window);
        } elseif ($metric === 'cleared_earnings_amount' && $threshold > 0) {
            $base = min($threshold, $base);
        }

        if ($base <= 0) {
            return 0.0;
        }

        if (!in_array($metric, ['rewarded_downloads', 'cleared_earnings_amount'], true) && $maxBucket > 1) {
            $base = $base / $maxBucket;
        }

        if ($rewardType === 'multiplier') {
            return round(max(0, $base * max(0, $rewardValue - 1)), 4);
        }

        if ($rewardType === 'percent') {
            return round(max(0, $base * ($rewardValue / 100)), 4);
        }

        return 0.0;
    }

    private static function rewardedDownloadsBucketBaseAmount(array $offer, int $userId, int $bucket, array $window): float
    {
        $thresholdCount = max(1, (int)floor((float)($offer['threshold_value'] ?? 0)));
        $offset = max(0, ($bucket - 1) * $thresholdCount);
        $countClearedOnly = (int)($offer['count_cleared_only'] ?? 1) === 1;
        $statuses = $countClearedOnly ? ['cleared', 'paid'] : ['held', 'cleared', 'paid', 'pending'];
        $statusList = "'" . implode("','", $statuses) . "'";

        $db = Database::getInstance()->getConnection();
        $sql = "
            SELECT COALESCE(SUM(t.amount), 0)
            FROM (
                SELECT amount
                FROM earnings
                WHERE user_id = ?
                  AND type = 'download_reward'
                  AND status IN ($statusList)
        ";
        [$sql, $params] = self::appendWindow($sql, [$userId], 'created_at', $window);
        $sql .= "
                ORDER BY created_at ASC, id ASC
                LIMIT ?, ?
            ) t
        ";
        $params[] = $offset;
        $params[] = $thresholdCount;

        $stmt = $db->prepare($sql);
        foreach ($params as $index => $value) {
            $paramIndex = $index + 1;
            if (is_int($value)) {
                $stmt->bindValue($paramIndex, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($paramIndex, $value);
            }
        }
        $stmt->execute();

        return (float)$stmt->fetchColumn();
    }

    private static function progressNeedsFraudHold(array $offer, string $metric, array $window, int $userId): bool
    {
        if ((int)($offer['fraud_hold'] ?? 1) !== 1) {
            return false;
        }

        if (in_array($metric, ['verified_referrals', 'premium_referrals'], true)) {
            return true;
        }

        if (!in_array($metric, ['rewarded_downloads', 'cleared_earnings_amount'], true)) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $types = $metric === 'rewarded_downloads'
            ? ['download_reward']
            : ['download_reward', 'pps_reward', 'referral'];

        $typeList = "'" . implode("','", $types) . "'";
        $sql = "SELECT COUNT(*) FROM earnings WHERE user_id = ? AND type IN ($typeList) AND status IN ('held', 'pending', 'flagged_review')";
        [$sql, $params] = self::appendWindow($sql, [$userId], 'created_at', $window);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn() > 0;
    }

    private static function countEligibleReferralUsers(array $referrer, array $referredRows): int
    {
        $eligible = 0;
        foreach ($referredRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (AffiliateRewardService::isReferralRelationshipEligible($referrer, $row)) {
                $eligible++;
            }
        }

        return $eligible;
    }

    private static function awardExists(string $awardKey): bool
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id FROM bonus_offer_awards WHERE award_key = ? LIMIT 1");
        $stmt->execute([$awardKey]);
        return (bool)$stmt->fetchColumn();
    }

    private static function formatProgressLabel(array $offer, float $progress): string
    {
        $threshold = (float)($offer['threshold_value'] ?? 0);
        $unit = (string)($offer['threshold_unit'] ?? 'count');
        return number_format($progress, $unit === 'currency' ? 2 : 0) . ' / ' . number_format($threshold, $unit === 'currency' ? 2 : 0) . ' ' . $unit;
    }

    public static function formatOfferSchedule(array $offer): string
    {
        $timezone = (string)($offer['timezone'] ?? 'UTC');
        $startAt = !empty($offer['start_at']) ? self::formatLocalDateTime((string)$offer['start_at'], $timezone) : null;
        $endAt = !empty($offer['end_at']) ? self::formatLocalDateTime((string)$offer['end_at'], $timezone) : null;
        $weekdays = self::decodeJsonList($offer['weekday_json'] ?? null);

        if ($startAt && $endAt) {
            $label = 'Active ' . $startAt . ' to ' . $endAt . ' (' . $timezone . ')';
        } elseif ($endAt) {
            $label = 'Ends ' . $endAt . ' (' . $timezone . ')';
        } elseif ($startAt) {
            $label = 'Starts ' . $startAt . ' (' . $timezone . ')';
        } else {
            $label = 'Available now';
        }

        if ($weekdays !== []) {
            $weekdayLabels = [];
            foreach ($weekdays as $weekday) {
                $weekdayLabels[] = self::definitions()['weekdays'][(string)$weekday] ?? $weekday;
            }
            $label .= ' on ' . implode(', ', $weekdayLabels);
        }

        return $label;
    }

    public static function formatRewardPreview(array $offer, array $progress): string
    {
        $rewardType = (string)($offer['reward_type'] ?? 'fixed');
        $rewardValue = (float)($offer['reward_value'] ?? 0);
        return match ($rewardType) {
            'multiplier' => rtrim(rtrim(number_format($rewardValue, 2), '0'), '.') . 'x qualifying earnings bonus',
            'percent' => rtrim(rtrim(number_format($rewardValue, 2), '0'), '.') . '% qualifying earnings bonus',
            default => '$' . number_format($rewardValue, 2) . ' cash bonus',
        };
    }

    public static function formatUserGoalSummary(array $offer): string
    {
        $metric = (string)($offer['metric_key'] ?? 'rewarded_downloads');
        $threshold = self::formatThresholdValue((float)($offer['threshold_value'] ?? 0), (string)($offer['threshold_unit'] ?? 'count'));
        $triggerStyle = (string)($offer['trigger_style'] ?? 'once');

        $goal = match ($metric) {
            'approved_payouts' => $threshold . ' approved payout' . ((float)($offer['threshold_value'] ?? 0) === 1.0 ? '' : 's'),
            'uploaded_files' => $threshold . ' upload' . ((float)($offer['threshold_value'] ?? 0) === 1.0 ? '' : 's'),
            'rewarded_downloads' => $threshold . ' rewarded download' . ((float)($offer['threshold_value'] ?? 0) === 1.0 ? '' : 's'),
            'cleared_earnings_amount' => self::formatThresholdCurrency((float)($offer['threshold_value'] ?? 0)) . ' in cleared earnings',
            'verified_referrals' => $threshold . ' verified referral' . ((float)($offer['threshold_value'] ?? 0) === 1.0 ? '' : 's'),
            'premium_referrals' => $threshold . ' premium referral' . ((float)($offer['threshold_value'] ?? 0) === 1.0 ? '' : 's'),
            'no_fraud_reversal_days' => $threshold . ' day' . ((float)($offer['threshold_value'] ?? 0) === 1.0 ? '' : 's') . ' with no fraud reversals',
            default => $threshold . ' ' . trim((string)($offer['threshold_unit'] ?? '')),
        };

        if ($triggerStyle === 'every_multiple') {
            return 'Earn this again every time you reach another ' . $goal . '.';
        }

        return 'Earn this when you reach ' . $goal . '.';
    }

    public static function formatUserProgress(array $offer): string
    {
        $progress = (float)($offer['progress_value'] ?? 0);
        $threshold = (float)($offer['threshold_value'] ?? 0);
        $unit = (string)($offer['threshold_unit'] ?? 'count');

        return self::formatThresholdValue($progress, $unit) . ' of ' . self::formatThresholdValue($threshold, $unit) . ' ' . $unit;
    }

    public static function formatUserAwardMode(array $offer): string
    {
        return ((string)($offer['award_mode'] ?? 'pending_review') === 'auto_credit')
            ? 'Credits automatically'
            : 'Needs admin approval';
    }

    private static function formatThresholdValue(float $value, string $unit): string
    {
        if (in_array(strtolower($unit), ['usd', 'currency', 'dollars'], true)) {
            return self::formatThresholdCurrency($value);
        }

        if (floor($value) === $value) {
            return number_format($value, 0);
        }

        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    private static function formatThresholdCurrency(float $value): string
    {
        return '$' . number_format($value, floor($value) === $value ? 0 : 2);
    }

    private static function normalizeAudienceIds(string $raw): array
    {
        $pieces = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $ids = [];
        foreach ($pieces as $piece) {
            $value = (string)(int)$piece;
            if ($value !== '0') {
                $ids[] = $value;
            }
        }
        return array_values(array_unique($ids));
    }

    private static function decodeJsonList(?string $json): array
    {
        if (!$json) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    private static function lookupReferralReferrers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }

        $db = Database::getInstance()->getConnection();
        User::ensureRuntimeColumns($db);
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $db->prepare("
            SELECT DISTINCT referrer_id
            FROM users
            WHERE id IN ($placeholders)
              AND referrer_id IS NOT NULL
              AND referrer_id > 0
              AND COALESCE(referrer_source, '') = 'referral'
        ");
        $stmt->execute($userIds);

        return array_values(array_unique(array_map('intval', array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), static fn ($value): bool => (int)$value > 0))));
    }

    private static function normalizeEnum(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function buildWeekdayUtcRanges(?string $startAt, ?string $endAt, string $timezone, array $weekdayJson): array
    {
        if (!$startAt || !$endAt || $weekdayJson === []) {
            return [];
        }

        $tz = new \DateTimeZone($timezone);
        $utc = new \DateTimeZone('UTC');
        $startUtc = new \DateTimeImmutable($startAt, $utc);
        $endUtc = new \DateTimeImmutable($endAt, $utc);
        if ($endUtc <= $startUtc) {
            return [];
        }

        $localStart = $startUtc->setTimezone($tz);
        $localEnd = $endUtc->setTimezone($tz);
        $cursor = $localStart->setTime(0, 0, 0);
        $lastDay = $localEnd->setTime(0, 0, 0);
        $ranges = [];

        while ($cursor <= $lastDay) {
            if (in_array($cursor->format('w'), $weekdayJson, true)) {
                $dayStart = $cursor;
                $dayEnd = $cursor->modify('+1 day');
                $rangeStart = $dayStart > $localStart ? $dayStart : $localStart;
                $rangeEnd = $dayEnd < $localEnd ? $dayEnd : $localEnd;
                if ($rangeEnd > $rangeStart) {
                    $ranges[] = [
                        'start' => $rangeStart->setTimezone($utc)->format('Y-m-d H:i:s'),
                        'end' => $rangeEnd->setTimezone($utc)->format('Y-m-d H:i:s'),
                    ];
                }
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $ranges;
    }

    private static function localDateTimeToUtc(string $value, string $timezone): ?string
    {
        if ($value === '') {
            return null;
        }

        $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, new \DateTimeZone($timezone));
        if (!$local) {
            return null;
        }

        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function formatLocalDateTime(string $utcValue, string $timezone): string
    {
        $dt = new \DateTimeImmutable($utcValue, new \DateTimeZone('UTC'));
        return $dt->setTimezone(new \DateTimeZone($timezone))->format('M d, Y g:i A');
    }

    private static function generatePublicId(PDO $db, string $table, string $column): string
    {
        do {
            $publicId = substr(bin2hex(random_bytes(8)), 0, 16);
            $stmt = $db->prepare("SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1");
            $stmt->execute([$publicId]);
        } while ($stmt->fetchColumn());

        return $publicId;
    }
}
