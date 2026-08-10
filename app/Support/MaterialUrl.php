<?php

namespace App\Support;

/**
 * Нормализация внешних ссылок на материалы курса.
 *
 * Фаза 1 библиотеки курсов: файл остаётся у преподавателя (Dropbox, Google Drive,
 * сайт издательства), у нас живёт только строка реестра. Поэтому вся работа с
 * URL — здесь, в одном месте: форма Filament, сиды, тесты и будущий разбор
 * сообщений обязаны получать один и тот же ответ на вопрос «какая ссылка ведёт
 * прямо к файлу».
 */
class MaterialUrl
{
    /**
     * Параметры, которые срезаем при нормализации: аналитика и короткоживущие
     * токены сессии (`st` у Dropbox живёт минуты и в реестре бесполезен).
     *
     * ⚠️ `rlkey` у Dropbox — ЧАСТЬ АВТОРИЗАЦИИ share-ссылки, а не трекинг.
     * Срезать его — сломать доступ к файлу. Никогда не добавлять сюда.
     */
    public const DROP_PARAMS = [
        'st',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_id',
    ];

    /**
     * Расширение → тип материала. Ключи должны совпадать с
     * App\Models\CourseMaterial::KINDS (плюс 'page' как значение по умолчанию).
     */
    public const KIND_BY_EXT = [
        'pdf' => 'pdf',
        'djvu' => 'pdf',
        'epub' => 'pdf',
        'fb2' => 'pdf',
        'mobi' => 'pdf',
        'doc' => 'pdf',
        'docx' => 'pdf',
        'mp3' => 'audio',
        'm4a' => 'audio',
        'wav' => 'audio',
        'ogg' => 'audio',
        'flac' => 'audio',
        'mp4' => 'video',
        'mkv' => 'video',
        'avi' => 'video',
        'mov' => 'video',
        'webm' => 'video',
        'zip' => 'archive',
        'rar' => 'archive',
        '7z' => 'archive',
        'tar' => 'archive',
        'gz' => 'archive',
    ];

    /**
     * Хост ссылки без www — то, что показываем студенту как «где лежит».
     */
    public static function host(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $host = parse_url(trim($url), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./i', '', strtolower($host));
    }

    /**
     * Ссылка, ведущая прямо к байтам файла, если хост нам известен.
     *
     * Для неизвестных хостов возвращаем очищенный URL, но НЕ выдаём его за
     * прямую ссылку — врать про это хуже, чем не знать: студент всё равно
     * откроет страницу, а куратор не будет думать, что скачивание проверено.
     */
    public static function downloadUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        $host = self::host($url);

        if ($host === null) {
            return null;
        }

        // Dropbox: dl=0 отдаёт HTML-превью, dl=1 — сам файл. rlkey сохраняем.
        if (str_contains($host, 'dropbox.com')) {
            return self::rebuild($url, ['dl' => '1'], ['raw']);
        }

        // Google Drive: /file/d/<id>/view → прямая выдача по id.
        if ($host === 'drive.google.com' && preg_match('#/file/d/([^/]+)#', $url, $m) === 1) {
            return 'https://drive.google.com/uc?export=download&id='.$m[1];
        }

        return self::rebuild($url);
    }

    /**
     * Тип материала по расширению в пути URL; по умолчанию — страница.
     */
    public static function guessKind(?string $url): string
    {
        if ($url === null || trim($url) === '') {
            return 'page';
        }

        $path = parse_url(trim($url), PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return 'page';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::KIND_BY_EXT[$ext] ?? 'page';
    }

    /**
     * Черновое название из имени файла в URL — чтобы куратор правил готовую
     * строку, а не набирал её с нуля. Финальное название всегда за человеком.
     */
    public static function guessTitle(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $path = parse_url(trim($url), PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $name = rawurldecode(pathinfo($path, PATHINFO_FILENAME));

        if ($name === '') {
            return null;
        }

        // Разделители имени файла → пробелы, служебные хвосты вида 600dpi/lossy
        // оставляем: их проще удалить руками, чем угадать, что они значимы.
        $name = str_replace(['_', '.', '-'], ' ', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return $name === '' ? null : mb_substr($name, 0, 255);
    }

    /**
     * Пересобрать URL: срезать DROP_PARAMS (и явно перечисленные), выставить
     * нужные значения. Порядок остальных параметров сохраняется.
     *
     * @param  array<string, string>  $set
     * @param  array<int, string>  $drop
     */
    private static function rebuild(string $url, array $set = [], array $drop = []): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $url;
        }

        $query = [];

        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }

        foreach (array_merge(self::DROP_PARAMS, $drop) as $key) {
            unset($query[$key]);
        }

        foreach ($set as $key => $value) {
            $query[$key] = $value;
        }

        $rebuilt = '';

        if (isset($parts['scheme'])) {
            $rebuilt .= $parts['scheme'].'://';
        }
        if (isset($parts['host'])) {
            $rebuilt .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        if (isset($parts['path'])) {
            $rebuilt .= $parts['path'];
        }
        if ($query !== []) {
            $rebuilt .= '?'.http_build_query($query);
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt === '' ? $url : $rebuilt;
    }
}
