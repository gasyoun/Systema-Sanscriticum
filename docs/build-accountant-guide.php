<?php

/*
 * Сборка PDF книги бухгалтера из ЕДИНСТВЕННОГО источника —
 * docs/ACCOUNTANT_CABINET_GUIDE_RU.md (H3214, волна 3).
 *
 * Пишет в storage/app/guide-shots/, не в публичный репозиторий.
 * Учительский / студенческий / кураторский сборщики не трогаем.
 */

require __DIR__.'/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use League\CommonMark\CommonMarkConverter;

$source = __DIR__.'/ACCOUNTANT_CABINET_GUIDE_RU.md';
$shotsDir = dirname(__DIR__).'/storage/app/guide-shots/accountant';
$outDir = dirname(__DIR__).'/storage/app/guide-shots';

if (! is_file($source)) {
    fwrite(STDERR, "Не найден источник: {$source}".PHP_EOL);
    exit(1);
}

$markdown = file_get_contents($source);

if ($markdown === false || trim($markdown) === '') {
    fwrite(STDERR, "Источник пуст: {$source}".PHP_EOL);
    exit(1);
}

if (! is_dir($outDir) && ! mkdir($outDir, 0775, true) && ! is_dir($outDir)) {
    fwrite(STDERR, "Не удалось создать каталог PDF: {$outDir}".PHP_EOL);
    exit(1);
}

$converter = new CommonMarkConverter([
    'html_input' => 'escape',
    'allow_unsafe_links' => false,
]);

$body = (string) $converter->convert($markdown);

$shotsFs = str_replace('\\', '/', $shotsDir).'/';
$body = (string) preg_replace(
    '#(<img[^>]+src=")screenshots/accountant/#i',
    '$1'.$shotsFs,
    $body
);

$css = <<<'CSS'
@page { margin: 24mm 16mm 20mm 16mm; }
* { box-sizing: border-box; }
body {
    font-family: "DejaVu Sans", sans-serif;
    font-size: 10.5pt;
    line-height: 1.5;
    color: #2b2b2b;
    margin: 0;
    padding: 0;
}
h1 {
    font-size: 17pt;
    color: #ffffff;
    background: #1e3a5f;
    padding: 9px 14px;
    margin: 22px 0 14px 0;
    border-radius: 5px;
    page-break-after: avoid;
}
h2 {
    font-size: 13pt;
    color: #1e3a5f;
    margin: 20px 0 6px 0;
    padding-bottom: 4px;
    border-bottom: 2px solid #93c5fd;
    page-break-after: avoid;
}
h3 {
    font-size: 11pt;
    color: #1f2937;
    margin: 14px 0 4px 0;
    page-break-after: avoid;
}
p { margin: 6px 0; }
ul, ol { margin: 6px 0; padding-left: 20px; }
li { margin: 3px 0; }
strong { color: #1f2937; }
a { color: #1e3a5f; text-decoration: none; }
code {
    font-family: "DejaVu Sans Mono", monospace;
    background: #f3f4f6;
    padding: 1px 5px;
    border-radius: 3px;
    font-size: 9.5pt;
}
blockquote {
    background: #eff6ff;
    border-left: 4px solid #1e3a5f;
    padding: 8px 14px;
    margin: 12px 0;
    page-break-inside: avoid;
}
blockquote p { margin: 3px 0; }
table {
    width: 100%;
    border-collapse: collapse;
    margin: 10px 0;
    page-break-inside: avoid;
}
th {
    background: #1e3a5f;
    color: #fff;
    text-align: left;
    padding: 7px 9px;
    font-size: 9.5pt;
}
td {
    border: 1px solid #e5e7eb;
    padding: 6px 9px;
    font-size: 9.5pt;
    vertical-align: top;
}
tr:nth-child(even) td { background: #eff6ff; }
hr { border: none; border-top: 1px solid #e5e7eb; margin: 18px 0; }
img { max-width: 100%; }
CSS;

$html = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><style>'
    .$css
    .'</style></head><body>'
    .$body
    .'</body></html>';

$options = new Options;
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultMediaType', 'print');
$chroot = [__DIR__, $outDir];
if (is_dir($shotsDir)) {
    $chroot[] = $shotsDir;
}
$options->setChroot($chroot);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
$canvas->page_text(
    520, 812,
    'Стр. {PAGE_NUM} / {PAGE_COUNT}',
    $font, 8, [0.6, 0.6, 0.6]
);
$canvas->page_text(
    40, 812,
    'актуально в кабинете: /admin/accountant-guide',
    $font, 8, [0.6, 0.6, 0.6]
);

$out = $outDir.'/accountant-guide.pdf';
file_put_contents($out, $dompdf->output());

echo 'Источник: '.$source.PHP_EOL;
echo 'PDF создан: '.$out.PHP_EOL;
echo 'Размер: '.round(filesize($out) / 1024, 1).' КБ'.PHP_EOL;
echo 'Этот PDF не коммитится в публичный репозиторий.'.PHP_EOL;
