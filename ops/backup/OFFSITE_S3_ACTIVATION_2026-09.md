# Off-site нога №3: Yandex Object Storage (immutable) — подготовка и активация

_Created: 24-08-2026 · Last updated: 24-08-2026_

Решение MG 24-08-2026: вторую off-site ногу (S3) — **одобрить, всё приготовить,
запуск ≈24-09-2026** (месяц после одобрения). Этот документ — состояние
готовности и пошаговый путь активации. Handoff:
[H3413](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3413-Sonnet_Systema-Sanscriticum_s3-offsite-activate_24.08.26.md).

## Что уже отшито (ничего доделывать не нужно)

Всё репо-side легло в [H3175 Wave 1](RUN_LOG_H3175_WAVE1_23-08-2026.md):

| Кусок | Где | Состояние |
|---|---|---|
| restic S3-нога (push обоих репо `systema`+`samudra`) | `systema-restic-run.sh` | готова; читает `/root/.restic-s3.env` когда появится; без него — `status=SKIP reason=no-/root/.restic-s3.env`, ран не валит |
| Верификация иммутабельности + bootstrap | `systema-restic-s3-verify.sh` | готова; `--init` инициализирует репо, первый пуш, критерии 1.1/1.2/1.2b/1.3 |
| Шаблон ключей | [restic-s3.env.example](restic-s3.env.example) | закоммичен; значения не содержит |
| SFTP-нога `.91` (копия №2) | hourly timer | зелёная с 23-08 |

## Почему именно Yandex Object Storage

- Тот же юрлицо/биллинг-контур, что и весь прод (без нового вендора);
- Object Lock (WORM) — третья копия становится иммутабельной к компрометации
  прод-ключа (PutObject-only ключ физически не может удалить);
- S3-API совместимость: при переезде к другому провайдеру меняется только
  endpoint в обёртке.

## День активации (≈24-09-2026 или позже по слову MG)

1. MG: консоль [console.cloud.yandex.ru](https://console.cloud.yandex.ru/)
   (~20 мин): бакет с **Object Lock** (400 дней, governance) → сервисный
   аккаунт → статический ключ **только PutObject, без delete**.
2. Агент/MG: заполнить `/root/.restic-s3.env` из
   [restic-s3.env.example](restic-s3.env.example), `chmod 600`.
3. `bash /usr/local/sbin/systema-restic-s3-verify.sh --init` → ожидаемые
   вердикты: 1.1 PASS, 1.2 IMMUTABLE=yes, списки снапшотов обоих репо.
4. Следующий hourly tick берёт S3-ногу в постоянный цикл; контроль — строки
   `s3=OK` в `/var/log/restic-backup.log`.

Деньги: холодный класс, объём двух репо ~5–6 ГиБ суммарно на старт с
помесячным ростом; точный тариф сверять с калькулятором Yandex Cloud перед
активацией (@money row — сумма в любом случае копеечная против SFTP-ноды).

## До дня активации

Ничего не делается и ничего не меняется: файл `.env` на боксе отсутствует →
нога SKIP, estate остаётся двухкопийным (local + `.91`), WebDAV-нога Яндекса
продолжает докатку частей по 20 МиБ (4899c1bd).

_Dr. Mārcis Gasūns_
