<?php

namespace App\Service;

use App\Model\Setting;

class AffiliateRewardService
{
    public static function accountRelationshipReasons(array $primary, array $secondary): array
    {
        $primaryId = (int)($primary['id'] ?? 0);
        $secondaryId = (int)($secondary['id'] ?? 0);
        if ($primaryId <= 0 || $secondaryId <= 0) {
            return ['Account relationship is missing a valid account.'];
        }

        $reasons = [];
        if ($primaryId === $secondaryId) {
            $reasons[] = 'The same account cannot approve or benefit from its own linked relationship.';
        }

        $primaryEmailLookup = trim((string)($primary['email_lookup'] ?? ''));
        $secondaryEmailLookup = trim((string)($secondary['email_lookup'] ?? ''));
        if ($primaryEmailLookup !== '' && $primaryEmailLookup === $secondaryEmailLookup) {
            $reasons[] = 'Accounts share the same email fingerprint.';
        }

        $primaryPayoutFingerprint = self::payoutFingerprint($primary);
        $secondaryPayoutFingerprint = self::payoutFingerprint($secondary);
        if ($primaryPayoutFingerprint !== '' && $primaryPayoutFingerprint === $secondaryPayoutFingerprint) {
            $reasons[] = 'Accounts share the same payout destination.';
        }

        return array_values(array_unique($reasons));
    }

    public static function accountsAppearLinked(array $primary, array $secondary): bool
    {
        return self::accountRelationshipReasons($primary, $secondary) !== [];
    }

    public static function referralRelationshipReasons(array $referrer, array $referred): array
    {
        $referrerId = (int)($referrer['id'] ?? 0);
        $referredId = (int)($referred['id'] ?? 0);
        if ($referrerId <= 0 || $referredId <= 0) {
            return ['Referral relationship is missing a valid account.'];
        }

        $reasons = [];
        foreach (self::accountRelationshipReasons($referrer, $referred) as $reason) {
            if ($reason === 'The same account cannot approve or benefit from its own linked relationship.') {
                $reasons[] = 'Self-referrals are not eligible.';
                continue;
            }
            if ($reason === 'Accounts share the same email fingerprint.') {
                $reasons[] = 'Referrer and referred account share the same email fingerprint.';
                continue;
            }
            if ($reason === 'Accounts share the same payout destination.') {
                $reasons[] = 'Referrer and referred account share the same payout destination.';
                continue;
            }
            $reasons[] = $reason;
        }

        $referrerStatus = strtolower(trim((string)($referrer['status'] ?? 'active')));
        $referredStatus = strtolower(trim((string)($referred['status'] ?? 'active')));
        if ($referrerStatus !== 'active') {
            $reasons[] = 'The referring account is not active.';
        }
        if ($referredStatus !== 'active') {
            $reasons[] = 'The referred account is not active.';
        }

        if ((int)($referrer['referrer_id'] ?? 0) === $referredId) {
            $reasons[] = 'Referrer and referred account are in a reciprocal referral chain.';
        }

        return array_values(array_unique($reasons));
    }

    public static function isReferralRelationshipEligible(array $referrer, array $referred): bool
    {
        return self::referralRelationshipReasons($referrer, $referred) === [];
    }

