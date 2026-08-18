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
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kullanım kılavuzu | NEXUS Admin</title>
  <style>
    body{margin:0;font-family:Arial,sans-serif;background:#f7f7f2;color:#10211f}
    .w{width:min(960px,calc(100% - 32px));margin:35px auto;background:#fff;border:1px solid #ddd;padding:28px 32px;border-radius:8px}
    .nav-bar{position:sticky;top:0;background:#fff;border-bottom:1px solid #e1e5de;padding:10px 0;margin:-28px -32px 20px;padding-left:32px;padding-right:32px;z-index:10;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .nav-bar a{font-size:12px;color:#64716d;text-decoration:none;padding:4px 8px;border-radius:4px;border:1px solid #e1e5de;background:#f7f7f2}
    .nav-bar a:hover{background:#e6f8c7;border-color:#a3d98c}
    @media(max-width:700px){.w{padding:16px}.nav-bar{margin:-16px -16px 16px;padding:8px 16px}}
  </style>
</head>
<body>
<main class="w">
  <a href="/nexustraveltech/admin/" style="color:#64716d;font-size:13px">← Yönetim paneli</a>
  <div class="nav-bar">
    <span style="font-size:12px;color:#64716d;font-weight:700">Bölümler:</span>
    <?php
    preg_match_all('/^## (.+)$/m', $content, $m);
    foreach ($m[1] as $i => $heading) {
        $slug = slug($heading);
        echo '<a href="#sec-' . $slug . '">' . htmlspecialchars($heading) . '</a>';
    }
    ?>
  </div>
  <?= md2html($content) ?>
</main>
</body>
</html>
