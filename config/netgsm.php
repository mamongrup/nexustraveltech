<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/platform_settings.php';

function netgsm_phone(string $phone): string
{
    $number = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($number, '00')) $number = substr($number, 2);
    if (str_starts_with($number, '0') && strlen($number) === 11) $number = '90' . substr($number, 1);
    if (str_starts_with($number, '5') && strlen($number) === 10) $number = '90' . $number;
    if (!preg_match('/^90[5][0-9]{9}$/', $number)) throw new RuntimeException('Geçersiz Türkiye GSM numarası.');
    return $number;
}

function process_netgsm_sms_outbox(int $limit = 25): array
{
    if (!platform_setting('netgsm_sms_enabled', false)) return ['sent' => 0, 'failed' => 0, 'reason' => 'Netgsm gönderimi yönetim panelinden kapalı.'];
    $settings = netgsm_settings();
    if ($settings['usercode'] === '' || $settings['password'] === '' || $settings['header'] === '') return ['sent' => 0, 'failed' => 0, 'reason' => 'Netgsm API bilgileri eksik.'];
    if (!function_exists('curl_init')) return ['sent' => 0, 'failed' => 0, 'reason' => 'PHP cURL eklentisi etkin değil.'];

    $limit = max(1, min(100, $limit));
    $query = db()->prepare("SELECT * FROM sms_outbox WHERE status='queued' ORDER BY id ASC LIMIT ?");
    $query->bindValue(1, $limit, PDO::PARAM_INT); $query->execute();
    $sent = 0; $failed = 0;
    foreach ($query->fetchAll() as $sms) {
        try {
            $params = ['usercode' => $settings['usercode'], 'password' => $settings['password'], 'gsmno' => netgsm_phone((string) $sms['phone']), 'message' => (string) $sms['message'], 'msgheader' => $settings['header'], 'dil' => 'TR'];
            $curl = curl_init($settings['endpoint'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true]);
            $response = trim((string) curl_exec($curl)); $curlError = curl_error($curl); curl_close($curl);
            if ($curlError !== '' || !preg_match('/^00\b/', $response)) throw new RuntimeException($curlError !== '' ? $curlError : ('Netgsm yanıtı: ' . substr($response, 0, 250)));
            db()->prepare("UPDATE sms_outbox SET status='sent',provider_message_id=?,sent_at=now(),error_message=NULL WHERE id=?")->execute([$response, $sms['id']]); $sent++;
        } catch (Throwable $exception) {
            db()->beginTransaction();
            try { db()->prepare("UPDATE sms_outbox SET status='failed',error_message=? WHERE id=? AND status='queued'")->execute([substr($exception->getMessage(), 0, 500), $sms['id']]); db()->prepare('UPDATE sms_entitlements SET credits_remaining=credits_remaining+1,updated_at=now() WHERE account_type=? AND account_id=?')->execute([$sms['account_type'], $sms['account_id']]); db()->commit(); } catch(Throwable $rollback) { if(db()->inTransaction()) db()->rollBack(); }
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed, 'reason' => ''];
}
