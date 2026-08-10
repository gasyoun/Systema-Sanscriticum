<?php

namespace Tests\Unit;

use App\Support\MaterialUrl;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Нормализация ссылок на материалы курса (H2527, фаза 1).
 *
 * Главный инвариант, который тут защищается: rlkey у Dropbox — часть
 * авторизации share-ссылки. Срезать его = сломать доступ к файлу.
 */
class MaterialUrlTest extends TestCase
{
    private const DROPBOX = 'https://www.dropbox.com/scl/fi/xv6pwlanwez76vt4t1u6p/'
        .'Cardona_Panini-his-work-and-its-traditions.-Volume-I.-Background-and-Introduction.'
        .'-Cardona-Delhi-1997-600dpi-lossy.pdf?rlkey=mznag7r36igeqdb043m78not6&st=bwpn8ew2&dl=0';

    #[Test]
    public function dropbox_preview_becomes_direct_download(): void
    {
        $url = MaterialUrl::downloadUrl(self::DROPBOX);

        $this->assertStringContainsString('dl=1', $url);
        $this->assertStringNotContainsString('dl=0', $url);
    }

    #[Test]
    public function dropbox_rlkey_is_preserved(): void
    {
        // Регресс-тест: без rlkey ссылка перестаёт открываться.
        $this->assertStringContainsString(
            'rlkey=mznag7r36igeqdb043m78not6',
            MaterialUrl::downloadUrl(self::DROPBOX)
        );
    }

    #[Test]
    public function short_lived_session_token_is_stripped(): void
    {
        $this->assertStringNotContainsString('st=', MaterialUrl::downloadUrl(self::DROPBOX));
    }

    #[Test]
    public function utm_params_are_stripped(): void
    {
        $url = MaterialUrl::downloadUrl('https://example.org/book.pdf?utm_source=tg&utm_medium=post&page=7');

        $this->assertStringNotContainsString('utm_source', $url);
        $this->assertStringNotContainsString('utm_medium', $url);
        $this->assertStringContainsString('page=7', $url);
    }

    #[Test]
    public function google_drive_file_link_becomes_direct(): void
    {
        $this->assertSame(
            'https://drive.google.com/uc?export=download&id=1AbCdEfGhIjK',
            MaterialUrl::downloadUrl('https://drive.google.com/file/d/1AbCdEfGhIjK/view?usp=sharing')
        );
    }

    #[Test]
    public function host_is_lowercased_without_www(): void
    {
        $this->assertSame('dropbox.com', MaterialUrl::host(self::DROPBOX));
        $this->assertSame('example.org', MaterialUrl::host('https://WWW.Example.ORG/a.pdf'));
        $this->assertNull(MaterialUrl::host(''));
        $this->assertNull(MaterialUrl::host(null));
    }

    #[Test]
    public function kind_is_guessed_from_extension(): void
    {
        $this->assertSame('pdf', MaterialUrl::guessKind(self::DROPBOX));
        $this->assertSame('pdf', MaterialUrl::guessKind('https://example.org/a.djvu'));
        $this->assertSame('audio', MaterialUrl::guessKind('https://example.org/a.mp3'));
        $this->assertSame('video', MaterialUrl::guessKind('https://example.org/a.mp4'));
        $this->assertSame('archive', MaterialUrl::guessKind('https://example.org/a.zip'));
        // Без расширения — просто страница, а не угадывание.
        $this->assertSame('page', MaterialUrl::guessKind('https://example.org/catalog/panini'));
        $this->assertSame('page', MaterialUrl::guessKind(null));
    }

    #[Test]
    public function title_is_drafted_from_file_name(): void
    {
        $title = MaterialUrl::guessTitle(self::DROPBOX);

        $this->assertStringContainsString('Cardona', $title);
        // Разделители имени файла превращаются в пробелы.
        $this->assertStringNotContainsString('_', $title);
        $this->assertStringNotContainsString('-', $title);
        $this->assertNull(MaterialUrl::guessTitle(null));
    }

    #[Test]
    public function unknown_host_is_cleaned_but_not_faked_as_direct(): void
    {
        // Неизвестный хост: чистим параметры, но путь не переписываем.
        $this->assertSame(
            'https://example.org/library/panini.pdf',
            MaterialUrl::downloadUrl('https://example.org/library/panini.pdf?st=abc')
        );
    }

    #[Test]
    public function kind_map_matches_model_labels(): void
    {
        // KIND_BY_EXT и CourseMaterial::KINDS обязаны согласовываться, иначе
        // студент увидит сырой ключ вместо подписи.
        $labelled = array_keys(\App\Models\CourseMaterial::KINDS);

        foreach (array_unique(array_values(MaterialUrl::KIND_BY_EXT)) as $kind) {
            $this->assertContains($kind, $labelled, "Тип {$kind} не имеет подписи в CourseMaterial::KINDS");
        }
    }

    #[Test]
    public function rlkey_is_never_in_the_drop_list(): void
    {
        // Защита от «оптимизации»: кто-то решит, что rlkey — трекинг.
        $this->assertNotContains('rlkey', MaterialUrl::DROP_PARAMS);
    }
}
