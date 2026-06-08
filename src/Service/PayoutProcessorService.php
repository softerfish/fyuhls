<?php

namespace App\Service;

use App\Model\Setting;

class PayoutProcessorService
{
    private const SETTING_KEY = 'withdrawal_method_definitions';
    private const SETTING_GROUP = 'rewards';
    private const MAX_LABEL_LENGTH = 80;
    private const MAX_FIELD_LENGTH = 160;
    private const MAX_HELP_LENGTH = 260;

    public static function definitions(bool $enabledOnly = false): array
    {
        $raw = Setting::get(self::SETTING_KEY, '', self::SETTING_GROUP);
        $definitions = self::normalizeStoredDefinitions($raw);
        if ($definitions === []) {
            $definitions = self::legacyFallbackDefinitions();
        }

        if ($enabledOnly) {
            $definitions = array_values(array_filter($definitions, static fn(array $definition): bool => !empty($definition['enabled'])));
        }

        return $definitions;
    }

    public static function activeKeys(): array
    {
        return array_values(array_map(static fn(array $definition): string => (string)$definition['key'], self::definitions(true)));
    }

    public static function find(?string $key): ?array
    {
        $needle = trim((string)$key);
        if ($needle === '') {
            return null;
        }

        foreach (self::definitions(false) as $definition) {
            if ((string)$definition['key'] === $needle) {
                return $definition;
            }
        }

        return null;
    }

    public static function label(?string $key): string
    {
        $definition = self::find($key);
        if ($definition !== null) {
            return (string)$definition['label'];
        }

        $key = trim((string)$key);
        if ($key === '') {
            return 'Not set';
        }

        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    public static function destinationLabel(?string $key): string
    {
        $definition = self::find($key);
        if ($definition !== null && trim((string)$definition['destination_label']) !== '') {
            return (string)$definition['destination_label'];
        }

        return 'Payout destination';
    }

    public static function placeholder(?string $key): string
    {
        $definition = self::find($key);
        if ($definition !== null && trim((string)$definition['placeholder']) !== '') {
            return (string)$definition['placeholder'];
        }

        return 'Enter the exact payout destination staff should use';
    }

    public static function helpText(?string $key): string
    {
        $definition = self::find($key);
        if ($definition !== null && trim((string)$definition['help_text']) !== '') {
            return (string)$definition['help_text'];
        }

        return 'Enter the exact payout destination staff should use when sending your rewards payment.';
    }

    public static function parseSubmittedDefinitions(array $payload): array
    {
        $labels = $payload['withdrawal_processor_label'] ?? [];
        $destinationLabels = $payload['withdrawal_processor_destination_label'] ?? [];
        $placeholders = $payload['withdrawal_processor_placeholder'] ?? [];
        $helpTexts = $payload['withdrawal_processor_help_text'] ?? [];
        $existingKeys = $payload['withdrawal_processor_existing_key'] ?? [];
        $rowIds = $payload['withdrawal_processor_row_id'] ?? [];
        $enabledRows = $payload['withdrawal_processor_enabled'] ?? [];

        $rowCount = max(
            is_countable($labels) ? count($labels) : 0,
            is_countable($destinationLabels) ? count($destinationLabels) : 0,
            is_countable($placeholders) ? count($placeholders) : 0,
            is_countable($helpTexts) ? count($helpTexts) : 0,
            is_countable($existingKeys) ? count($existingKeys) : 0,
            is_countable($rowIds) ? count($rowIds) : 0
        );

        $definitions = [];
        $seenKeys = [];
        $enabledTokens = array_map('strval', is_array($enabledRows) ? $enabledRows : []);

        for ($i = 0; $i < $rowCount; $i++) {
            $label = self::trimField($labels[$i] ?? '', self::MAX_LABEL_LENGTH);
            $destinationLabel = self::trimField($destinationLabels[$i] ?? '', self::MAX_FIELD_LENGTH);
            $placeholder = self::trimField($placeholders[$i] ?? '', self::MAX_FIELD_LENGTH);
            $helpText = self::trimField($helpTexts[$i] ?? '', self::MAX_HELP_LENGTH);
            $existingKey = self::normalizeKey($existingKeys[$i] ?? '');
            $rowId = self::normalizeRowId($rowIds[$i] ?? '');
            $enabled = $rowId !== '' && in_array($rowId, $enabledTokens, true);

            if ($label === '' && $destinationLabel === '' && $placeholder === '' && $helpText === '') {
                continue;
            }

            if ($label === '') {
                throw new \RuntimeException('Each payout processor needs a display name.');
            }

            if ($destinationLabel === '') {
                throw new \RuntimeException('Each payout processor needs a destination field label so users know what to enter.');
            }

            $key = $existingKey !== '' ? $existingKey : self::slugifyKey($label);
            if ($key === '') {
                throw new \RuntimeException('Could not generate a stable payout processor key. Please use a clearer display name.');
            }

            if (isset($seenKeys[$key])) {
                throw new \RuntimeException('Payout processor names must produce unique keys. Please rename duplicate entries.');
            }

            $definitions[] = [
                'key' => $key,
                'label' => $label,
                'destination_label' => $destinationLabel,
                'placeholder' => $placeholder !== '' ? $placeholder : 'Enter the exact payout destination staff should use',
                'help_text' => $helpText !== '' ? $helpText : 'Enter the exact payout destination staff should use when sending your rewards payment.',
                'enabled' => $enabled ? 1 : 0,
            ];
            $seenKeys[$key] = true;
        }

        if ($definitions === []) {
            throw new \RuntimeException('Add at least one payout processor so users know where rewards payments can be sent.');
        }

        if (!array_filter($definitions, static fn(array $definition): bool => !empty($definition['enabled']))) {
            throw new \RuntimeException('Enable at least one payout processor so users can request payouts.');
        }

        return $definitions;
    }

    public static function encodeDefinitions(array $definitions): string
    {
        return json_encode(array_values($definitions), JSON_UNESCAPED_SLASHES);
    }

    private static function normalizeStoredDefinitions(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $definitions = [];
        $seenKeys = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = self::normalizeKey($row['key'] ?? '');
            $label = self::trimField($row['label'] ?? '', self::MAX_LABEL_LENGTH);
            if ($key === '' || $label === '' || isset($seenKeys[$key])) {
                continue;
            }

            $definitions[] = [
                'key' => $key,
                'label' => $label,
                'destination_label' => self::trimField($row['destination_label'] ?? '', self::MAX_FIELD_LENGTH) ?: 'Payout destination',
                'placeholder' => self::trimField($row['placeholder'] ?? '', self::MAX_FIELD_LENGTH) ?: 'Enter the exact payout destination staff should use',
                'help_text' => self::trimField($row['help_text'] ?? '', self::MAX_HELP_LENGTH) ?: 'Enter the exact payout destination staff should use when sending your rewards payment.',
                'enabled' => !empty($row['enabled']) ? 1 : 0,
            ];
            $seenKeys[$key] = true;
        }

        return $definitions;
    }

