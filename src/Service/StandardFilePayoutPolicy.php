<?php

namespace App\Service;

class StandardFilePayoutPolicy
{
    public function evaluate(array $input): array
    {
        $deliveryMode = strtolower(trim((string)($input['delivery_mode'] ?? 'php')));
        $fileSize = (int)($input['file_size'] ?? 0);
        $bytesRaw = $input['bytes_sent'] ?? null;
        $status = isset($input['status']) ? trim((string)$input['status']) : null;
        $streamMode = !empty($input['stream_mode']);
        $minPercent = $this->normalizePercent($input['min_percent'] ?? 0);

        $result = [
            'eligible' => false,
            'reason_code' => 'threshold_not_met',
            'required_bytes' => 0,
            'observed_bytes' => 0,
            'delivery_mode' => $deliveryMode,
            'min_percent' => $minPercent,
        ];

        if ($streamMode) {
            $result['reason_code'] = 'stream_mode_not_supported';
            return $result;
        }

        if ($fileSize <= 0) {
            $result['reason_code'] = 'unknown_file_size';
            return $result;
        }

        if ($bytesRaw === null || $bytesRaw === '' || !is_numeric($bytesRaw)) {
            $result['reason_code'] = 'invalid_bytes_sent';
            return $result;
        }

        $bytesSent = max(0, (int)$bytesRaw);
        $requiredBytes = $this->calculateRequiredBytes($fileSize, $minPercent);

        $result['required_bytes'] = $requiredBytes;
        $result['observed_bytes'] = $bytesSent;

        if ($deliveryMode === 'nginx' && !in_array($status, ['200', '206'], true)) {
            $result['reason_code'] = 'invalid_status';
            return $result;
        }

        if ($bytesSent < $requiredBytes) {
            $result['reason_code'] = 'threshold_not_met';
            return $result;
        }

        $result['eligible'] = true;
        $result['reason_code'] = 'eligible';

        return $result;
    }

    public function normalizePercent(mixed $percent): int
    {
        $value = (int)$percent;
        if ($value < 0) {
            return 0;
        }
        if ($value > 100) {
            return 100;
        }

        return $value;
    }

    public function calculateRequiredBytes(int $fileSize, int $minPercent): int
    {
        if ($fileSize <= 0) {
            return 0;
        }

        $normalized = $this->normalizePercent($minPercent);
        if ($normalized <= 0) {
            return 0;
        }

        return (int)ceil($fileSize * ($normalized / 100));
    }
}
