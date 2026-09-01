<?php

/*
 * Сборка PDF руководства преподавателя из ЕДИНСТВЕННОГО источника —
 * docs/TEACHER_CABINET_GUIDE_RU.md (H2501, волна 1).
 *
 * До волны 1 источником был docs/teacher-guide.html, и правку приходилось
 * вносить в разметку руками — из-за этого руководство отстало на два месяца
 * незамеченным. Теперь HTML собирается из Markdown на лету, а печатные стили
 * живут здесь, рядом со сборкой, и в текст не протекают.
 */

require __DIR__.'/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use League\CommonMark\CommonMarkConverter;

$source = __DIR__.'/TEACHER_CABINET_GUIDE_RU.md';

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

/*
 * Кадры разделов (H2502) записаны в Markdown относительным путём
 * `screenshots/teacher-guide/*.png` — так они рендерятся на GitHub. Dompdf же
 * считает относительный путь от базы документа, а базы у строки HTML нет:
 * `setBasePath()` тут не спасает, потому что до картинок дело доходит через
 * `Helpers::build_url()` с проверкой chroot. Поэтому разворачиваем путь в
 * абсолютный ПРЯМО В РАЗМЕТКЕ, а chroot ставим на docs/ — тогда сборка не
 * читает ничего за пределами каталога руководства.
 */
$body = (string) preg_replace(
    '#(<img[^>]+src=")screenshots/#i',
    '$1'.str_replace('\\', '/', __DIR__).'/screenshots/',
    $body
);

/*
 * Печатные стили. Шрифт DejaVu Sans выбран потому, что он покрывает кириллицу;
 * эмодзи он НЕ покрывает — поэтому маркеры обратимости в тексте руководства
 * заданы словами, а не цветными кружками (иначе в PDF были бы пустые
 * прямоугольники).
 */
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
    background: #b45309;
    padding: 9px 14px;
    margin: 22px 0 14px 0;
    border-radius: 5px;
    page-break-after: avoid;
}
h2 {
    font-size: 13pt;
    color: #92400e;
    margin: 20px 0 6px 0;
    padding-bottom: 4px;
    border-bottom: 2px solid #fde68a;
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
a { color: #b45309; text-decoration: none; }
code {
    font-family: "DejaVu Sans Mono", monospace;
    background: #f3f4f6;
    padding: 1px 5px;
    border-radius: 3px;
    font-size: 9.5pt;
    color: #b45309;
}
blockquote {
    background: #eff6ff;
    border-left: 4px solid #3b82f6;
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
    background: #92400e;
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
tr:nth-child(even) td { background: #faf7f2; }
hr { border: none; border-top: 1px solid #e5e7eb; margin: 18px 0; }
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
// Читать разрешено только каталог руководства — картинки лежат в нём.
$options->setChroot([__DIR__]);

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

$out = __DIR__.'/rukovodstvo-prepodavatelya.pdf';
file_put_contents($out, $dompdf->output());

echo 'Источник: '.$source.PHP_EOL;
echo 'PDF создан: '.$out.PHP_EOL;
echo 'Размер: '.round(filesize($out) / 1024, 1).' КБ'.PHP_EOL;
