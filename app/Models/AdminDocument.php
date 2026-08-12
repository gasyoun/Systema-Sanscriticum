<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MaterialUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Важный документ админки (H2570) — строка реестра рабочих файлов, которыми
 * пользуются суперадмин и админ: таблицы Google Sheets, регламенты, реестры.
 *
 * Модель НИКОГДА не отдаётся студенческой витрине: единственный её потребитель —
 * AdminDocumentResource в панели `admin`. Родственная CourseMaterial решает
 * обратную задачу (ссылка ДЛЯ студента внутри курса) — общий у них только
 * MaterialUrl, и это единственное, что стоит переиспользовать.
 *
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string $source_url
 * @property string|null $host
 * @property string $kind
 * @property string $category
 * @property int $sort_order
 * @property bool $super_admin_only
 * @property bool $is_active
 * @property int|null $added_by_user_id
 */
class AdminDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'source_url',
        'host',
        'kind',
        'category',
        'sort_order',
        'super_admin_only',
        'is_active',
        'added_by_user_id',
    ];

    protected $casts = [
        'super_admin_only' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Типы документов и подписи для админа.
     *
     * Своя таблица, а НЕ CourseMaterial::KINDS: у внутренних документов другая
     * природа — здесь важно «это таблица или текстовый документ», а не
     * «аудио/видео/архив». Ключи 'pdf' и 'page' сознательно совпадают со
     * значениями MaterialUrl::KIND_BY_EXT / guessKind(), чтобы вывод типа из
     * расширения попадал в существующий ключ.
     */
    public const KINDS = [
        'sheet' => 'Таблица',
        'doc' => 'Документ',
        'form' => 'Форма',
        'presentation' => 'Презентация',
        'drive' => 'Папка / диск',
        'pdf' => 'PDF / книга',
        'page' => 'Страница',
        'link' => 'Ссылка',
    ];

    /**
     * Разделы библиотеки. Плоский список строк, а не таблица справочника:
     * набор меняется раз в год, а join ради шести значений усложнил бы и форму,
     * и выборку. Появится нужда в произвольных разделах — тогда и вынесем.
     */
    public const CATEGORIES = [
        'finance' => 'Финансы',
        'legal' => 'Юридическое',
        'teaching' => 'Обучение',
        'marketing' => 'Маркетинг',
        'ops' => 'Операционное',
        'other' => 'Прочее',
    ];

    /**
     * Google-сервис → тип документа. Ключ ищется как подстрока пути URL, чтобы
     * /spreadsheets/d/... и /document/d/... различались без разбора id.
     */
    private const KIND_BY_GOOGLE_PATH = [
        '/spreadsheets/' => 'sheet',
        '/document/' => 'doc',
        '/forms/' => 'form',
        '/presentation/' => 'presentation',
        '/drive/' => 'drive',
        '/file/' => 'drive',
    ];

    /**
     * Нормализация живёт в модели, а не в форме Filament: сиды, тесты и будущий
     * импорт обязаны получать тот же host/kind, что и ручной ввод куратора.
     */
    protected static function booted(): void
    {
        static::creating(function (self $document): void {
            $document->applyUrlDerivations();
        });

        static::updating(function (self $document): void {
            // Пересчитываем только когда изменился сам URL: иначе ручная правка
            // kind («это не таблица, а форма») затиралась бы при каждом save().
            // При смене URL kind форсируется — иначе новый файл наследовал бы
            // тип старого до тех пор, пока куратор не поставит его вручную.
            if ($document->isDirty('source_url')) {
                $document->applyUrlDerivations(forceKind: true);
            }
        });
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeShelfOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('title');
    }

    /**
     * Строки, которые вправе видеть данный пользователь.
     *
     * Единственный источник правды для сужения по super_admin_only: и ресурс, и
     * тесты спрашивают модель, а не повторяют условие where у себя.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null || ! $user->isAdminLike()) {
            // Не админоподобный — не видит ничего. Пустая выборка, а не
            // исключение: ресурс и так закрыт гейтом, это второй контур.
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->where('super_admin_only', false);
    }

    /**
     * Человеческая подпись типа.
     */
    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? self::KINDS['link'];
    }

    /**
     * Человеческая подпись раздела.
     */
    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? self::CATEGORIES['other'];
    }

    /**
     * Вывести host и kind из source_url.
     *
     * download_url здесь НЕ выводим (в отличие от CourseMaterial): Google Sheets
     * и Docs незачем «скачивать» — админ открывает живой документ, а export-URL
     * отдал бы снапшот и потерял бы вкладку gid.
     */
    protected function applyUrlDerivations(bool $forceKind = false): void
    {
        $this->host = MaterialUrl::host($this->source_url);

        if ($forceKind || blank($this->kind) || $this->kind === 'link') {
            $this->kind = self::guessKind($this->source_url);
        }

        if (blank($this->title)) {
            $this->title = MaterialUrl::guessTitle($this->source_url) ?? 'Без названия';
        }
    }

    /**
     * Тип документа по URL: сперва Google-сервисы (у них расширения нет),
     * иначе — общий вывод по расширению из MaterialUrl.
     */
    public static function guessKind(?string $url): string
    {
        if ($url === null || trim($url) === '') {
            return 'link';
        }

        $host = MaterialUrl::host($url);
        $path = parse_url(trim($url), PHP_URL_PATH);

        if ($host !== null && str_contains($host, 'google.com') && is_string($path)) {
            foreach (self::KIND_BY_GOOGLE_PATH as $needle => $kind) {
                if (str_contains($path, $needle)) {
                    return $kind;
                }
            }
        }

        $kind = MaterialUrl::guessKind($url);

        // MaterialUrl знает про audio/video/archive — для внутреннего документа
        // такие типы бессмысленны, сводим их к нейтральной ссылке.
        return array_key_exists($kind, self::KINDS) ? $kind : 'link';
    }
}
