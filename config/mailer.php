<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/platform_settings.php';

/**
 * E-postayı kuyruğa ekler; gönderim cron/process-emails.php üzerinden yapılır.
 */
function queue_email(string $to, string $subject, string $bodyHtml, ?string $relatedType = null, ?int $relatedId = null): void
{
    db()->prepare('INSERT INTO email_outbox(to_address,subject,body_html,related_type,related_id) VALUES(?,?,?,?,?)')
        ->execute([trim($to), mb_substr(trim($subject), 0, 190), $bodyHtml, $relatedType, $relatedId]);
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
    foreach ($query->fetchAll() as $email) {
        $headers = "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "From: NEXUS TravelTech <no-reply@nexustraveltech.com>\r\n"
            . "X-Mailer: NEXUS/1.0\r\n";
        $subject = '=?UTF-8?B?' . base64_encode((string) $email['subject']) . '?=';
        $ok = @mail((string) $email['to_address'], $subject, (string) $email['body_html'], $headers);
        if ($ok) {
            db()->prepare("UPDATE email_outbox SET status='sent',sent_at=now(),error_message=NULL WHERE id=?")->execute([$email['id']]);
            $sent++;
        } else {
            db()->prepare("UPDATE email_outbox SET status='failed',error_message='mail() başarısız' WHERE id=?")->execute([$email['id']]);
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}
