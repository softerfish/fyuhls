<?php

namespace App\Service;

use Exception;

class MailService
{
    private string $host;
    private int $port;
    private string $fromAddress;
    private string $secureMethod;
    private bool $requiresAuth;
    private string $username;
    private string $password;
    private string $template;

    public function __construct(
        string $host,
        int $port,
        string $fromAddress,
        string $secureMethod = 'none',
        bool $requiresAuth = false,
        string $username = '',
        string $password = '',
        string $template = ''
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->fromAddress = $fromAddress;
        $this->secureMethod = strtolower($secureMethod);
        $this->requiresAuth = $requiresAuth;
        $this->username = $username;
        $this->password = $password;
        $this->template = $template;
    }

    /**
     * Smart Send: Automatically decides to send now or queue based on priority
     * Priority 'high' = Send now (blocking)
     * Priority 'low'  = Save to DB for cron (non-blocking)
     */
    public static function sendSmart(string $to, string $subject, string $body, string $priority = 'low'): bool
    {
        if ($priority === 'low') {
            return MailQueueService::queue($to, $subject, $body, 'low');
        }

        // High priority = Send immediately
        try {
            $service = self::createFromSettings();
            return $service->send($to, $subject, $body);
        } catch (Exception $e) {
            // If instant fail, fallback to high-priority queue so we don't lose it
            return MailQueueService::queue($to, $subject, $body, 'high');
        }
    }

    /**
     * Send an email based on a database template
     */
    public static function sendTemplate(string $to, string $templateKey, array $placeholders, string $priority = 'low'): bool
    {
        self::ensureDefaultTemplates();
        $db = \App\Core\Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT subject, body FROM email_templates WHERE template_key = ? LIMIT 1");
        $stmt->execute([$templateKey]);
        $tpl = $stmt->fetch();

        if (!$tpl) {
            return false;
        }

        // 1. Prepare global placeholders
        $globalPlaceholders = [
            '{site_name}'     => \App\Model\Setting::get('app.name', 'fyuhls'),
            '{site_url}'      => \App\Service\SeoService::trustedBaseUrl(),
            '{support_email}' => \App\Model\Setting::get('email_from_address', 'support@localhost'),
            '{email}'         => $to,
            '{current_year}'  => date('Y')
        ];

        // 2. Merge with provided placeholders (provided ones take precedence)
        $allPlaceholders = array_merge($globalPlaceholders, $placeholders);

        // 3. Perform replacement
        $subject = strtr($tpl['subject'], $allPlaceholders);
        $body = strtr($tpl['body'], $allPlaceholders);

        return self::sendSmart($to, $subject, $body, $priority);
    }

