<?php

namespace App\Service;

use App\Core\Database;

final class ReviewIntegrityService
{
    public static function assertNotSelfWithdrawalReview(?int $reviewerId, int $ownerUserId): void
    {
        self::assertNoLinkedConflict(
            $reviewerId,
            $ownerUserId,
            'You cannot approve, reject, or mark your own withdrawal requests as paid.',
            'You cannot approve, reject, or mark withdrawal requests as paid for an account linked to your own email fingerprint or payout destination.'
        );
    }

    public static function assertNotSelfRewardReview(?int $reviewerId, int $ownerUserId): void
    {
        self::assertNoLinkedConflict(
            $reviewerId,
            $ownerUserId,
            'You cannot review or clear your own reward earnings.',
            'You cannot review or clear reward earnings for an account linked to your own email fingerprint or payout destination.'
        );
    }

    public static function assertNotSelfBonusAwardReview(?int $reviewerId, int $ownerUserId): void
    {
        self::assertNoLinkedConflict(
            $reviewerId,
            $ownerUserId,
            'You cannot review or credit your own bonus awards.',
            'You cannot review or credit bonus awards for an account linked to your own email fingerprint or payout destination.'
        );
    }

    public static function assertNotSelfTrustTierChange(?int $reviewerId, int $ownerUserId): void
    {
        self::assertNoLinkedConflict(
            $reviewerId,
            $ownerUserId,
            'You cannot change your own reward trust tier.',
            'You cannot change reward trust tiers for an account linked to your own email fingerprint or payout destination.'
        );
    }

    public static function assertNotSelfManualSubscriptionGrant(?int $reviewerId, int $ownerUserId): void
    {
        self::assertNoLinkedConflict(
            $reviewerId,
            $ownerUserId,
            'You cannot create a manual premium subscription for your own account.',
            'You cannot create a manual premium subscription for an account linked to your own email fingerprint or payout destination.'
        );
    }

    public static function assertNotSelfManualCredit(?int $reviewerId, int $ownerUserId): void
    {
        self::assertNoLinkedConflict(
            $reviewerId,
            $ownerUserId,
            'You cannot issue manual credit to your own account.',
            'You cannot issue manual credit to an account linked to your own email fingerprint or payout destination.'
        );
    }

    private static function assertDistinctUsers(?int $reviewerId, int $ownerUserId, string $message): void
    {
        $reviewerId = (int)($reviewerId ?? 0);
        if ($reviewerId > 0 && $ownerUserId > 0 && $reviewerId === $ownerUserId) {
            throw new \RuntimeException($message);
        }
    }

    private static function assertNoLinkedConflict(?int $reviewerId, int $ownerUserId, string $selfMessage, string $linkedMessage): void
    {
        self::assertDistinctUsers($reviewerId, $ownerUserId, $selfMessage);

        $reviewerId = (int)($reviewerId ?? 0);
        if ($reviewerId <= 0 || $ownerUserId <= 0) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT id, email_lookup, payment_method, payment_details
            FROM users
            WHERE id IN (?, ?)
        ");
        $stmt->execute([$reviewerId, $ownerUserId]);

        $identities = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $identities[(int)($row['id'] ?? 0)] = $row;
        }

        if (
            !empty($identities[$reviewerId])
            && !empty($identities[$ownerUserId])
            && AffiliateRewardService::accountsAppearLinked($identities[$reviewerId], $identities[$ownerUserId])
        ) {
            throw new \RuntimeException($linkedMessage);
        }
    }
}
