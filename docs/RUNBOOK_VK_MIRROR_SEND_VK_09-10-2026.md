# RUNBOOK — VK-зеркало samskrtamru (warm-up ключа, wall.post 214, send-vk)

_Created: 09-10-2026 · Last updated: 09-10-2026_

**Аудитория:** ops/агенты. **Когда:** дайджест/опрос content-saturday не отзеркалился в [vk.com/samskrtamru](https://vk.com/samskrtamru) (owner_id `-88831040`, «Общество ревнителей санскрита»), или сменился VK-ключ. **Важно:** это python-лейн на .92, а НЕ старый n8n-воркфлоу — setup-инструкции n8n к текущему зеркалу не относятся.
**Источники:** [tools/vk_mirror.py](https://github.com/gasyoun/Uprava/blob/main/tools/vk_mirror.py) + [tools/content_saturday_runner.py](https://github.com/gasyoun/Uprava/blob/main/tools/content_saturday_runner.py) в Uprava (лейн деплоится на .92), хендафф [H6153](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6153-GLM_Uprava_vk-mirror-content-lane_05.10.26.md), живые пробы .92 09-10-2026.

## Топология и файлы (всё на .92)

| Путь | Что это |
|---|---|
| `/home/hermes/bin/content_saturday_runner.py` | лейн; подкоманда `send-vk` + publish-хвост зеркалит payload |
| `/home/hermes/.hermes/.env` | ключи: `VK_ACCESS_TOKEN` (сообщество), `VK_GROUP_ID=-88831040`, `VK_USER_TOKEN` (offline user-ключ; если непуст — wins) |
| `vk_mirror_state.json` рядом с payload | приёмные квитанции зеркала — идемпотентность (повтор не дублирует пост) |

Токен-приоритет (рулинг MG 05-10, H6153): непустой `VK_USER_TOKEN` (scope wall+photos+offline) старше — даёт настоящий poll-виджет; ключ сообщества `VK_ACCESS_TOKEN` — фолбэк и постоянный путь. `VK_ADS_TOKEN` НЕ подходит (err 15, проба 05-10).

## Диагностика (рид-онли, с .92)

```bash
ssh -o BatchMode=yes root@193.232.229.92

# 1. Ключ сообщества жив? (жду response.groups[…], НЕ error)
T=$(grep -E '^VK_ACCESS_TOKEN=' /home/hermes/.hermes/.env | cut -d= -f2-)
curl -s -m 10 "https://api.vk.com/method/groups.getById?group_id=88831040&access_token=$T&v=5.199" | head -c 200
# 09-10-2026: «Общество ревнителей санскрита» → ключ жив

# 2. User-токен жив? (err 9 Flood control = кламп/лимит, НЕ «нет токена»)
U=$(grep -E '^VK_USER_TOKEN=' /home/hermes/.hermes/.env | cut -d= -f2-)
curl -s -m 10 "https://api.vk.com/method/users.get?access_token=$U&v=5.199" | head -c 200
# 09-10-2026: переменная не пуста; users.get → error_code 9 Flood control
# (рождение user-токена клампится; vk_mirror ретраит проход сообществом)

# 3. Что зеркало уже посчитало отправленным (идемпотентность):
ls -la /home/hermes/content_queue/vk_mirror_state.json 2>/dev/null || echo "state ещё не создан (проба 09-10: отсутствует)"

# 4. Последние VK-строки брифа лейна:
grep -i vk /home/hermes/brief/*.md 2>/dev/null | tail -5
```

## Таблица ошибок VK API

| Код | Класс | Действие |
|---|---|---|
| `214` wall.post | VK-edge отказывает python-TLS-фингерпринту ДО валидации параметров (curl проходит с тем же телом — пробы 05-10) | Свои скрипты зовут VK только через curl-subprocess (vk_mirror так и делает, urllib — fallback). Также: свежесозданный ключ флапает 214 сутки — warm-up, ждать ≤ 24 ч |
| `15`/`27` | скоуп-дыра ключа (нет прав «Стена»/«Фотографии») или ads-токен | Пересоздать ключ с нужными правами — виза MG (Управление → Работа с API → ключ доступа) |
| `15`/`27`/`200`/`204` на polls.create | community-токен не умеет опросы | НОРМА: text-fallback — опрос уходит текстом, в брифе честная DEGRADED-строка; апгрейд = непустой `VK_USER_TOKEN` |
| `9` Flood | user-токен родился flood-clamped | vk_mirror перезапускает проход на community-ключе; выждать, повторить |

## Warm-up нового ключа

1. MG создаёт ключ (права «Стена» + «Фотографии»; для user-токена — oauth со scope wall+photos+offline).
2. Вставить в `/home/hermes/.hermes/.env` (`VK_ACCESS_TOKEN=` или `VK_USER_TOKEN=`) — правит владелец бокса.
3. Первые ≤ 24 ч `wall.post` может флапать `214` даже через curl — это прогрев ключа, не дефект.
4. Пробный проход: «Ручной send-vk» ниже; смотреть строку результата в брифе.

## Ручной send-vk (восстановление зеркала без повторной TG-отправки)

`*** GATE ***` — пишет на публичную стену сообщества; только по слову MG или по расписанию лейна:

```bash
ssh -o BatchMode=yes root@193.232.229.92
cd /home/hermes/bin
rm -rf __pycache__   # гоча 0OD: stale __pycache__ после scp ломает первый живой прогон
python3 content_saturday_runner.py send-vk \
  --payload /home/hermes/content_queue/payload.json \
  --env-file /home/hermes/.hermes/.env
# идемпотентно: повторный запуск пост НЕ дублирует (state-файл)
```

## Откат

Зеркало read-only для TG-лейна не бывает (либо постит, либо нет): отказ API = DEGRADED-строка в брифе, TG-публикация не страдает. Откат ключа — очистить переменную в `/home/hermes/.hermes/.env`; нога честно пиш DEGRADED до нового ключа.

_Dr. Mārcis Gasūns_