    public static function ensureDefaultTemplates(): void
    {
        $db = \App\Core\Database::getInstance()->getConnection();
        foreach (self::defaultTemplates() as $template) {
            $stmt = $db->prepare("
                INSERT INTO email_templates (template_key, subject, body, description)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    subject = subject,
                    body = body,
                    description = COALESCE(description, VALUES(description))
            ");
            $stmt->execute([
                $template['template_key'],
                $template['subject'],
                $template['body'],
                $template['description'],
            ]);
        }
    }

    public static function defaultTemplates(): array
    {
        return [
            [
                'template_key' => 'confirm_email',
                'subject' => 'Confirm your email address for {site_name}',
                'body' => "Hi {username},\n\nWelcome to {site_name}. Please confirm your email address by opening the link below:\n\n{confirm_link}\n\nIf you did not create this account, you can safely ignore this email.\n\nRegards,\n{site_name}\n{site_url}",
                'description' => 'Sent after registration when email verification is required.',
            ],
            [
                'template_key' => 'welcome_email',
                'subject' => 'Welcome to {site_name}',
                'body' => "Hi {username},\n\nYour account is ready and you can now start using {site_name}.\n\nYou can log in here:\n{site_url}/login\n\nThanks for joining us.\n\nRegards,\n{site_name}",
                'description' => 'Sent after successful registration or email verification.',
            ],
            [
                'template_key' => 'forgot_password',
                'subject' => 'Reset your {site_name} password',
                'body' => "Hi {username},\n\nWe received a request to reset your password.\n\nUse the link below to choose a new password:\n\n{reset_link}\n\nIf you did not request this, you can ignore this email.\n\nRegards,\n{site_name}",
                'description' => 'Sent when a user requests a password reset.',
            ],
            [
                'template_key' => 'admin_notification',
                'subject' => '[{site_name}] {event_type}',
                'body' => "Hello,\n\nA new event requires your attention on {site_name}.\n\nEvent: {event_type}\n\nDetails:\n{details}\n\nAdmin area:\n{site_url}/admin\n",
                'description' => 'Sent to the admin notification address for operational alerts.',
            ],
            [
                'template_key' => 'abuse_report_confirmation',
                'subject' => 'We received your abuse report on {site_name}',
                'body' => "Hi {username},\n\nThanks for submitting an abuse report for:\n{file_name}\n\nOur team will review it as soon as possible.\n\nRegards,\n{site_name}",
                'description' => 'Sent to a logged-in user after they submit an abuse report.',
            ],
            [
                'template_key' => 'contact_form_responder',
                'subject' => 'We received your message: {subject}',
                'body' => "Hi {username},\n\nThanks for contacting {site_name}. We received your message about:\n{subject}\n\nOur team will get back to you as soon as possible.\n\nRegards,\n{site_name}\n{support_email}",
                'description' => 'Sent after a public contact form submission.',
            ],
            [
                'template_key' => 'dmca_form_responder',
                'subject' => 'We received your DMCA notice',
                'body' => "Hi {username},\n\nWe received your DMCA notice on {site_name}.\n\nOur team will review the submission and follow up if additional information is needed.\n\nRegards,\n{site_name}\n{support_email}",
                'description' => 'Sent after a public DMCA form submission.',
            ],
            [
                'template_key' => 'account_downgrade',
                'subject' => 'Your account has been moved to the Free tier',
                'body' => "Hi {username},\n\nYour premium subscription has expired. Your account has been automatically moved to our Free tier.\n\nYour files are still safe, but your account may now be subject to free-tier limits.\n\nYou can upgrade again at any time from your dashboard.\n\nRegards,\n{site_name}",
                'description' => 'Sent when a premium subscription expires.',
            ],
            [
                'template_key' => 'package_changed',
                'subject' => 'Your account package has been updated',
                'body' => "Hi {username},\n\nYour account package on {site_name} has been updated.\n\nPrevious package: {old_package}\nNew package: {new_package}\n\nIf you were not expecting this change, please contact support.\n\nRegards,\n{site_name}\n{support_email}",
                'description' => 'Sent when an admin manually changes a user package.',
            ],
            [
                'template_key' => 'premium_expiry_reminder_7d',
                'subject' => 'Your premium plan expires in 7 days',
                'body' => "Hi {username},\n\nThis is a reminder that your premium plan on {site_name} will expire on {expiry_date}.\n\nIf you want to keep your premium features active, please renew before that date.\n\nRegards,\n{site_name}",
                'description' => 'Sent 7 days before premium expiry.',
            ],
            [
                'template_key' => 'premium_expiry_reminder_1d',
                'subject' => 'Your premium plan expires tomorrow',
                'body' => "Hi {username},\n\nYour premium plan on {site_name} expires on {expiry_date}.\n\nRenew now if you want to avoid interruption to your premium features.\n\nRegards,\n{site_name}",
                'description' => 'Sent 1 day before premium expiry.',
            ],
            [
                'template_key' => 'storage_limit_warning',
                'subject' => 'Storage warning: you are using {usage_percent}% of your quota',
                'body' => "Hi {username},\n\nYou are currently using {usage_percent}% of your available storage on {site_name}.\n\nWarning threshold: {threshold}%\nTotal package storage: {max_storage}\n\nPlease clean up unused files or upgrade your package if you need more room.\n\nRegards,\n{site_name}",
                'description' => 'Sent when a user reaches their storage warning threshold.',
            ],
            [
                'template_key' => 'withdrawal_request_submitted',
                'subject' => 'We received your withdrawal request',
                'body' => "Hi {username},\n\nWe received your withdrawal request on {site_name}.\n\nAmount: {amount}\nMethod: {method}\n\nYour request is now pending review. We will update you again once it is processed.\n\nRegards,\n{site_name}",
                'description' => 'Sent when a user submits a withdrawal request.',
            ],
            [
                'template_key' => 'withdrawal_status_approved',
                'subject' => 'Your withdrawal request has been approved',
                'body' => "Hi {username},\n\nYour withdrawal request has been approved.\n\nAmount: {amount}\nMethod: {method}\n\nAdmin note:\n{admin_note}\n\nRegards,\n{site_name}",
                'description' => 'Sent when an admin approves a withdrawal request.',
            ],
            [
                'template_key' => 'withdrawal_status_paid',
                'subject' => 'Your withdrawal has been marked as paid',
                'body' => "Hi {username},\n\nYour withdrawal request has been marked as paid.\n\nAmount: {amount}\nMethod: {method}\n\nAdmin note:\n{admin_note}\n\nRegards,\n{site_name}",
                'description' => 'Sent when an admin marks a withdrawal as paid.',
            ],
            [
                'template_key' => 'withdrawal_status_rejected',
                'subject' => 'Your withdrawal request was rejected',
                'body' => "Hi {username},\n\nYour withdrawal request was rejected.\n\nAmount: {amount}\nMethod: {method}\n\nAdmin note:\n{admin_note}\n\nPlease review the note above and update your payout details if needed.\n\nRegards,\n{site_name}",
                'description' => 'Sent when an admin rejects a withdrawal request.',
            ],
            [
                'template_key' => 'two_factor_enabled',
                'subject' => 'Two-factor authentication is now enabled',
                'body' => "Hi {username},\n\nTwo-factor authentication has been enabled on your {site_name} account.\n\nIf this was you, no action is needed.\nIf you did not enable 2FA, contact support immediately.\n\nRegards,\n{site_name}\n{support_email}",
                'description' => 'Sent after a user successfully enables 2FA.',
            ],
            [
                'template_key' => 'two_factor_disabled',
                'subject' => 'Two-factor authentication has been disabled',
                'body' => "Hi {username},\n\nTwo-factor authentication has been disabled on your {site_name} account.\n\nIf this was expected, no action is needed.\nIf you did not request this change, secure your account immediately and contact support.\n\nRegards,\n{site_name}\n{support_email}",
                'description' => 'Sent after 2FA is disabled for a user account.',
            ],
            [
                'template_key' => 'payment_completed',
                'subject' => 'Your payment was completed successfully',
                'body' => "Hi {username},\n\nWe received your payment successfully.\n\nPackage: {package_name}\nAmount: {amount}\nGateway: {gateway}\n\nYour account will be updated according to your purchase.\n\nRegards,\n{site_name}",
                'description' => 'Sent when a package payment is successfully completed.',
            ],
            [
                'template_key' => 'payment_failed',
                'subject' => 'Your payment could not be completed',
                'body' => "Hi {username},\n\nWe were unable to complete your payment.\n\nPackage: {package_name}\nAmount: {amount}\nGateway: {gateway}\n\nPlease try again or use a different payment method.\n\nRegards,\n{site_name}",
                'description' => 'Sent when a package payment fails.',
            ],
            [
                'template_key' => 'payment_pending',
                'subject' => 'Your payment is pending review',
                'body' => "Hi {username},\n\nWe received your payment attempt and it is currently pending review.\n\nPackage: {package_name}\nAmount: {amount}\nGateway: {gateway}\n\nWe will send another email as soon as the payment is confirmed or declined.\n\nRegards,\n{site_name}",
                'description' => 'Sent when a package payment is pending review or asynchronous confirmation.',
            ],
            [
                'template_key' => 'payment_on_hold',
                'subject' => 'Your payment is currently on hold',
                'body' => "Hi {username},\n\nYour recent payment has been placed on hold.\n\nPackage: {package_name}\nAmount: {amount}\nGateway: {gateway}\n\nWe will update you again when the payment is released or declined.\n\nRegards,\n{site_name}",
                'description' => 'Sent when a package payment is put on hold.',
            ],
            [
                'template_key' => 'payment_denied',
                'subject' => 'Your payment was denied',
                'body' => "Hi {username},\n\nYour payment could not be approved.\n\nPackage: {package_name}\nAmount: {amount}\nGateway: {gateway}\n\nPlease try again or use a different payment method.\n\nRegards,\n{site_name}",
                'description' => 'Sent when a package payment is denied.',
            ],
            [
                'template_key' => 'payment_refunded',
                'subject' => 'Your payment has been refunded',
                'body' => "Hi {username},\n\nA refund has been recorded for your payment.\n\nPackage: {package_name}\nAmount: {amount}\nGateway: {gateway}\n\nIf you have questions about this refund, please contact support.\n\nRegards,\n{site_name}",
                'description' => 'Sent when a package payment is refunded.',
            ],
            [
                'template_key' => 'new_device_login',
                'subject' => 'New device sign-in detected on your account',
                'body' => "Hi {username},\n\nWe detected a sign-in to your {site_name} account from a new device or browser.\n\nIP: {login_ip}\nTime: {login_time}\n\nIf this was you, no action is needed.\nIf this was not you, change your password immediately and review your security settings.\n\nRegards,\n{site_name}\n{support_email}",
                'description' => 'Sent when a user signs in from a browser or device token that has not been seen before.',
            ],
        ];
    }


    /**
     * Factory to create instance from DB settings
     * 
     * @throws Exception
     */
    public static function createFromSettings(): self
    {
        $host = trim(\App\Model\Setting::get('email_smtp_host', ''));
        $port = (int)\App\Model\Setting::get('email_smtp_port', '25');
        $from = \App\Model\Setting::get('email_from_address', 'noreply@localhost');
        $secure = \App\Model\Setting::get('email_secure_method', 'none');
        $auth = \App\Model\Setting::get('email_smtp_requires_auth', '0') === '1';
        $user = \App\Model\Setting::get('email_smtp_auth_username', '');
        $pass = \App\Model\Setting::getEncrypted('email_smtp_auth_password', '');
        $temp = \App\Model\Setting::get('email_template', '');

        if (!$host) throw new Exception("SMTP Host not configured.");

        return new self($host, $port, $from, $secure, $auth, $user, $pass, $temp);
    }

    /**
     * Test the SMTP connection only (does not send an email)
     * 
     * @throws Exception
     */
    public function testConnection(): bool
    {
        $socket = $this->connect();
        if ($socket) {
            $this->disconnect($socket);
            return true;
        }
        return false;
    }

    /**
     * Send an email via SMTP
     * 
     * @throws Exception
     */
    public function send(string $to, string $subject, string $body, array $attachments = []): bool
    {
        if (!empty($this->template)) {
            $body .= "\r\n\r\n-- \r\n" . $this->template;
        }

        $socket = $this->connect();
        if (!$socket) {
            throw new Exception("Unable to connect to SMTP server");
        }
        
        try {
            // mail from
            $this->sendCommand($socket, "MAIL FROM:<{$this->fromAddress}>", 250);
            
            // rcpt to
            $this->sendCommand($socket, "RCPT TO:<{$to}>", 250);
            
            // data
            $this->sendCommand($socket, "DATA", 354);
            
            // Headers & Body
            $headers = "From: {$this->fromAddress}\r\n";
            $headers .= "To: {$to}\r\n";
            $headers .= "Subject: {$subject}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";

            if (!empty($attachments)) {
                $boundary = 'fyuhls_' . bin2hex(random_bytes(12));
                $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

                $messageBody = "--{$boundary}\r\n";
                $messageBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $messageBody .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
                $messageBody .= $body . "\r\n";

                foreach ($attachments as $attachment) {
                    $filename = $attachment['filename'] ?? 'attachment.txt';
                    $content = base64_encode((string)($attachment['content'] ?? ''));
                    $contentType = $attachment['content_type'] ?? 'application/octet-stream';

                    $messageBody .= "--{$boundary}\r\n";
                    $messageBody .= "Content-Type: {$contentType}; name=\"{$filename}\"\r\n";
                    $messageBody .= "Content-Transfer-Encoding: base64\r\n";
                    $messageBody .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
                    $messageBody .= chunk_split($content);
                }

                $messageBody .= "--{$boundary}--";
            } else {
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $messageBody = $body;
            }
            
            $message = $headers . "\r\n" . $this->dotStuff($messageBody) . "\r\n.";
            $this->sendCommand($socket, $message, 250);
            
            // quit
            $this->disconnect($socket);
            return true;
        } catch (Exception $e) {
            $this->disconnect($socket);
            throw $e;
        }
    }

    /**
     * @throws Exception
     */
    private function connect()
    {
        $context = stream_context_create();
        $hostname = trim($this->host);

        if (empty($hostname)) {
            throw new Exception("SMTP connection failed: Hostname is empty.");
        }
        
        if ($this->secureMethod === 'ssl') {
            $hostname = "ssl://" . $this->host;
        } elseif ($this->secureMethod === 'tls' && $this->port == 465) {
            // Edge case where TLS is specified but port is 465
            $hostname = "ssl://" . $this->host;
        }
        
        $socket = stream_socket_client(
            $hostname . ':' . $this->port,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            throw new Exception("Connection failed: $errno $errstr");
        }

        // Set timeout
        stream_set_timeout($socket, 10);

        // Read greeting
        $this->readResponse($socket);

        // Send EHLO/HELO
        try {
            $this->sendCommand($socket, "EHLO localhost", 250);
        } catch (Exception $e) {
            $this->sendCommand($socket, "HELO localhost", 250);
        }

        // STARTTLS
        if ($this->secureMethod === 'tls' && strpos($hostname, 'ssl://') === false) {
            $this->sendCommand($socket, "STARTTLS", 220);
            
            // Enable crypto
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception("Failed to enable TLS encryption");
            }
            
            // Send EHLO again after STARTTLS
            $this->sendCommand($socket, "EHLO localhost", 250);
        }

        // auth
        if ($this->requiresAuth) {
            $this->sendCommand($socket, "AUTH LOGIN", 334);
            $this->sendCommand($socket, base64_encode($this->username), 334);
            $this->sendCommand($socket, base64_encode($this->password), 235);
        }

        return $socket;
    }

    private function disconnect($socket)
    {
        if ($socket) {
            // Suppress error on QUIT as server might have already closed
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }
    }

    /**
     * @throws Exception
     */
    private function sendCommand($socket, string $command, int $expectedCode): string
    {
        fwrite($socket, $command . "\r\n");
        $response = $this->readResponse($socket);
        
        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode && !($expectedCode === 250 && ($code === 250 || $code === 220))) {
            throw new Exception("SMTP Error. Expected $expectedCode, got: $response");
        }
        
        return $response;
    }

    private function readResponse($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            // Check if this is the last line of the response
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }

    private function dotStuff(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);
        foreach ($lines as &$line) {
            if (str_starts_with($line, '.')) {
                $line = '.' . $line;
            }
        }

        return implode("\r\n", $lines);
    }
}