    private static function legacyFallbackDefinitions(): array
    {
        $allowedKeys = array_filter(array_map('trim', explode(',', Setting::get('supported_withdrawal_methods', 'paypal,bitcoin', self::SETTING_GROUP))));
        if ($allowedKeys === []) {
            $allowedKeys = ['paypal', 'bitcoin'];
        }

        $defaults = self::defaultDefinitions();
        $definitions = [];
        foreach ($defaults as $definition) {
            if (in_array((string)$definition['key'], $allowedKeys, true)) {
                $definition['enabled'] = 1;
                $definitions[] = $definition;
            }
        }

        return $definitions !== [] ? $definitions : [self::defaultDefinitions()[0]];
    }

    private static function defaultDefinitions(): array
    {
        return [
            [
                'key' => 'paypal',
                'label' => 'PayPal',
                'destination_label' => 'PayPal account email',
                'placeholder' => 'creator@example.com',
                'help_text' => 'Enter the PayPal email staff should use for this payout.',
                'enabled' => 1,
            ],
            [
                'key' => 'stripe',
                'label' => 'Stripe manual payout',
                'destination_label' => 'Connected Stripe account or payout reference',
                'placeholder' => 'acct_123456789 or the exact reference your staff uses',
                'help_text' => 'Use this only if your team manually pays creators through Stripe outside the buyer checkout flow.',
                'enabled' => 0,
            ],
            [
                'key' => 'bitcoin',
                'label' => 'Bitcoin',
                'destination_label' => 'Bitcoin wallet address',
                'placeholder' => 'bc1...',
                'help_text' => 'Enter the exact Bitcoin wallet address staff should pay.',
                'enabled' => 1,
            ],
            [
                'key' => 'wire',
                'label' => 'Bank wire',
                'destination_label' => 'Bank payout instructions',
                'placeholder' => 'Account holder, bank name, account number / IBAN, SWIFT / BIC',
                'help_text' => 'Enter the full bank payout instructions staff needs in order to send the wire correctly.',
                'enabled' => 0,
            ],
        ];
    }

    private static function normalizeKey($value): string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-z0-9_-]{1,100}$/', $value) === 1 ? $value : '';
    }

    private static function normalizeRowId($value): string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-z0-9_-]{1,120}$/', $value) === 1 ? $value : '';
    }

    private static function slugifyKey(string $label): string
    {
        $slug = strtolower(trim($label));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        $slug = preg_replace('/_+/', '_', $slug) ?? '';
        return self::normalizeKey($slug);
    }

    private static function trimField($value, int $maxLength): string
    {
        return mb_substr(trim((string)$value), 0, $maxLength);
    }
}
