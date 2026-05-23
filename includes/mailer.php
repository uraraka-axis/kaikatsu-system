<?php declare(strict_types=1);

/**
 * 快活システム - メール送信ヘルパ (PHPMailer ラッパー)
 *
 * 使い方:
 *   require_once __DIR__ . '/mailer.php';
 *   sendMail(['foo@example.com'], '件名', '本文', ['html' => false]);
 *
 * 環境切替:
 *   ローカル: includes/config.php で SMTP_HOST=localhost, SMTP_PORT=1025 (Mailpit)
 *   本番:     同設定を SMTP プロバイダ (Gmail/SendGrid/SES 等) に差し替えるだけ
 *
 * MAIL_LOG_ONLY=true のときは送信せず logs/mail.log に書き出す (ローカル切り分け用)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * メール送信。失敗時は false を返し error_log に記録する (例外は throw しない)。
 *
 * @param string[] $to      宛先メアド配列 (空配列ならスキップして true)
 * @param string   $subject 件名
 * @param string   $body    本文 (text/plain)
 * @param array    $options ['html' => bool, 'cc' => string[], 'bcc' => string[]]
 * @return bool 送信成功で true
 */
function sendMail(array $to, string $subject, string $body, array $options = []): bool
{
    // 宛先フィルタ (空文字や null を除外)
    $to = array_values(array_filter(array_map('trim', $to), fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)));
    if (empty($to)) {
        return true;   // 宛先無し = 何もしない (エラーではない)
    }

    // ログモード: 送信せずファイルに追記
    if (defined('MAIL_LOG_ONLY') && MAIL_LOG_ONLY === true) {
        return writeMailLog($to, $subject, $body, $options);
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = (int)SMTP_PORT;
        $mail->SMTPAuth   = (bool)SMTP_AUTH;
        if (SMTP_AUTH) {
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
        }
        if (SMTP_SECURE === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (SMTP_SECURE === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        foreach ($to as $addr) {
            $mail->addAddress($addr);
        }
        foreach ($options['cc'] ?? [] as $cc) {
            if (filter_var($cc, FILTER_VALIDATE_EMAIL)) $mail->addCC($cc);
        }
        foreach ($options['bcc'] ?? [] as $bcc) {
            if (filter_var($bcc, FILTER_VALIDATE_EMAIL)) $mail->addBCC($bcc);
        }

        $mail->Subject = $subject;
        if (!empty($options['html'])) {
            $mail->isHTML(true);
            $mail->Body    = $body;
            $mail->AltBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $body));
        } else {
            $mail->isHTML(false);
            $mail->Body = $body;
        }

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('sendMail failed: ' . $mail->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        error_log('sendMail exception: ' . $e->getMessage());
        return false;
    }
}

/**
 * MAIL_LOG_ONLY モード用: logs/mail.log にメール内容を追記する
 */
function writeMailLog(array $to, string $subject, string $body, array $options = []): bool
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/mail.log';
    $entry = "\n=== " . date('Y-m-d H:i:s') . " ===\n"
           . 'To: '      . implode(', ', $to) . "\n"
           . 'Subject: ' . $subject . "\n"
           . (!empty($options['cc'])  ? 'Cc: '  . implode(', ', $options['cc'])  . "\n" : '')
           . (!empty($options['bcc']) ? 'Bcc: ' . implode(', ', $options['bcc']) . "\n" : '')
           . "Body:\n" . $body . "\n";
    return file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX) !== false;
}
