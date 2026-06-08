<?php

namespace App\Service;

use App\Model\Setting;

class MonetizationModelService
{
    public static function globallyEnabledModels(): array
    {
        if (!FeatureService::rewardsEnabled()) {
            return [];
        }

        return array_values(array_intersect(
            ['ppd', 'pps', 'mixed'],
            array_filter(array_map('trim', explode(',', Setting::get('enabled_models', 'ppd,pps,mixed', 'rewards'))))
        ));
    }

    public static function allowedModelsForPackage(?array $package): array
    {
        $enabledModels = self::globallyEnabledModels();
        if ($package === null) {
            return $enabledModels;
        }

        $allowed = [];
        $ppdAllowed = (int)($package['ppd_enabled'] ?? 0) === 1;
        $ppsAllowed = (int)($package['pps_enabled'] ?? 0) === 1;

        if ($ppdAllowed && in_array('ppd', $enabledModels, true)) {
            $allowed[] = 'ppd';
        }
        if ($ppsAllowed && in_array('pps', $enabledModels, true)) {
            $allowed[] = 'pps';
        }
        if ($ppdAllowed && $ppsAllowed && in_array('mixed', $enabledModels, true)) {
            $allowed[] = 'mixed';
        }

        return $allowed;
    }

    public static function normalizeRequestedModel(?string $model, ?array $package, ?string $currentModel = null): string
    {
        $allowedModels = self::allowedModelsForPackage($package);
        $model = trim((string)$model);
        if (in_array($model, $allowedModels, true)) {
            return $model;
        }

        $currentModel = trim((string)$currentModel);
        if (in_array($currentModel, $allowedModels, true)) {
            return $currentModel;
        }

        return $allowedModels[0] ?? 'ppd';
    }

    public static function ppdEligible(?string $model, ?array $package): bool
    {
        return in_array(trim((string)$model), ['ppd', 'mixed'], true)
            && in_array(trim((string)$model), self::allowedModelsForPackage($package), true);
    }

    public static function ppsEligible(?string $model, ?array $package): bool
    {
        return in_array(trim((string)$model), ['pps', 'mixed'], true)
            && in_array(trim((string)$model), self::allowedModelsForPackage($package), true);
    }
}
