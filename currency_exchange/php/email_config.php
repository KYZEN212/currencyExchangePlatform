<?php
// Email configuration for password resets
// Set your Gmail App Password here or via environment
const GMAIL_USER = 'naing270104@gmail.com';
const GMAIL_APP_PASSWORD = 'pwuzpusgmtkvpbfd';
const EMAIL_FROM = 'naing270104@gmail.com';
const APP_BASE_URL = 'http://localhost/project/backup_new'; // Adjust if deployed differently

function send_reset_email(string $toEmail, string $toName, string $resetUrl): bool {
    $phpmailerBase = __DIR__ . '/../phpmailer/src/';
    $paths = [
        $phpmailerBase . 'PHPMailer.php',
        $phpmailerBase . 'SMTP.php',
        $phpmailerBase . 'Exception.php',
    ];
    foreach ($paths as $p) { if (file_exists($p)) { require_once $p; } }
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) { return false; }
    if (GMAIL_USER === '' || GMAIL_APP_PASSWORD === '') { return false; }

    $from = EMAIL_FROM !== '' ? EMAIL_FROM : GMAIL_USER;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = GMAIL_USER;
        $mail->Password = GMAIL_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom($from, 'Support');
        $mail->addAddress($toEmail, $toName ?: $toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Reset your password';
        $mail->Body = '<p>Use this link to reset your password:</p><p><a href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetUrl) . '</a></p>';
        $mail->AltBody = "Use this link to reset your password: $resetUrl";

        return $mail->send();
    } catch (Throwable $e) {
        return false;
    }
}

// Send account lock/ban notification after too many failed login attempts
function send_ban_email(string $toEmail, string $toName, int $lockSeconds = 60): bool {
    $phpmailerBase = __DIR__ . '/../phpmailer/src/';
    $paths = [
        $phpmailerBase . 'PHPMailer.php',
        $phpmailerBase . 'SMTP.php',
        $phpmailerBase . 'Exception.php',
    ];
    foreach ($paths as $p) { if (file_exists($p)) { require_once $p; } }
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) { return false; }
    if (GMAIL_USER === '' || GMAIL_APP_PASSWORD === '') { return false; }

    $from = EMAIL_FROM !== '' ? EMAIL_FROM : GMAIL_USER;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = GMAIL_USER;
        $mail->Password = GMAIL_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom($from, 'Support');
        $mail->addAddress($toEmail, $toName ?: $toEmail);
        $mail->isHTML(true);
        $minutes = max(1, (int)ceil($lockSeconds / 60));
        $mail->Subject = 'Account temporarily locked';
        $resetUrl = htmlspecialchars(rtrim(APP_BASE_URL, '/') . '/php/forgot_password_request.php');
        $minutesTxt = htmlspecialchars((string)$minutes);
        $plural = $minutes > 1 ? 's' : '';
        $mail->Body = <<<HTML
<p>For your security, your account has been temporarily locked due to too many unsuccessful login attempts.</p>
<p>Please wait about <strong>{$minutesTxt} minute{$plural}</strong> before trying again.</p>
<p>If this wasn't you, we recommend resetting your password immediately:</p>
<p><a href="{$resetUrl}">Reset your password</a></p>
HTML;
        $mail->AltBody = 'Your account has been temporarily locked due to too many unsuccessful login attempts. Please wait about ' . $minutes . ' minute(s) and consider resetting your password: ' . rtrim(APP_BASE_URL, '/') . '/php/forgot_password_request.php';

        return $mail->send();
    } catch (Throwable $e) {
        return false;
    }
}

// Send PIN reset email with a distinct subject/body
function send_pin_reset_email(string $toEmail, string $toName, string $resetUrl): bool {
    $phpmailerBase = __DIR__ . '/../phpmailer/src/';
    $paths = [
        $phpmailerBase . 'PHPMailer.php',
        $phpmailerBase . 'SMTP.php',
        $phpmailerBase . 'Exception.php',
    ];
    foreach ($paths as $p) { if (file_exists($p)) { require_once $p; } }
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) { return false; }
    if (GMAIL_USER === '' || GMAIL_APP_PASSWORD === '') { return false; }

    $from = EMAIL_FROM !== '' ? EMAIL_FROM : GMAIL_USER;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = GMAIL_USER;
        $mail->Password = GMAIL_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom($from, 'Security');
        $mail->addAddress($toEmail, $toName ?: $toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Reset your 4-digit Security PIN';
        $safeUrl = htmlspecialchars($resetUrl);
        $mail->Body = '<p>We received a request to reset your 4-digit Security PIN.</p>'
            . '<p>If you requested this, click the secure link below within 15 minutes to set a new PIN:</p>'
            . '<p><a href="' . $safeUrl . '">' . $safeUrl . '</a></p>'
            . '<p>If you did not request this change, you can safely ignore this email.</p>';
        $mail->AltBody = "Use this link within 15 minutes to reset your 4-digit Security PIN: $resetUrl";

        return $mail->send();
    } catch (Throwable $e) {
        return false;
    }
}

