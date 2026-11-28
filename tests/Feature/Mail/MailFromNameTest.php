<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Support\Mail\SenderNameSetting;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 24-11-2026: имя отправителя меняется одной командой, потому что у правки .env
 * есть два невидимых шага (кеш конфига и перезапуск воркеров). Тесты держат
 * именно их: файл переписан ровно в одной строке, а расхождение с кешем видно
 * и в выводе команды, и в её коде возврата.
 */
class MailFromNameTest extends TestCase
{
    private string $dir;

    private string $envPath;

    private string $cachePath;

    /** @var list<string> */
    private array $backupsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('app/testing/mail-from-name/'.uniqid('', true));
        File::ensureDirectoryExists($this->dir);

        $this->envPath = $this->dir.'/.env';
        $this->cachePath = $this->dir.'/config.php';

        $this->writeEnv([
            'APP_NAME=Laravel',
            'MAIL_MAILER=smtp',
            'MAIL_FROM_ADDRESS=rusamskrtam@yandex.ru',
            'MAIL_FROM_NAME="Институт исследования санскрита Общества ревнителей санскрита"',
            'QUEUE_CONNECTION=redis',
        ]);

        $this->bindSetting();

        $this->backupsBefore = glob(storage_path('app/mail-from-name').'/.env.*') ?: [];
    }

    protected function tearDown(): void
    {
        foreach (array_diff(glob(storage_path('app/mail-from-name').'/.env.*') ?: [], $this->backupsBefore) as $created) {
            @unlink($created);
        }

        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** @param  list<string>  $lines */
    private function writeEnv(array $lines, string $eol = "\n"): void
    {
        file_put_contents($this->envPath, implode($eol, $lines).$eol);
    }

    private function bindSetting(): void
    {
        $this->app->instance(
            SenderNameSetting::class,
            new SenderNameSetting($this->envPath, $this->cachePath),
        );
    }

    private function writeConfigCache(string $name): void
    {
        $config = [
            'mail' => [
                'from' => [
                    'address' => 'rusamskrtam@yandex.ru',
                    'name' => $name,
                ],
            ],
        ];

        file_put_contents($this->cachePath, '<?php return '.var_export($config, true).';'.PHP_EOL);
        $this->bindSetting();
    }

    private function envContents(): string
    {
        return (string) file_get_contents($this->envPath);
    }

    /** @test */
    public function it_shows_the_env_value_without_touching_the_file(): void
    {
        $before = sha1($this->envContents());

        $this->artisan('mail:from-name')
            ->assertSuccessful()
            ->expectsOutputToContain('MAIL_FROM_NAME="Институт исследования санскрита Общества ревнителей санскрита"');

        $this->assertSame($before, sha1($this->envContents()));
    }

    /** @test */
    public function it_rewrites_only_the_from_name_line_and_keeps_a_backup(): void
    {
        $this->artisan('mail:from-name', ['name' => 'Общество ревнителей санскрита', '--no-cache' => true])
            ->assertSuccessful();

        $contents = $this->envContents();

        $this->assertStringContainsString('MAIL_FROM_NAME="Общество ревнителей санскрита"', $contents);
        $this->assertStringNotContainsString('Институт исследования санскрита', $contents);
        $this->assertStringContainsString('APP_NAME=Laravel', $contents);
        $this->assertStringContainsString('MAIL_FROM_ADDRESS=rusamskrtam@yandex.ru', $contents);
        $this->assertStringContainsString('QUEUE_CONNECTION=redis', $contents);
        $this->assertSame(1, substr_count($contents, 'MAIL_FROM_NAME='));

        $backups = glob(storage_path('app/mail-from-name').'/.env.*') ?: [];
        $this->assertNotEmpty(array_diff($backups, $this->backupsBefore), 'Копия .env не создана.');
    }

    /** @test */
    public function it_keeps_crlf_line_endings_of_the_original_file(): void
    {
        $this->writeEnv([
            'APP_NAME=Laravel',
            'MAIL_FROM_NAME="Старое имя"',
        ], "\r\n");
        $this->bindSetting();

        $this->artisan('mail:from-name', ['name' => 'Общество ревнителей санскрита', '--no-cache' => true])
            ->assertSuccessful();

        $this->assertStringContainsString("MAIL_FROM_NAME=\"Общество ревнителей санскрита\"\r\n", $this->envContents());
    }

    /** @test */
    public function it_appends_the_line_when_the_env_has_none(): void
    {
        $this->writeEnv(['APP_NAME=Laravel']);
        $this->bindSetting();

        $this->artisan('mail:from-name', ['name' => 'Общество ревнителей санскрита', '--no-cache' => true])
            ->assertSuccessful();

        $setting = new SenderNameSetting($this->envPath, null);
        $this->assertSame('Общество ревнителей санскрита', $setting->raw());
        $this->assertStringContainsString('APP_NAME=Laravel', $this->envContents());
    }

    /** @test */
    public function it_warns_that_without_a_cache_rebuild_the_change_is_invisible(): void
    {
        $this->writeConfigCache('Институт исследования санскрита Общества ревнителей санскрита');

        $this->artisan('mail:from-name', ['name' => 'Общество ревнителей санскрита', '--no-cache' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('НЕ пересобран')
            ->expectsOutputToContain('config:cache');

        // Ровно та ловушка, ради которой команда существует: .env новый, письма старые.
        $setting = new SenderNameSetting($this->envPath, $this->cachePath);
        $this->assertSame('Общество ревнителей санскрита', $setting->raw());
        $this->assertSame('Институт исследования санскрита Общества ревнителей санскрита', $setting->effective());
        $this->assertTrue($setting->isStale());
    }

    /** @test */
    public function it_reports_drift_with_a_non_zero_exit_so_it_can_be_scripted(): void
    {
        $this->writeConfigCache('Старое имя');

        $this->artisan('mail:from-name')
            ->assertFailed()
            ->expectsOutputToContain('Кеш конфига расходится с .env');
    }

    /** @test */
    public function it_says_there_is_no_drift_when_the_cache_matches_the_env(): void
    {
        $this->writeConfigCache('Институт исследования санскрита Общества ревнителей санскрита');

        $this->artisan('mail:from-name')
            ->assertSuccessful()
            ->expectsOutputToContain('Расхождения нет');
    }

    /** @test */
    public function it_rejects_values_that_would_break_the_env_file(): void
    {
        $before = sha1($this->envContents());

        foreach (['', "Имя\nвторая строка", 'Имя с "кавычкой"', 'Имя с $переменной', str_repeat('я', SenderNameSetting::MAX_LENGTH + 1)] as $bad) {
            $this->artisan('mail:from-name', ['name' => $bad, '--no-cache' => true])
                ->assertFailed();
        }

        $this->assertSame($before, sha1($this->envContents()), '.env изменён при недопустимом имени.');
    }

    /** @test */
    public function dry_run_changes_nothing(): void
    {
        $before = sha1($this->envContents());

        $this->artisan('mail:from-name', ['name' => 'Общество ревнителей санскрита', '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('dry-run');

        $this->assertSame($before, sha1($this->envContents()));
    }

    /** @test */
    public function it_reads_a_quoted_and_an_unquoted_value_alike(): void
    {
        $this->writeEnv(['MAIL_FROM_NAME=Общество ревнителей санскрита']);
        $this->assertSame('Общество ревнителей санскрита', (new SenderNameSetting($this->envPath, null))->raw());

        $this->writeEnv(['MAIL_FROM_NAME="Общество ревнителей санскрита"']);
        $this->assertSame('Общество ревнителей санскрита', (new SenderNameSetting($this->envPath, null))->raw());
    }

    /** @test */
    public function it_does_not_call_a_substituted_value_drifted(): void
    {
        // `${APP_NAME}` интерполируется dotenv: сырая строка и значение в конфиге
        // заведомо разные, и объявлять это дрейфом было бы ложной тревогой.
        $this->writeEnv(['APP_NAME=Laravel', 'MAIL_FROM_NAME="${APP_NAME}"']);
        $this->writeConfigCache('Laravel');

        $this->assertFalse((new SenderNameSetting($this->envPath, $this->cachePath))->isStale());
    }
}
