<?php

namespace App\Service;

use App\Core\Auth;
use App\Model\ApiToken;
use App\Model\Setting;

class ApiAuthService
{
    public function resolveRequestContext(): array
    {
        $rawToken = $this->extractToken();
        if ($rawToken === null) {
            return [
                'mode' => 'session',
                'user_id' => Auth::check() ? (int)Auth::id() : null,
                'guest_session_id' => null,
                'api_token' => null,
                'csrf_required' => true,
            ];
        }

        $token = ApiToken::findActiveByRawToken($rawToken);
        if (!$token) {
            throw new \RuntimeException('Invalid API token.');
        }

        ApiToken::touchUsage((int)$token['id'], SecurityService::getClientIp());

        return [
            'mode' => 'token',
            'user_id' => (int)$token['user_id'],
            'guest_session_id' => null,
            'api_token' => $token,
            'csrf_required' => false,
        ];
    }

    public function requireScope(array $context, string $scope): void
    {
        if (($context['mode'] ?? 'session') !== 'token') {
            return;
        }

        if (!ApiToken::hasScope($context['api_token'], $scope)) {
            throw new \RuntimeException('API token is missing the required scope.');
        }
    }

    public function enforceRateLimit(array $context, string $action, int $defaultLimit, int $windowSeconds): void
    {
        $ip = SecurityService::getClientIp();
        $userId = $context['user_id'] ?? 0;
        $tokenId = $context['api_token']['id'] ?? null;

        $tokenLimit = (int)Setting::get('api_rate_limit_per_token', (string)$defaultLimit);
        $userLimit = (int)Setting::get('api_rate_limit_per_user', (string)max($defaultLimit * 3, $defaultLimit));
        $ipLimit = (int)Setting::get('api_rate_limit_per_ip', (string)max($defaultLimit * 2, $defaultLimit));

        if ($tokenId !== null && !RateLimiterService::check($action . '_token', (string)$tokenId, max(1, $tokenLimit), $windowSeconds)) {
            throw new \RuntimeException('API token rate limit exceeded.');
        }

        if ($userId && !RateLimiterService::check($action . '_user', (string)$userId, max(1, $userLimit), $windowSeconds)) {
            throw new \RuntimeException('User API rate limit exceeded.');
        }

        if (!RateLimiterService::check($action . '_ip', $ip, max(1, $ipLimit), $windowSeconds)) {
            throw new \RuntimeException('IP rate limit exceeded.');
        }
    }

    private function extractToken(): ?string
    {
        $bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $bearer, $matches)) {
            return trim($matches[1]);
        }

        $headerToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
        if ($headerToken !== '') {
            return trim($headerToken);
        }

        return null;
    }
}
