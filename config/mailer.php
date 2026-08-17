<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/platform_settings.php';

/**
 * E-postayı kuyruğa ekler; gönderim cron/process-emails.php üzerinden yapılır.
 */
function queue_email(string $to, string $subject, string $bodyHtml, ?string $relatedType = null, ?int $relatedId = null, ?string $attachmentName = null, ?string $attachmentBase64 = null): void
{
    $name = $attachmentName !== null ? mb_substr(str_replace('"', '', trim($attachmentName)), 0, 190) : null;
    db()->prepare('INSERT INTO email_outbox(to_address,subject,body_html,related_type,related_id,attachment_name,attachment_base64) VALUES(?,?,?,?,?,?,?)')
        ->execute([trim($to), mb_substr(trim($subject), 0, 190), $bodyHtml, $relatedType, $relatedId, $name, $attachmentBase64]);
}

/**
 * Aktif bir e-posta şablonunu {degisken} değişkenleriyle doldurur.
 * Şablon yoksa veya pasifse null döner.
 *
 * @return array{subject: string, body_html: string}|null
 */
function render_email_template(string $code, array $vars = []): ?array
{
    $q = db()->prepare("SELECT subject,body_html FROM email_templates WHERE code=? AND is_active=true LIMIT 1");
    $q->execute([$code]);
    $t = $q->fetch();
    if (!$t) return null;
    $subject = (string) $t['subject'];
    $body = (string) $t['body_html'];
    foreach ($vars as $key => $value) {
        $subject = str_replace('{' . $key . '}', (string) $value, $subject);
        $body = str_replace('{' . $key . '}', htmlspecialchars((string) $value), $body);
    }
    return ['subject' => $subject, 'body_html' => $body];
}

/**
 * Şablon varsa şablonla, yoksa verilen varsayılan içerikle e-posta kuyruklar.
 * Şablon kullanıldıysa true döner.
 */
function queue_email_with_template(string $to, string $code, array $vars, string $fallbackSubject, string $fallbackBody, ?string $relatedType = null, ?int $relatedId = null): bool
{
    $rendered = render_email_template($code, $vars);
    if ($rendered !== null) {
        queue_email($to, $rendered['subject'], $rendered['body_html'], $relatedType, $relatedId);
        return true;
    }
    queue_email($to, $fallbackSubject, $fallbackBody, $relatedType, $relatedId);
    return false;
}

/**
 * Yönetim panelinde tanımlı admin_alert_email adresine uyarı e-postası kuyruklar.
 */
function queue_admin_alert_email(string $title, string $body): void
{
    $email = trim((string) platform_setting('admin_alert_email', ''));
    if ($email !== '') {
        queue_email($email, '[NEXUS Uyarı] ' . $title, nl2br(htmlspecialchars($body)), 'admin_alert');
    }
}

/**
 * Kuyruktaki e-postaları mail() ile gönderir (cron).
 */
function process_email_outbox(int $limit = 25): array
{
    $limit = max(1, min(100, $limit));
    $query = db()->prepare("SELECT * FROM email_outbox WHERE status='queued' ORDER BY id ASC LIMIT ?");
    $query->bindValue(1, $limit, PDO::PARAM_INT);
    $query->execute();
    $sent = 0;
    $failed = 0;
    $testDelivered = [];
    foreach ($query->fetchAll() as $email) {
        $attName = (string) ($email['attachment_name'] ?? '');
        $attData = (string) ($email['attachment_base64'] ?? '');
        $base = "From: NEXUS TravelTech <no-reply@nexustraveltech.com>\r\n" . "X-Mailer: NEXUS/1.0\r\n";
        if ($attName !== '' && $attData !== '') {
            // Ekli (multipart/mixed) e-posta.
            $boundary = 'nexus_' . bin2hex(random_bytes(8));
            $headers = "MIME-Version: 1.0\r\n" . "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n" . $base;
            $body = "--" . $boundary . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . (string) $email['body_html'] . "\r\n"
                . "--" . $boundary . "\r\n"
                . "Content-Type: application/pdf; name=\"" . $attName . "\"\r\n"
                . "Content-Disposition: attachment; filename=\"" . $attName . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split($attData, 76, "\r\n") . "\r\n"
                . "--" . $boundary . "--";
        } else {
            $headers = "MIME-Version: 1.0\r\n" . "Content-Type: text/html; charset=UTF-8\r\n" . $base;
            $body = (string) $email['body_html'];
        }
        $subject = '=?UTF-8?B?' . base64_encode((string) $email['subject']) . '?=';
        $ok = @mail((string) $email['to_address'], $subject, $body, $headers);
        if ($ok) {
            db()->prepare("UPDATE email_outbox SET status='sent',sent_at=now(),error_message=NULL WHERE id=?")->execute([$email['id']]);
            $sent++;
            if (($email['related_type'] ?? '') === 'admin_alerts_test') {
                // Test e-postası teslim edildi — rapora işaret + panel için kod kaydı.
                $testCode = null;
                if (preg_match('#\[([A-Z0-9\-]{4,})\]#', (string) $email['subject'], $m)) {
                    $testCode = $m[1];
                }
                $testDelivered[] = ['id' => (int) $email['id'], 'code' => $testCode];
                save_platform_setting('last_alert_test_delivered_at', date('Y-m-d H:i:s'));
                if ($testCode !== null) {
                    save_platform_setting('last_alert_test_delivered_code', $testCode);
                }
            }
        } else {
            db()->prepare("UPDATE email_outbox SET status='failed',error_message='mail() başarısız' WHERE id=?")->execute([$email['id']]);
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed, 'test_delivered' => $testDelivered];
}
