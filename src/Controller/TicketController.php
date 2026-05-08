<?php

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Service\PackageAllowanceService;
use App\Service\RateLimiterService;
use App\Service\SecurityService;
use App\Service\TicketService;

class TicketController
{
    private function storageQuotaInfo(int $userId): array
    {
        $stmt = \App\Core\Database::getInstance()->getConnection()->prepare('SELECT storage_used FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $used = (int)($stmt->fetchColumn() ?: 0);

        $package = \App\Model\Package::getUserPackage($userId);
        $limit = (int)($package['max_storage_bytes'] ?? 0);

        return ['used' => $used, 'limit' => $limit];
    }

    private function requireUser(): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }
    }

    public function index(?string $publicId = null): void
    {
        $this->requireUser();

        $userId = (int)Auth::id();
        $tickets = TicketService::getUserTickets($userId);
        $selectedTicket = null;
        if ($publicId !== null && $publicId !== '') {
            $selectedTicket = TicketService::getUserTicketByPublicId($userId, $publicId);
            if (!$selectedTicket) {
                $_SESSION['error'] = 'Ticket not found.';
                header('Location: /tickets');
                exit;
            }
        } elseif (!empty($tickets)) {
            $selectedTicket = TicketService::getUserTicketByPublicId($userId, (string)$tickets[0]['public_id']);
        }

        View::render('home/tickets.php', [
            'tickets' => $tickets,
            'selectedTicket' => $selectedTicket,
            'statusLabels' => TicketService::userStatusOptions(),
            'storageQuota' => $this->storageQuotaInfo($userId),
            'dailyDownloadLimitSummary' => PackageAllowanceService::dailyDownloadLimitSummary($userId, \App\Model\Package::getUserPackage($userId) ?: []),
        ]);
    }

    public function create(): void
    {
        $this->requireUser();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die('CSRF mismatch');
        }

        try {
            $userId = (int)Auth::id();
            $ip = SecurityService::getClientIp();
            $userLimit = TicketService::getRateLimitConfig('support_create_user');
            $ipLimit = TicketService::getRateLimitConfig('support_create_ip');
            if (
                !RateLimiterService::check('support_ticket_create_user', (string)$userId, $userLimit['max'], $userLimit['window'])
                || !RateLimiterService::check('support_ticket_create_ip', $ip, $ipLimit['max'], $ipLimit['window'])
            ) {
                throw new \RuntimeException('Too many support tickets have been opened recently. Please wait a bit and try again.');
            }

            $publicId = TicketService::createSupportTicket(
                $userId,
                (string)($_POST['subject'] ?? ''),
                (string)($_POST['message'] ?? ''),
                $ip
            );
            $_SESSION['success'] = 'Ticket created successfully.';
            header('Location: /tickets/' . rawurlencode($publicId));
            exit;
        } catch (\Throwable $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: /tickets');
            exit;
        }
    }

    public function reply(string $publicId): void
    {
        $this->requireUser();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die('CSRF mismatch');
        }

        try {
            $userId = (int)Auth::id();
            $ip = SecurityService::getClientIp();
            $userLimit = TicketService::getRateLimitConfig('support_reply_user');
            $ipLimit = TicketService::getRateLimitConfig('support_reply_ip');
            if (
                !RateLimiterService::check('support_ticket_reply_user', (string)$userId, $userLimit['max'], $userLimit['window'])
                || !RateLimiterService::check('support_ticket_reply_ip', $ip, $ipLimit['max'], $ipLimit['window'])
            ) {
                throw new \RuntimeException('Too many replies have been sent recently. Please slow down and try again shortly.');
            }

            TicketService::addUserReply($publicId, $userId, (string)($_POST['message'] ?? ''));
            $_SESSION['success'] = 'Reply sent.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        header('Location: /tickets/' . rawurlencode($publicId));
        exit;
    }

    public function close(string $publicId): void
    {
        $this->requireUser();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die('CSRF mismatch');
        }

        try {
            TicketService::closeTicketByUser($publicId, (int)Auth::id());
            $_SESSION['success'] = 'Ticket closed.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        header('Location: /tickets/' . rawurlencode($publicId));
        exit;
    }
}
