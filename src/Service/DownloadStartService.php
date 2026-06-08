<?php

namespace App\Service;

use App\Core\Database;
use App\Model\File;
use App\Model\Package;

class DownloadStartService
{
    public function wouldMutateState(array $file, ?int $viewerId = null, ?array $validatedSession = null): bool
    {
        $ownerId = !empty($file['user_id']) ? (int)$file['user_id'] : null;
        if ($viewerId !== null && $ownerId !== null && $viewerId === $ownerId) {
            return false;
        }

        if (is_array($validatedSession) && !empty($validatedSession['download_counted_at'])) {
            return false;
        }

        return true;
    }

    public function commit(array $file, ?int $viewerId = null, ?int $downloadSessionId = null): bool
    {
        $ownerId = !empty($file['user_id']) ? (int)$file['user_id'] : null;
        if ($viewerId !== null && $ownerId !== null && $viewerId === $ownerId) {
            return false;
        }

        $fileId = (int)($file['id'] ?? 0);
        if ($fileId <= 0) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $startedTransaction = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $startedTransaction = true;
        }

        try {
            if ($downloadSessionId !== null && $downloadSessionId > 0) {
                $counted = (new RewardFraudService())->claimDownloadCountForSessionId($downloadSessionId);
                if (!$counted) {
                    if ($startedTransaction && $db->inTransaction()) {
                        $db->rollBack();
                    }
                    return false;
                }
            }

            File::incrementDownloads($fileId);

            if ($ownerId !== null && $ownerId > 0) {
                $ownerPackage = Package::getUserPackage($ownerId);
            } else {
                $ownerPackage = Package::getGuestPackage();
            }

            $expiryDays = (int)($ownerPackage['file_expiry_days'] ?? 0);
            if ($expiryDays > 0) {
                $newDeleteAt = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));
                $db->prepare("UPDATE files SET delete_at = ? WHERE id = ?")->execute([$newDeleteAt, $fileId]);
            } else {
                $db->prepare("UPDATE files SET delete_at = NULL WHERE id = ?")->execute([$fileId]);
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
