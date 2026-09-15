# H4848: окно root-овых compiled Blade закрыто по построению — прогрев кэшей идёт от www-data, деплой падает на гарде (OxAlpha `z-ai/glm-5.3-flash`, 15-09-2026)

Рекуррентный класс (17-08, 20-08, 09-09 и снова **14-09-2026**): `deploy.sh` грел кэши от root, `php artisan optimize` компилировал Blade как `root:755`, а php-fpm бежит от www-data и в `BladeCompiler.php:215` делает `touch($compiledPath, $lastModified + 1)`. `touch()`/`utime()` разрешён владельцу ИЛИ имеющему право записи — root-овый 755 не даёт ни того, ни другого, поэтому запрос, которому нужна перекомпиляция, получал `Utime failed: Operation not permitted` и Filament `/admin` отдавал **HTTP 500** до ручного chown. 14-09 авто-деплой `e9e5e594 → fee9cfdb` шёл 18:00:01–18:02:47 UTC, и всё это время `/admin` был сломан; failsafe H3194 (chown после каждого root-artisan) окно только **сужал**, но не закрывал — файл «сломан» в тот же миг, когда его записал root, а запрос мог быть уже в полёте.

- **Прогрев кэшей от FPM-пользователя** (`run_as_app_user`, `runuser -u www-data`): `artisan optimize`, `filament:optimize` и `cabinet:probe` больше не запускаются от root — root вообще не создаёт вьюху, окна нет по построению, а не «уже». Приоритетное искусство внутри репо: [`ops/migrate/debian13/04-app-deploy.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/migrate/debian13/04-app-deploy.sh) уже делает `sudo -u $DEPLOY_USER php artisan view:cache`.
- **Проба теперь честная.** `cabinet:probe` в деплое идёт от www-data — тем же пользователем, что обслуживает запросы. Это не косметика: 14-09 проба **от root была зелёной, пока `/admin` отдавал 500**; root-овый `touch()` проба от root не видит структурно. От www-data она и компилирует вьюхи правильным владельцем, и видит реальное состояние.
- **Гард, которого требовала миссия:** `assert_no_root_views` — деплой, оставивший root-овый скомпилированный view, это **провальный деплой**, а не предупреждение в логе. Стоит после последнего `chown_compiled_views` (H3194: `fail` — это `exit 1`).
- **Страховка второго уровня:** `umask 002` + setgid (`chmod 2770`) на `storage/framework/views` — даже случайная root-запись остаётся группово-записываемой для www-data (`utime()` разрешён при праве записи, не только владении). Плюс `chown -R --from=root` на `bootstrap/cache`: без этого прогрев от www-data упал бы на старых `config.php`/`routes-v7.php` — `--from=root` меняет только root-овые записи и не трогает группу `webteam`.
- **Непрерывная проверка между выкладками:** новый [`CompiledViewsOwnershipInspector`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ServerGuards/CompiledViewsOwnershipInspector.php) + проверка `views-ownership` в `cabinet:probe` — сторож */15 бежит **от www-data**, значит ответ `is_writable()` там совпадает с ответом php-fpm. Severity **critical** и сообщение намеренно НЕ начинается с `guards/`: иначе `isHostGuardFailure()` отнёс бы находку к host-guard'ам, которые не будят Telegram и не роняют деплой (H3197), а этот дефект обязан и будить, и ронять.
- Тесты: [`CompiledViewsOwnershipInspectorTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Unit/CompiledViewsOwnershipInspectorTest.php) (фикстура с read-only файлом — на реальном каталоге проверялась бы только «зелёная» ветка) + [`DeploySurfaceSecretsTest::test_deploy_sh_compiles_views_as_fpm_user_not_root`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Unit/DeploySurfaceSecretsTest.php) (форма `deploy.sh`: нет голого root-ового `optimize`, гард есть и стоит после последней починки).

## Приёмка на живом проде (канарейка, замер раз в секунду)

Два прогона `deploy.sh` на проде, каждый под канарейкой, которая каждую секунду
писала три факта: число root-овых файлов в `storage/framework/views`, число
скомпилированных вьюх и HTTP-статус `/admin`.

- **Прогон 1 — привёз сам фикс, исполнял ещё старый скрипт** (bash держит прежний
  inode через `git pull`, см. [docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md)):
  окно 05:21:31–05:24:04 UTC, до **716** файлов `root:root`, 131 замер из 756 с
  `root>0`. `/admin` при этом остался 302 — повезло: 500 случается только когда
  www-data нужна *перекомпиляция* вьюхи, а файлы были свежие.
- **Прогон 2 — фикс уже на диске:** 191 замер, **максимум root-овых = 0**,
  замеров с `root>0` = **0**, `/admin` = **302 во всех 191**, при этом кэш реально
  пересобирался (файлов 1 → 717 → 725) — то есть окно было пройдено насквозь, и
  ни один созданный файл не оказался root-овым. Это и есть критерий приёмки
  «ноль root-овых в любой момент, когда запрос может быть обслужен».
- Итог: прод `ba3a80cf` = `origin/main`, вьюхи 725, root-овых 0, каталог
  `drwxrws--- www-data:www-data`, `cabinet:probe` от www-data — «Кабинет жив: все
  проверки OK (4246 ms)», главная 200, `/admin` 302.
