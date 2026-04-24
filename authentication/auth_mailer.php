<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

if (!function_exists('app_auth_smtp_attempts')) {
    function app_auth_smtp_attempts(): array
    {
        $host = function_exists('app_env_value') ? app_env_value('SMTP_HOST', 'premium245.web-hosting.com') : 'premium245.web-hosting.com';
        $username = function_exists('app_env_value') ? app_env_value('SMTP_USERNAME', 'admin@festival-web.com') : 'admin@festival-web.com';
        $password = function_exists('app_env_value') ? app_env_value('SMTP_PASSWORD', '!g3$~8tYju*D') : '!g3$~8tYju*D';
        $fromEmail = function_exists('app_env_value') ? app_env_value('SMTP_FROM_EMAIL', $username) : $username;
        $fromName = function_exists('app_env_value') ? app_env_value('SMTP_FROM_NAME', 'Athina E-Shop') : 'Athina E-Shop';

        return [
            [
                'host' => $host,
                'username' => $username,
                'password' => $password,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'secure' => PHPMailer::ENCRYPTION_STARTTLS,
                'port' => 587,
            ],
            [
                'host' => $host,
                'username' => $username,
                'password' => $password,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'secure' => PHPMailer::ENCRYPTION_SMTPS,
                'port' => 465,
            ],
        ];
    }
}

if (!function_exists('app_auth_send_plaintext_email')) {
    function app_auth_send_plaintext_email(string $toEmail, string $toName, string $subject, string $body): array
    {
        $toEmail = trim($toEmail);
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'The destination email address is invalid.',
            ];
        }

        foreach (app_auth_smtp_attempts() as $smtpConfig) {
            try {
            $mail = new PHPMailer(true);
            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host = (string)$smtpConfig['host'];
            $mail->SMTPAuth = true;
            $mail->Username = (string)$smtpConfig['username'];
            $mail->Password = (string)$smtpConfig['password'];
            $mail->SMTPSecure = (string)$smtpConfig['secure'];
            $mail->Port = (int)$smtpConfig['port'];
            $mail->Timeout = 20;
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
            $mail->setFrom((string)$smtpConfig['from_email'], (string)$smtpConfig['from_name']);
            $mail->addAddress($toEmail, trim($toName) !== '' ? $toName : 'Customer');
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();

            return [
                'success' => true,
                'message' => '',
            ];
            } catch (Throwable $e) {
                continue;
            }
        }

        return [
            'success' => false,
            'message' => "We couldn't send the email right now. Please try again later.",
        ];
    }
}

if (!function_exists('app_auth_send_two_factor_code_email')) {
    function app_auth_send_two_factor_code_email(string $toEmail, string $toName, string $code): array
    {
        $validMinutes = function_exists('app_auth_two_factor_code_ttl_seconds')
            ? max(1, (int)floor(app_auth_two_factor_code_ttl_seconds() / 60))
            : 20;
        $subject = 'Athina E-Shop Login Verification Code';
        $body =
            "Hello,\n\n" .
            "Use the following verification code to complete your login:\n\n" .
            $code . "\n\n" .
            "This code is valid for {$validMinutes} minutes.\n\n" .
            "If you did not try to sign in, you can safely ignore this email.\n\n" .
            "Athina E-Shop";

        return app_auth_send_plaintext_email($toEmail, $toName, $subject, $body);
    }
}

if (!function_exists('app_auth_send_email_verification_code')) {
    function app_auth_send_email_verification_code(mysqli $conn, int $userId, string $toEmail, string $toName = ''): array
    {
        $newCode = (string)random_int(100000, 999999);
        $expiresAt = date('Y-m-d H:i:s', time() + 20 * 60);

        $stmt = $conn->prepare("
            UPDATE users
            SET verification_code = ?,
                verification_expires_at = ?
            WHERE userID = ?
        ");
        if (!$stmt) {
            return [
                'success' => false,
                'message' => "We couldn't create a new verification code right now. Please try again later.",
            ];
        }
        $stmt->bind_param('ssi', $newCode, $expiresAt, $userId);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            return [
                'success' => false,
                'message' => "We couldn't create a new verification code right now. Please try again later.",
            ];
        }

        $subject = 'Athina E-Shop Email Verification Code';
        $body =
            "Hello,\n\n" .
            "Your verification code is:\n\n" .
            $newCode . "\n\n" .
            "This code is valid for 20 minutes.\n\n" .
            "If you did not request this, please ignore this email.\n\n" .
            "Athina E-Shop";

        $sendResult = app_auth_send_plaintext_email($toEmail, $toName, $subject, $body);
        if (empty($sendResult['success'])) {
            $clearStmt = $conn->prepare("
                UPDATE users
                SET verification_code = NULL,
                    verification_expires_at = NULL
                WHERE userID = ?
            ");
            if ($clearStmt) {
                $clearStmt->bind_param('i', $userId);
                $clearStmt->execute();
                $clearStmt->close();
            }
        }

        return $sendResult;
    }
}

