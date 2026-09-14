<?php

/*
 * Сборка PDF гида куратора из ЕДИНСТВЕННОГО источника —
 * docs/CURATOR_ADMIN_GUIDE_RU.md (H3213, волна 2).
 *
 * Учительский и студенческий сборщики не трогаем.
 */

require __DIR__.'/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use League\CommonMark\CommonMarkConverter;

$source = __DIR__.'/CURATOR_ADMIN_GUIDE_RU.md';

if (! is_file($source)) {
    fwrite(STDERR, "Не найден источник: {$source}".PHP_EOL);
    exit(1);
}

$markdown = file_get_contents($source);

if ($markdown === false || trim($markdown) === '') {
    fwrite(STDERR, "Источник пуст: {$source}".PHP_EOL);
    exit(1);
}

$converter = new CommonMarkConverter([
    'html_input' => 'escape',
    'allow_unsafe_links' => false,
]);

$body = (string) $converter->convert($markdown);

$body = (string) preg_replace(
    '#(<img[^>]+src=")screenshots/#i',
    '$1'.str_replace('\\', '/', __DIR__).'/screenshots/',
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
    background: #c2410c;
    padding: 9px 14px;
    margin: 22px 0 14px 0;
    border-radius: 5px;
    page-break-after: avoid;
}
h2 {
    font-size: 13pt;
    color: #9a3412;
    margin: 20px 0 6px 0;
    padding-bottom: 4px;
    border-bottom: 2px solid #fdba74;
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
a { color: #c2410c; text-decoration: none; }
code {
    font-family: "DejaVu Sans Mono", monospace;
    background: #f3f4f6;
    padding: 1px 5px;
    border-radius: 3px;
    font-size: 9.5pt;
}
blockquote {
    background: #fff7ed;
    border-left: 4px solid #E85C24;
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
    background: #9a3412;
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
tr:nth-child(even) td { background: #fff7ed; }
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
$options->setChroot([__DIR__]);

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
    'актуально в кабинете: /admin/curator-guide',
    $font, 8, [0.6, 0.6, 0.6]
);

$out = __DIR__.'/rukovodstvo-kuratora.pdf';
file_put_contents($out, $dompdf->output());

echo 'Источник: '.$source.PHP_EOL;
echo 'PDF создан: '.$out.PHP_EOL;
echo 'Размер: '.round(filesize($out) / 1024, 1).' КБ'.PHP_EOL;
