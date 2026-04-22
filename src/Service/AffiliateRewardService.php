<?php

namespace App\Service;

use App\Model\Setting;

class AffiliateRewardService
{
    public static function awardReferralForUserEarning($db, int $earnedUserId, float $earnedAmount, int $parentEarningId, string $parentStatus = 'cleared', ?string $parentHoldUntil = null, ?string $contextDescription = null): void
    {
        if (!FeatureService::affiliateEnabled() || $earnedUserId <= 0 || $earnedAmount <= 0 || $parentEarningId <= 0) {
            return;
        }

        $userStmt = $db->prepare("SELECT id, referrer_id, referrer_source FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$earnedUserId]);
        $user = $userStmt->fetch();
        if (!$user || empty($user['referrer_id']) || (string)($user['referrer_source'] ?? '') !== 'referral') {
            return;
        }

        $referrerId = (int)$user['referrer_id'];
        if ($referrerId <= 0 || $referrerId === $earnedUserId) {
            return;
        }

        $percent = max(0, min(100, (int)Setting::get('referral_commission_percent', '50', 'rewards')));
        if ($percent <= 0) {
            return;
        }

        $amount = round($earnedAmount * ($percent / 100), 4);
        if ($amount <= 0) {
            return;
        }

        $description = self::childDescription($parentEarningId, $contextDescription);
        $exists = $db->prepare("SELECT id FROM earnings WHERE user_id = ? AND type = 'referral' AND description = ? LIMIT 1");
        $exists->execute([$referrerId, $description]);
        if ($exists->fetchColumn()) {
            return;
        }

        $insert = $db->prepare("
            INSERT INTO earnings (user_id, amount, type, status, description, hold_until, metadata)
            VALUES (?, ?, 'referral', ?, ?, ?, ?)
        ");
        $insert->execute([
            $referrerId,
            $amount,
            self::normalizeStatus($parentStatus),
            $description,
            self::normalizeHoldUntilForStatus($parentStatus, $parentHoldUntil),
            json_encode([
                'parent_earning_id' => $parentEarningId,
                'earned_user_id' => $earnedUserId,
                'kind' => 'referral_child',
            ], JSON_UNESCAPED_SLASHES),
        ]);
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
                description = ?
                OR description LIKE ?
              )
        ");
        $stmt->execute([
            $normalizedStatus,
            $normalizedHoldUntil,
            $baseDescription,
            $baseDescription . ' (%)',
        ]);
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
}
