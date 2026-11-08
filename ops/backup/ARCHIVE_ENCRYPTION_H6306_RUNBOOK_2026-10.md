# H6306 Runbook: отказ незашифрованного off-site архива + установка секрета

_Дата: 11-10-2026 · Repo: Systema-Sanscriticum · Источник разрыва: [reports/MATURITY_BACKUPS_DR_2026-10-09.md](../../reports/MATURITY_BACKUPS_DR_2026-10-09.md) канал 3_

## Что изменилось (код)

| Поверхность | Поведение |
|---|---|
| `App\Support\Backup\OffsiteArchiveEncryptionGate` | Единая точка правды: offsite включён? пароль задан? (`trim !== ''`, пустая строка = отсутствие). Значение пароля наружу не отдаётся — только факты и env-ключ в тексте причины. |
| `SplitUploadToYandex::handle()` | Префлайт ДО первой части: нет пароля → `Log::error` с причиной + `return`. Ни одна часть не уезжает. Local-копия не страдает. |
| `SplitUploadToYandex::resumeOffsite()` | Тот же отказ для докатки (`backup:resume-yandex-parts`): незавершённая группа НЕ добивается без пароля. |
| `DeployConfigPreflight` (deploy:config-preflight) | В проде при включённой off-site ноге без пароля — **hard-fail деплоя** (наравне с SESSION_SECURE_COOKIE, H3311). Rollback-деплой не блокирует (вызывается только в forward-деплое). |

Не тронуто: local-копия spatie (пишется как раньше), restic-нога (systemd-таймеры
на .92, отдельный контур), исторические части на Диске (не удаляются, не
перезаливаются), расписание backup:run (его ремонт — H6124).

## Синтетические проверки (все зелёные, см. PR)

`tests/Feature/Backup/OffsiteArchiveEncryptionGateTest.php` (8 тестов, 21
assertion): отказ без пароля / с пустым паролем / отказ докатки / прохождение
с паролем / preflight 3 исхода / round-trip AES-256 (нечитаем без пароля,
байт-в-байт с паролем, hash match).

## Установка секрета в прод — ОТДЕЛЬНАЯ gated-op (по слову MG)

1. Сгенерировать: `openssl rand -base64 32`; положить в менеджер паролей MG.
2. `BACKUP_ARCHIVE_PASSWORD=<значение>` в `/var/www/html/.env` (.92, root).
   Шаблон с инструкцией: `ops/backup/archive-encryption.env.example`
   (реальный `ops/backup/archive-encryption.env` — gitignored).
3. `cd /var/www/html && php artisan config:cache`.
4. Факт-проверка (без чтения значения):
   `grep -c "^BACKUP_ARCHIVE_PASSWORD=." .env` → `1`.
5. До установки секрета forward-деплой прода стоит на
   `deploy:config-preflight` (hard-fail) — это задуманный гейт,
   заставляющий поставить секрет; rollback-деплой не блокируется.

## Поведение после установки

- Следующий `backup:run` создаст зашифрованный (AES-256) архив; части на
  Диске поедут только от него.
- Старые открытые группы на Диске доживают до retention-чистки
  (`keep_parts_days`, по умолчанию 16) и удаляются ЦЕЛИКОМ штатно.
  **Не удалять и не перезаливать руками** (контракт H6306).
- Восстановление из зашифрованной группы: скачать части, склеить
  `cat *.part-*-of-*.zip > backup.zip`, распаковать с паролем
  (`unzip -P <пароль>` или 7-Zip/Zipeg; AES-256 поддерживается
  современными распаковщиками).

## Откат (rollback)

- Код: revert коммита(ов) PR — поведение возвращается к «льёт без вопросов»
  (этот runbook существует, чтобы этого не делать молча).
- Конфиг (мягче): убрать `BACKUP_ARCHIVE_PASSWORD` из .env + `config:cache` —
  off-site выгрузка встанет с громким `Log::error` (безопасный отказ, не
  потеря), local-копия и restic продолжат. Это штатный способ «выключить
  off-site ногу, не выключая бэкапы».

## Риски / границы

- Если пароль потерян — off-site история после установки НЕ читается:
  пароль хранится в менеджере паролей MG вместе с restic-паролем.
- Пустая строка в env (= шаблон .env.example) ≠ «выключено»: гейт считает
  её отсутствием и запрещает выгрузку. Выключение off-site — только
  `BACKUP_SPLIT_DISK`... точнее `backup.backup.split_upload.disk = local`.
- Guards (backup-fresh по полным группам) честно краснеют, пока пароля
  нет — это сигнал, а не поломка мониторинга.

_Gasūns · H6306_
