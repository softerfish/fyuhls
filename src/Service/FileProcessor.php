<?php

namespace App\Service;

use App\Core\StorageManager;
use App\Model\StoredFile;
use App\Model\File;
use App\Model\Setting;

class FileProcessor {
    // No longer injecting StorageProvider in constructor, as we select it dynamically

    /**
     * process a complete upload (after chunk assembly)
     * 
     * @throws \Exception
     */
    public function processUpload(string $tempFilePath, string $originalFilename, int $userId, int|string|null $folderId = null): array {
        $fileSize = filesize($tempFilePath);
        \App\Core\Logger::info("Processing complete upload", ['file' => $originalFilename, 'size' => $fileSize, 'user' => $userId]);

        $db = \App\Core\Database::getInstance()->getConnection();

        // Resolve folderId if it's a slug
        if ($folderId && !is_numeric($folderId)) {
            $folder = \App\Model\Folder::find($folderId);
            $folderId = $folder ? (int)$folder['id'] : null;
        } else {
            $folderId = $folderId ? (int)$folderId : null;
        }

        // security: use explicit allowlist for both extension and mime type.
        // blocklists aren't enough; there's just too many bad types to block.
        $allowedSetting = \App\Model\Setting::get('upload_allowed_extensions', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt,zip,mp4,mp3,ipa,apk');
        $allowedExtensions = array_map('trim', explode(',', strtolower($allowedSetting)));
        
        // Remove empty values if user left stray commas
        $allowedExtensions = array_filter($allowedExtensions);
        
        // security: always lowercase the extension before checking
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        // check extensions based on admin settings
        if (!in_array($ext, $allowedExtensions)) {
            unlink($tempFilePath);
            $allowedStr = implode(', ', $allowedExtensions);
            throw new \Exception("Security Error: file type (.$ext) is not allowed. Allowed extensions are: [$allowedStr]. Check your Settings.");
        }

        // enforce max upload size based on user package
        $package = $userId ? \App\Model\Package::getUserPackage($userId) : \App\Model\Package::getGuestPackage();
        $maxSize = $package['max_upload_size'] > 0 ? (int)$package['max_upload_size'] : PHP_INT_MAX;

        if (filesize($tempFilePath) > $maxSize) {
            unlink($tempFilePath);
            throw new \Exception("file is too big for your account's package limit.");
        }

        if ($userId && !empty($package['max_storage_bytes']) && (float)$package['max_storage_bytes'] > 0) {
            $stmt = $db->prepare("SELECT storage_used FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $storageUsed = (float)$stmt->fetchColumn();
            $maxStorage = (float)$package['max_storage_bytes'];

            if (($storageUsed + $fileSize) > $maxStorage) {
                unlink($tempFilePath);
                throw new \Exception("Storage quota exceeded for your account package.");
            }
        }

        // calculate hash (deduplication check)
        $hash = hash_file('sha256', $tempFilePath);
        $fileSize = filesize($tempFilePath);
        \App\Core\Logger::info("Processing upload", ['filename' => $originalFilename, 'tmp' => $tempFilePath, 'size' => $fileSize]);
        
        // use global mime_content_type
        if (function_exists('mime_content_type')) {
            $mimeType = \mime_content_type($tempFilePath);
        } else {
            $mimeType = 'application/octet-stream';
        }

        // check if file already exists globally (this keeps things clean in the db)
        $existingStoredFile = null;
        $collidingFile = null;
        $dupeCheckEnabled = \App\Model\Setting::get('upload_detect_duplicates', '1') === '1';
        
        // we always find by hash to avoid sql errors, even if dupe detection is "off" in the ui
        $found = StoredFile::findByHash($hash);
        if ($found) {
            // double check: hash and size must match. 
            // if hash matches but size doesn't, the existing file is likely broken or a partial upload.
            if ((int)$found['file_size'] === (int)$fileSize) {
                // Only treat as a "hit" if the setting is actually on
                if ($dupeCheckEnabled) {
                    $existingStoredFile = $found;
                    \App\Core\Logger::info("Deduplication hit (Hash: $hash, Size: $fileSize)");
                } else {
                    \App\Core\Logger::info("Hash match found, but deduplication is disabled. Using existing storage anyway to avoid SQL error.", ['hash' => $hash]);
                    $existingStoredFile = $found;
                }
            } else {
                \App\Core\Logger::warning("Hash match but size mismatch. Repairing corrupted duplicate record.", [
                    'hash' => $hash,
                    'recorded_size' => $found['file_size'],
                    'actual_size' => $fileSize
                ]);
                $collidingFile = $found;
            }
        }

        // pick the right storage server from the file_servers table
        [$providerKey, $storage, $fileServerId] = StorageManager::resolveFromDb($db);
        $providerName = $storage->getName();
        \App\Core\Logger::info("Resolved storage provider", ['provider' => $providerKey, 'server_id' => $fileServerId]);

        // generate a secure path: yyyy/mm/hash.ext
        // security: always lowercase the stored extension so 'evil.PHP' can't sneak past
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $relativePath = date('Y/m') . '/' . $hash . '.' . $ext;
        
        // thumbnails (images/videos)
        $thumbRelative = 'thumbnails/' . date('Y/m') . '/' . $hash . '.jpg';
        $thumbCreated = false;
        $storedFileId = 0;
        \App\Core\Logger::info("Deduplication check", ['hash' => $hash, 'size' => $fileSize]);
        if ($existingStoredFile) {
            // Fix: we must check existence on the original provider, not the current default!
            $originalProvider = StorageManager::getProviderById($existingStoredFile['file_server_id'], $db);
            
            // verify the file actually exists on the storage provider
            // if it doesn't, we treat it as a collision and force a re-upload to repair the record.
            if ($originalProvider->exists($existingStoredFile['storage_path'])) {
                \App\Core\Logger::info("Deduplication hit (Storage Verified)", ['hash' => $hash, 'existing_id' => $existingStoredFile['id'], 'provider' => $existingStoredFile['storage_provider']]);
                // just link to existing physical file
                StoredFile::incrementRefCount($existingStoredFile['id']);
                $storedFileId = $existingStoredFile['id'];
                
                // delete temp file as we don't need it
                if (file_exists($tempFilePath)) {
                    unlink($tempFilePath);
                }
            } else {
                \App\Core\Logger::warning("Deduplication hit in DB, but file MISSING from storage. Repairing ghost record.", ['hash' => $hash, 'path' => $existingStoredFile['storage_path']]);
                $collidingFile = $existingStoredFile;
                $existingStoredFile = null;
            }
        }
        
        if (!$existingStoredFile) {
            \App\Core\Logger::info("Deduplication miss or repair needed, saving to storage", ['hash' => $hash, 'size' => $fileSize, 'provider' => $providerKey]);

            if (str_starts_with($mimeType, 'image/')) {
                $thumbCreated = $this->createImageThumbnail($tempFilePath, sys_get_temp_dir() . '/' . $hash . '_thumb.jpg');
            } elseif (str_starts_with($mimeType, 'video/')) {
                // check DB setting first, then config file fallback
                $ffmpegEnabled = Setting::getOrConfig('video.ffmpeg_enabled', '1');
                $ffmpeg = Setting::getOrConfig('video.ffmpeg_path', '');
                if ($ffmpegEnabled === '1' && !empty($ffmpeg)) {
                    $thumbCreated = $this->createVideoThumbnail($tempFilePath, sys_get_temp_dir() . '/' . $hash . '_thumb.jpg', $ffmpeg);
                }
            }

            if (!$storage->save($tempFilePath, $relativePath)) {
                throw new \Exception("failed to save file to storage ($providerName).");
            }
            \App\Core\Logger::info("File saved to storage provider", ['provider' => $providerKey, 'path' => $relativePath]);

            // save thumbnail if we got one
            if ($thumbCreated) {
                $tmpThumb = sys_get_temp_dir() . '/' . $hash . '_thumb.jpg';
                $storage->save($tmpThumb, $thumbRelative);
                \App\Core\Logger::info("Thumbnail saved to storage provider", ['provider' => $providerKey, 'path' => $thumbRelative]);
                if (file_exists($tmpThumb)) {
                    unlink($tmpThumb);
                    \App\Core\Logger::info("Deleted temporary thumbnail file", ['path' => $tmpThumb]);
                }
            }

            if ($collidingFile) {
                // Repair the existing record to avoid Duplicate Entry error
                StoredFile::update($collidingFile['id'], [
                    'storage_provider' => $providerKey,
                    'storage_path' => $relativePath,
                    'file_size' => $fileSize,
                    'mime_type' => $mimeType,
                    'file_server_id' => $fileServerId ?? null
                ]);

                // also release usage on the old server (if it was different)
                if ($collidingFile['file_server_id'] && $collidingFile['file_server_id'] != $fileServerId) {
                    StorageManager::releaseUsage($db, $collidingFile['file_server_id'], $collidingFile['file_size']);
                    \App\Core\Logger::info("Released usage on old missing server", ['server_id' => $collidingFile['file_server_id']]);
                }

                \App\Core\Logger::info("Collision repaired in database", ['id' => $collidingFile['id']]);
                // Ensure ref_count is at least 1 and then increment for this new association
                // Actually, if we are part of a normal processUpload flow, we need to add 1 ref.
                StoredFile::incrementRefCount($collidingFile['id']);
                $storedFileId = $collidingFile['id'];
            } else {
                // create new storedfile entry
                $storedFileId = StoredFile::create($hash, $providerKey, $relativePath, $fileSize, $mimeType, $fileServerId ?? null);
            }

            // bump per-server usage stats
            if ($fileServerId) {
                StorageManager::recordUsage($db, $fileServerId, $fileSize);
            }
            
            // cleanup temp file
            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
        }

        // delete_at is not set on upload - expiry is based on last download, not upload time
        $deleteAt = null;

        // Get User Privacy Preference
        $isPublic = 1;
        if ($userId) {
            $db = \App\Core\Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT default_privacy FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $pref = $stmt->fetchColumn();
            $isPublic = ($pref === 'private') ? 0 : 1;
        }

        // create user file entry
        $fileId = File::create($userId, $storedFileId, $originalFilename, $folderId, $deleteAt, $isPublic);
        \App\Core\Auth::logActivity('upload', "Uploaded file: " . $originalFilename . " (ID: " . $fileId . ")");

        // update user storage_used
        if ($userId) {
            $db = \App\Core\Database::getInstance()->getConnection();
            $db->prepare("UPDATE users SET storage_used = storage_used + ? WHERE id = ?")->execute([$fileSize, $userId]);
        }

        // check for storage quota warning
        if ($userId) {
            $this->checkStorageQuotaWarning($userId);
        }

        return [
            'file_id' => $fileId,
            'status' => 'success',
            'deduplicated' => (bool)$existingStoredFile
        ];
    }

    private function checkStorageQuotaWarning(int $userId): void {
        $db = \App\Core\Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT username, email, storage_used, storage_warning_threshold, storage_warning_sent, package_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || $user['storage_warning_sent']) return;

        $package = \App\Model\Package::find($user['package_id']);
        $maxStorage = $package ? (float)$package['max_storage_bytes'] : 0;

        if ($maxStorage <= 0) return;

        $threshold = (int)$user['storage_warning_threshold'];
        $usagePercent = ($user['storage_used'] / $maxStorage) * 100;

        if ($usagePercent >= $threshold) {
            $email = \App\Service\EncryptionService::decrypt($user['email']);
            $username = \App\Service\EncryptionService::decrypt($user['username']);

            $sent = \App\Service\MailService::sendTemplate($email, 'storage_limit_warning', [
                '{username}' => $username,
                '{usage_percent}' => round($usagePercent, 1),
                '{threshold}' => $threshold,
                '{max_storage}' => self::formatSize($maxStorage)
            ]);

            if ($sent) {
                $db->prepare("UPDATE users SET storage_warning_sent = 1 WHERE id = ?")->execute([$userId]);
            }
        }
    }

    private function createImageThumbnail(string $source, string $dest): bool {
        $dims = \App\Core\Config::get('thumbnail', ['max_width' => 320, 'max_height' => 240, 'quality' => 80]);
        $info = @getimagesize($source);
        if (!$info) return false;
        [$width, $height] = $info;
        $mime = $info['mime'] ?? '';
        $ratio = min($dims['max_width'] / $width, $dims['max_height'] / $height, 1);
        $newW = (int)floor($width * $ratio);
        $newH = (int)floor($height * $ratio);
        if (!function_exists('imagecreatetruecolor')) return false;
        $dst = imagecreatetruecolor($newW, $newH);
        switch ($mime) {
            case 'image/jpeg':
                if (!function_exists('imagecreatefromjpeg')) return false;
                $src = @imagecreatefromjpeg($source);
                break;
            case 'image/png':
                if (!function_exists('imagecreatefrompng')) return false;
                $src = @imagecreatefrompng($source);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                break;
            case 'image/gif':
                if (!function_exists('imagecreatefromgif')) return false;
                $src = @imagecreatefromgif($source);
                break;
            default:
                return false;
        }
        if (!$src) {
            imagedestroy($dst);
            return false;
        }
        if (!function_exists('imagecopyresampled') || !function_exists('imagejpeg')) return false;
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        $ok = imagejpeg($dst, $dest, $dims['quality']);
        imagedestroy($dst);
        imagedestroy($src);
        return $ok;
    }

    private function createVideoThumbnail(string $source, string $dest, string $ffmpegPath): bool {
        $dims = \App\Core\Config::get('thumbnail', ['max_width' => 320, 'max_height' => 240]);
        $ffmpegPath = trim($ffmpegPath);
        if ($ffmpegPath === '' || !is_file($ffmpegPath) || !preg_match('/^ffmpeg(?:\.exe)?$/i', basename($ffmpegPath))) {
            return false;
        }
        $scale = $dims['max_width'] . ':-1';
        $cmd = escapeshellcmd($ffmpegPath) . " -y -ss 00:00:01 -i " . escapeshellarg($source) . " -frames:v 1 -vf scale=" . $scale . " " . escapeshellarg($dest);
        $result = @shell_exec($cmd);
        return file_exists($dest);
    }
 
    public static function formatSize($bytes, $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
