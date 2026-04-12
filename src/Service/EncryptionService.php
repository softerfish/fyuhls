<?php
namespace App\Service;

use Exception;

class EncryptionService
{
    private static string $key = '';

    /**
     * Set the AES-256 encryption key.
     * This is automatically loaded from the secure, off-grid config file by the framework.
     */
    public static function setKey(string $key): void
    {
        // Decode the base64 string back into its raw 32 bytes (256 bits) of entropy
        $decoded = base64_decode($key);
        if ($decoded !== false && strlen($decoded) === 32) {
            self::$key = $decoded;
        } else {
            // Fallback for legacy 32-char hex keys from older versions
            self::$key = $key;
        }
    }

    /**
     * Determine if encryption has been configured and is ready to use.
     */
    public static function isReady(): bool
    {
        // Must be exactly 32 raw bytes for AES-256
        return !empty(self::$key) && strlen(self::$key) === 32;
    }

    /**
     * Apply AES-256-CBC encryption with a fresh random IV for every value.
     *
     * @throws Exception
     */
    public static function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || !self::isReady()) {
            return $value; // Return nulls or bypass if key isn't loaded yet
        }

        // Check if value is already encrypted (starts with magic prefix)
        if (str_starts_with($value, 'ENC:')) {
            return $value;
        }

        // --- FUTUREPROOF GUARD: Hash-Awareness ---
        // If the value looks like a Bcrypt ($2y$, $2a$) or Argon2 ($argon2) hash, 
        // we explicitly refuse to encrypt it.
        if (str_starts_with($value, '$2y$') || str_starts_with($value, '$2a$') || str_starts_with($value, '$argon2')) {
            return $value;
        }

        $iv = random_bytes(16);

        $ciphertext = openssl_encrypt($value, 'aes-256-cbc', self::$key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            error_log("EncryptionService: Failed to encrypt string.");
            throw new Exception("Encryption failed.");
        }

        // Format: ENC:base64(iv . ciphertext)
        return 'ENC:' . base64_encode($iv . $ciphertext);
    }

    /**
     * Decrypt an AES-256-CBC string back to plaintext.
     */
    public static function decrypt(?string $value): ?string
    {
        // Ignore nulls, blanks, or unencrypted legacy data
        if ($value === null || $value === '' || !self::isReady() || strpos($value, 'ENC:') !== 0) {
            return $value;
        }

        // Extract the payload
        $payload = base64_decode(substr($value, 4));
        if ($payload === false || strlen($payload) < 16) {
            return $value; // Corrupted payload, return as is safely
        }

        // Split the IV and Ciphertext
        $iv = substr($payload, 0, 16);
        $ciphertext = substr($payload, 16);

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', self::$key, OPENSSL_RAW_DATA, $iv);

        // If decryption fails (e.g., config key changed), return the failed string 
        // silently rather than fatal crashing the entire script. It will look like gibberish on the frontend.
        if ($plaintext === false) {
            return $value; 
        }

        return $plaintext;
    }

    /**
     * Generate a brand new, cryptographically secure 256-bit key for new installations.
     * 
     * @throws Exception
     */
    public static function generateKey(): string
    {
        // 32 raw bytes = 256 bits of true entropy, encoded to base64 for safe storage
        return base64_encode(random_bytes(32)); 
    }
}
