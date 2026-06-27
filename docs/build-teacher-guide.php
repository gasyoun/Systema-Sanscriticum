<?php

require __DIR__.'/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$html = file_get_contents(__DIR__.'/teacher-guide.html');

$options = new Options;
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultMediaType', 'print');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Колонтитул со страницами
$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
$canvas->page_text(
    520, 812,
    'Стр. {PAGE_NUM} / {PAGE_COUNT}',
    $font, 8, [0.6, 0.6, 0.6]
);
$canvas->page_text(
    40, 812,
    'Система «Санскритикум» · Руководство преподавателя',
    $font, 8, [0.6, 0.6, 0.6]
);

$out = __DIR__.'/Руководство преподавателя.pdf';
file_put_contents($out, $dompdf->output());

echo 'PDF создан: '.$out.PHP_EOL;
echo 'Размер: '.round(filesize($out) / 1024, 1).' КБ'.PHP_EOL;
