<?php
declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require_admin();

$mdFile = __DIR__ . '/../KULLANIM.md';
$content = file_exists($mdFile) ? file_get_contents($mdFile) : '# Kullanım kılavuzu bulunamadı';

// Basit Markdown → HTML dönüştürücü (sadece bu sayfa için).
function md2html(string $md): string
{
    $lines = explode("\n", $md);
    $html = '';
    $inCode = false;
    $inTable = false;
    $tableRows = [];

    foreach ($lines as $line) {
        // Code block toggle.
        if (preg_match('/^```/', $line)) {
            if ($inTable) { $html .= renderTable($tableRows); $tableRows = []; $inTable = false; }
            $inCode = !$inCode;
            if ($inCode) { $html .= '<pre style="background:#1a2332;color:#e0e0e0;padding:16px;border-radius:8px;overflow-x:auto;font-size:13px;line-height:1.5"><code>'; continue; }
            $html .= '</code></pre>'; continue;
        }
        if ($inCode) { $html .= htmlspecialchars($line) . "\n"; continue; }

        // Table detection.
        if (preg_match('/^\|/', $line)) {
            if (preg_match('/^\|[\s-]+\|/', $line)) continue; // separator row
            $cells = array_map('trim', explode('|', trim($line, '| ')));
            if (!$inTable) $inTable = true;
            $tableRows[] = $cells;
            continue;
        } elseif ($inTable) {
            $html .= renderTable($tableRows);
            $tableRows = [];
            $inTable = false;
        }

        // Headers.
        if (preg_match('/^#### (.+)$/', $line, $m)) { $html .= '<h4 style="margin:18px 0 6px;font-size:14px;color:#374957">' . esc($m[1]) . '</h4>'; continue; }
        if (preg_match('/^### (.+)$/', $line, $m)) { $html .= '<h3 style="margin:20px 0 8px;font-size:16px;color:#1a3d6d">' . esc($m[1]) . '</h3>'; continue; }
        if (preg_match('/^## (.+)$/', $line, $m)) { $html .= '<h2 style="margin:28px 0 10px;font-size:20px;color:#10211f;border-bottom:2px solid #e1e5de;padding-bottom:6px" id="sec-' . slug($m[1]) . '">' . esc($m[1]) . '</h2>'; continue; }
        if (preg_match('/^# (.+)$/', $line, $m)) { $html .= '<h1 style="margin:0 0 12px;font-size:26px;color:#10211f">' . esc($m[1]) . '</h1>'; continue; }

        // Horizontal rule.
        if (preg_match('/^---$/', $line)) { $html .= '<hr style="border:none;border-top:1px solid #e1e5de;margin:24px 0">'; continue; }

        // Blockquote.
        if (preg_match('/^> (.+)$/', $line, $m)) { $html .= '<blockquote style="border-left:3px solid #b26a00;padding:8px 14px;margin:10px 0;background:#fdf9f2;color:#6b4a12;font-size:13px">' . inline($m[1]) . '</blockquote>'; continue; }

        // Empty line.
        if (trim($line) === '') { $html .= "\n"; continue; }

        // Paragraph.
        $html .= '<p style="margin:6px 0;line-height:1.7">' . inline($line) . '</p>' . "\n";
    }
    if ($inTable) $html .= renderTable($tableRows);
    return $html;
}

function esc(string $s): string { return htmlspecialchars($s); }
function slug(string $s): string { return strtolower(preg_replace('/[^a-z0-9]+/', '-', $s)); }
function inline(string $s): string
{
    $s = htmlspecialchars($s);
    $s = preg_replace('/`([^`]+)`/', '<code style="background:#f2f4ef;padding:2px 6px;font-size:12px;border-radius:3px">$1</code>', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $s);
    $s = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2" style="color:#1a5e1a;font-weight:600">$1</a>', $s);
    return $s;
}
function renderTable(array $rows): string
{
    if (!$rows) return '';
    $html = '<div style="overflow-x:auto;margin:12px 0"><table style="border-collapse:collapse;width:100%;font-size:13px">';
    $isFirst = true;
    foreach ($rows as $row) {
        $tag = $isFirst ? 'th' : 'td';
        $bg = $isFirst ? 'background:#f4f6f1;' : '';
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= "<$tag style=\"padding:8px 12px;border:1px solid #e1e5de;$bg;text-align:left;vertical-align:top\">" . inline($cell) . "</$tag>";
        }
        $html .= '</tr>';
        $isFirst = false;
    }
    $html .= '</table></div>';
    return $html;
}
?>
<?php
require_once __DIR__ . '/layout.php';
admin_layout_start('Sistem & Geliştirici Kullanım Kılavuzu', 'kullanim-kilavuzu');
?>

<div class="sui-card" style="margin-bottom:20px">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <span style="font-size:12px;color:var(--sui-muted);font-weight:700">📑 Hızlı Atlama:</span>
        <?php
        preg_match_all('/^## (.+)$/m', $content, $m);
        foreach ($m[1] as $heading) {
            $slug = slug($heading);
            echo '<a href="#sec-' . $slug . '" class="sui-btn sui-btn-outline sui-btn-sm">' . htmlspecialchars($heading) . '</a>';
        }
        ?>
    </div>
</div>

<div class="sui-card" style="line-height:1.7;padding:32px">
    <?= md2html($content) ?>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