    public static function awardReferralForUserEarning($db, int $earnedUserId, float $earnedAmount, int $parentEarningId, string $parentStatus = 'cleared', ?string $parentHoldUntil = null, ?string $contextDescription = null): ?int
    {
        if (!FeatureService::affiliateEnabled() || $earnedUserId <= 0 || $earnedAmount <= 0 || $parentEarningId <= 0) {
            return null;
        }

        $userStmt = $db->prepare("SELECT id, referrer_id, referrer_source, status, email_lookup, payment_method, payment_details FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$earnedUserId]);
        $user = $userStmt->fetch();
        if (!$user || empty($user['referrer_id']) || (string)($user['referrer_source'] ?? '') !== 'referral') {
            return null;
        }

        $referrerId = (int)$user['referrer_id'];
        if ($referrerId <= 0 || $referrerId === $earnedUserId) {
            return null;
        }

        $referrerStmt = $db->prepare("SELECT id, referrer_id, status, email_lookup, payment_method, payment_details FROM users WHERE id = ? LIMIT 1");
        $referrerStmt->execute([$referrerId]);
        $referrer = $referrerStmt->fetch();
        if (!$referrer || !self::isReferralRelationshipEligible($referrer, $user)) {
            return null;
        }

        $percent = max(0, min(100, (int)Setting::get('referral_commission_percent', '50', 'rewards')));
        if ($percent <= 0) {
            return $referrerId;
        }

        $amount = round($earnedAmount * ($percent / 100), 4);
        if ($amount <= 0) {
            return $referrerId;
        }

        $description = self::childDescription($parentEarningId, $contextDescription);
        $lockKey = self::referralAwardLockKey($referrerId, $parentEarningId);
        if (!self::acquireReferralAwardLock($db, $lockKey)) {
            throw new \RuntimeException('Could not acquire referral commission lock.');
        }

        try {
            $exists = $db->prepare("SELECT id FROM earnings WHERE user_id = ? AND type = 'referral' AND parent_earning_id = ? LIMIT 1");
            $exists->execute([$referrerId, $parentEarningId]);
            if ($exists->fetchColumn()) {
                return $referrerId;
            }

            $insert = $db->prepare("
                INSERT INTO earnings (user_id, amount, type, status, description, hold_until, parent_earning_id, metadata)
                VALUES (?, ?, 'referral', ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $referrerId,
                $amount,
                self::normalizeStatus($parentStatus),
                $description,
                self::normalizeHoldUntilForStatus($parentStatus, $parentHoldUntil),
                $parentEarningId,
                json_encode([
                    'parent_earning_id' => $parentEarningId,
                    'earned_user_id' => $earnedUserId,
                    'kind' => 'referral_child',
                ], JSON_UNESCAPED_SLASHES),
            ]);
        } finally {
            self::releaseReferralAwardLock($db, $lockKey);
        }

        return $referrerId;
    }

    public static function syncReferralChildrenForParent($db, int $parentEarningId, string $targetStatus, ?string $holdUntil = null): void
    {
        if ($parentEarningId <= 0) {
            return;
        }

        $normalizedStatus = self::normalizeStatus($targetStatus);
        $normalizedHoldUntil = self::normalizeHoldUntilForStatus($normalizedStatus, $holdUntil);

        $baseDescription = self::childDescription($parentEarningId);
        $stmt = $db->prepare("
            UPDATE earnings
            SET status = ?, hold_until = ?
            WHERE type = 'referral'
              AND (
                parent_earning_id = ?
                OR description = ?
                OR description LIKE ?
              )
        ");
        $stmt->execute([
            $normalizedStatus,
            $normalizedHoldUntil,
            $parentEarningId,
            $baseDescription,
            $baseDescription . ' (%',
        ]);
    }

    public static function reverseReferralChildrenForParent($db, int $parentEarningId, string $reason, ?int $reviewerId = null): array
    {
        if ($parentEarningId <= 0) {
            return [];
        }

        $baseDescription = self::childDescription($parentEarningId);
        $stmt = $db->prepare("
            SELECT id, user_id, file_id, session_id, parent_earning_id, type, amount, ip_hash, risk_score,
                   risk_reasons_json, review_note, country_code, network_type, asn, metadata, status
            FROM earnings
            WHERE type = 'referral'
              AND (
                parent_earning_id = ?
                OR description = ?
                OR description LIKE ?
              )
        ");
        $stmt->execute([
            $parentEarningId,
            $baseDescription,
            $baseDescription . ' (%',
        ]);
        $rows = $stmt->fetchAll() ?: [];
        if ($rows === []) {
            return [];
        }

        $cancel = $db->prepare("
            UPDATE earnings
            SET status = 'cancelled', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, review_note = ?, hold_until = NULL
            WHERE id = ? AND status = ?
        ");

        $touchUserIds = [];
        foreach ($rows as $row) {
            $currentStatus = strtolower(trim((string)($row['status'] ?? '')));
            if (in_array($currentStatus, ['reversed', 'cancelled'], true)) {
                continue;
            }

            if (in_array($currentStatus, ['cleared', 'paid'], true)) {
                $reversal = RewardService::ensureLedgerReversalEntry(
                    $db,
                    $row,
                    $reason,
                    $reviewerId,
                    [
                        'source' => 'referral_parent_reversal',
                        'source_parent_earning_id' => $parentEarningId,
                    ]
                );
                if (($reversal['id'] ?? 0) > 0) {
                    $touchUserIds[] = (int)($row['user_id'] ?? 0);
                }
                continue;
            }

            $cancel->execute([
                $reviewerId,
                trim($reason),
                (int)($row['id'] ?? 0),
                (string)($row['status'] ?? ''),
            ]);
            if ($cancel->rowCount() === 1) {
                $touchUserIds[] = (int)($row['user_id'] ?? 0);
            }
        }

        return array_values(array_unique(array_filter($touchUserIds, static fn (int $id): bool => $id > 0)));
    }

    public static function childDescription(int $parentEarningId, ?string $contextDescription = null): string
    {
        $base = 'Referral commission for earning #' . $parentEarningId;
        $contextDescription = trim((string)$contextDescription);
        if ($contextDescription === '') {
            return $base;
        }

        return $base . ' (' . $contextDescription . ')';
    }

    private static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['held', 'flagged_review', 'cleared', 'reversed', 'paid', 'cancelled', 'pending'], true)
            ? $status
            : 'held';
    }

    private static function normalizeHoldUntilForStatus(string $status, ?string $holdUntil): ?string
    {
        return $status === 'held' ? $holdUntil : null;
    }

    private static function referralAwardLockKey(int $referrerId, int $parentEarningId): string
    {
        return 'affiliate_referral:' . $referrerId . ':' . $parentEarningId;
    }

    private static function acquireReferralAwardLock($db, string $lockKey): bool
    {
        $stmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $stmt->execute([$lockKey]);
        return (bool)$stmt->fetchColumn();
    }

    private static function releaseReferralAwardLock($db, string $lockKey): void
    {
        try {
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute([$lockKey]);
        } catch (\Throwable $e) {
        }
    }

    private static function payoutFingerprint(array $user): string
    {
        $method = strtolower(trim((string)($user['payment_method'] ?? '')));
        $details = trim((string)EncryptionService::decrypt((string)($user['payment_details'] ?? '')));
        if ($method === '' || $details === '') {
            return '';
        }

        return $method . '|' . strtolower(self::collapseWhitespace($details));
    }

    private static function collapseWhitespace(string $value): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $value));
    }
}
