# ops/covers — автогенератор обложек уроков (H4514)

_Created: 10-09-2026_

Рендерит датированные обложки `YYYY-MM-DD.jpg` для каждого будущего урока и
кладёт их в Drive-папку курса, чтобы проверка «Обложка найдена?» в ZOOM 1.4
(n8n на .91) больше не зависела от человека. Существующие файлы никогда не
перезаписываются (skip, если файл с таким именем уже есть в папке).

## Как это работает

1. **Уроки** — mysql `laravel` на .92 через SSH с .91
   (`root@192.168.200.92`, ключ). Пароль БД читается из удалённого
   `/var/www/html/.env` внутри .92 и никуда не копируется.
   Выбираются `schedules` на `--days` (по умолчанию 8) дней вперёд, где
   известен zoom meeting_id (своя колонка или колонка курса).
2. **Папки** — вкладка Settings листа Automation_DB
   (`1Z8CndhrmEbgRBiFZvt2Zwr7JM4zFBLqyc9XOVggFiKI`, первый грид) экспортируется
   как CSV через Drive API; маппинг `meeting_id -> drive_folder_id`.
   Уроки без строки в Settings пропускаются и печатаются как `UNMAPPED`
   (их папку надо добавить в таблицу — спросить MG/куратора).
3. **Рендер** — Pillow, шаблон в палитре курса (бордо/золото/крем/красный):
   название курса + день недели + дата. 1920x1080 JPEG.
4. **Загрузка** — Drive API (oauth-кред n8n «Google Drive account»,
   экспортированный в `/opt/covers/creds/gdrive.json`, 0600).

## Расположение на .91 (193.232.229.91)

| Путь | Что |
|---|---|
| `/opt/covers/generate_covers.py` | генератор (копия `ops/covers/`) |
| `/opt/covers/venv/` | python3 venv с Pillow |
| `/opt/covers/creds/gdrive.json` | oauth-кред Drive (0600, root-only) |
| `/opt/covers/creds/token.json` | кэш access-токена (0600) |
| `/etc/systemd/system/uprava-covers.{service,timer}` | ежедневный таймер 04:00 UTC = 07:00 МСК |

## Команды

```sh
# план без изменений (урок -> папка -> EXISTS/MISSING/UNMAPPED)
/opt/covers/venv/bin/python /opt/covers/generate_covers.py --dry

# рендер + загрузка недостающих
/opt/covers/venv/bin/python /opt/covers/generate_covers.py --run

# образец шаблона без БД и Drive
/opt/covers/venv/bin/python /opt/covers/generate_covers.py \
  --render-sample /tmp/sample.jpg --title "Курс" --date 2026-09-17

# состояние таймера и журнал
systemctl list-timers uprava-covers.timer
journalctl -u uprava-covers.service -n 50
```

## Обновление шаблона или кода

Править `ops/covers/generate_covers.py` в репо, затем на .91:
`scp ops/covers/generate_covers.py root@193.232.229.91:/opt/covers/`.
Если шаблон не принят MG — удалить загруженные `YYYY-MM-DD.jpg`
(Drive API / вручную) и повторить `--run` после правки; существующие обложки
уроков Ивана генератор не трогает.
