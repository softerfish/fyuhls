<?php

namespace App\Service;

use App\Core\Database;

class NotificationService
{
    /**
     * Send a notification to a user
     */
    public static function send(int $userId, string $title, string $message, string $type = 'info'): void
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $title, $message, $type]);
    }

    /**
     * Get unread notifications for a user
     */
    public static function getUnread(int $userId): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Mark all notifications as read for a user
     */
    public static function markAllRead(int $userId): void
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$userId]);
    }
}
