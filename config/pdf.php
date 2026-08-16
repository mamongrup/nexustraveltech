<?php

declare(strict_types=1);

/**
 * PDF üretimi. TCPDF kuruluysa (composer require tecnickcom/tcpdf) gerçek PDF,
 * değilse yazdırılabilir HTML indirir — her iki durumda da çalışır.
 */

function pdf_available(): bool
{
    return class_exists('TCPDF');
}

/**
 * HTML'i PDF baytlarına çevirir; TCPDF yoksa null döner (e-posta eki vb. için).
 */
function pdf_build(string $html): ?string
{
    if (!pdf_available()) return null;
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('NEXUS TravelTech');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');
    return $pdf->Output('', 'S');
}

/**
 * HTML içeriği PDF (veya fallback HTML) olarak tarayıcıya indirir.
 */
function pdf_download(string $html, string $filename): void
{
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'belge';
    $filename = str_ends_with($filename, '.pdf') ? $filename : $filename . '.pdf';

    $bytes = pdf_build($html);
    if ($bytes !== null) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $bytes;
        exit;
    }

    // Fallback: yazdırılabilir HTML dosyası olarak indir.
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.html"');
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>' . htmlspecialchars($filename) . '</title></head>'
        . '<body style="font-family:Arial;color:#10211f">' . $html . '</body></html>';
    exit;
}
