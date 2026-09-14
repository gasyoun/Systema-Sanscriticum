# Runbook: вооружить канва-факстуру пробы (~10 мин, MG на проде .92)

_Created: 13-09-2026 · Last updated: 13-09-2026_

Исполнитель: **человек (MG)** — сам флип prod `.env` и сидинг курса не агенту
(прецедент H3797: sandbox-урок заводит человек). Связан с GTD 0b2 и
[H4648](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4648-OxAlpha_Systema-Sanscriticum_route-500-watch-probe-arm-loud-skip_13.09.26.md).

Зачем: пока `CABINET_PROBE_KANVA_COURSE_ID` пуст, `cabinet:probe` **не** исполняет
канва-ветку student.dashboard (курсор H4435) — фатал класса инцидента 10–13.09
(`/dvaram` 500, unqualified inline FQCN) пробой не ловится. См.
[постмортем](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INCIDENT_STUDENT_DASHBOARD_FQCN_500_10-13-09-2026.md).
Каждый unarmed-прогон с H4648 громко предупреждает и ставит флаг
`coverage_partial` в `cabinet_probe_runs` — этот рунбук его гасит.

## Шаги

1. Зайти на прод: `ssh root@193.232.229.92`, далее `cd /var/www/html`.

2. Завести синтетический kanva-курс + группу (один раз, идемпотентно — повтор
   создаст второй курс):

   ```sh
   php artisan tinker --execute='
   $c = App\Models\Course::create([
       "title" => "Кочергина (канва-факстура пробы H4648)",
       "is_active" => true,
   ]);
   $g = App\Models\Group::create(["name" => "Канва-факстура пробы"]);
   $c->groups()->attach($g->id);
   echo "CABINET_PROBE_KANVA_COURSE_ID=".$c->id.PHP_EOL;
   '
   ```

   Заголовок обязан содержать «Кочергина» (матчит семейство канвы
   `TextbookScale::courseFamilyPublic`). Запомнить напечатанный id курса.

3. Вписать id в `.env` (заменить `<id>` на число из шага 2):

   ```sh
   grep -q '^CABINET_PROBE_KANVA_COURSE_ID=' .env \
     && sed -i "s/^CABINET_PROBE_KANVA_COURSE_ID=.*/CABINET_PROBE_KANVA_COURSE_ID=<id>/" .env \
     || echo "CABINET_PROBE_KANVA_COURSE_ID=<id>" >> .env
   php artisan config:cache
   ```

4. Контрольный прогон (без TG — сторож `*/15` шлёт сам, если надо):

   ```sh
   php artisan cabinet:probe --no-alert
   ```

   Ожидаемо видно: `Канва-факстура OK: курс #<id> (семейство «kochergina»), группа
   #N, студент #M — ветка H4435 исполняется.` и **нет** блока
   `⚠️ CABINET_PROBE_KANVA_COURSE_ID ПУСТ`.

5. Проверить, что флаг покрытия снялся:

   ```sh
   php artisan tinker --execute='
   echo json_encode(App\Models\CabinetProbeRun::query()->latest("id")->first(["ran_at","healthy","coverage_partial"])->toArray(), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
   '
   ```

   Ожидаемо `"coverage_partial": false`.

## Если что-то не так

- `курс #N … не найден` → опечатка в id шага 3; повторить шаги 2–4.
- `заголовок курса … не матчит семейство канвы` → в заголовке нет «Кочергина»;
  поправить заголовок курса и повторить шаг 4.
- `у курса #N нет группы` → забыли `attach` шага 2; завести группу и привязать.
- Хочется откатить: убрать строку `CABINET_PROBE_KANVA_COURSE_ID` из `.env`,
  `php artisan config:cache`, `php artisan cabinet:probe --no-alert` — прогон
  снова честно покажет warn-блок и `coverage_partial: true`. Курс в БД можно
  оставить (не мешает) или удалить.

_Гасунс_
