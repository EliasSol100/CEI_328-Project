<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

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

        try {
            $mail = new PHPMailer(true);
            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host = 'premium245.web-hosting.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'admin@festival-web.com';
            $mail->Password = '!g3$~8tYju*D';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->Timeout = 20;
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];

            $mail->setFrom('admin@festival-web.com', 'Athina E-Shop');
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
            return [
                'success' => false,
                'message' => "We couldn't send the email right now. Please try again later.",
            ];
        }
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

