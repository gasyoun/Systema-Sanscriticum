# -*- coding: utf-8 -*-
"""H5135 adjudication sheet generator - bots map verdicts. Regen: python scripts/h5135_sheet_review.py <out.html>
Requires csl-pyutil >= 0.23 (render_review_sheet with screening/identity_gate)."""
import json
from csl_pyutil import render_review_sheet

EVID = "Проба 18-09-2026: {src}. Позиция агента: {pos}"

def ev(src, pos):
    return '<div class="ssb-evidence-panel"><h4>Доказательство (live-проба 18-09-2026)</h4>' + src + '<br><b>Позиция агента:</b> ' + pos + '<br><b>Досье:</b> <code>docs/BOTS_MAP_RESEARCH_H5135_18.09.26.md</code> (§2 карта, §3 канарки) · <a href="https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/BOTS_MAP_RESEARCH_H5135_18.09.26.md">github</a></div>'

_raw_items = [
 dict(id="V1-samskrtamru-keep", filt="keep", title="V1 · @samskrtamru_bot — оставить как есть (KEEP)",
  badges=["студкабинет", "long-poll RUNNING"],
  question="<p><b>Что это?</b> Студенческий бот кабинета: привязка TG, ИИ-куратор, /кабинет, /вход, уведомления. Аварийно на long-poll с 06-09 (вебхук-труба мертва).</p>"
   "<p><b>Что изменится при «согласен»?</b> Ничего в проде; остаётся residual «вернуть вебхук, когда труба оживёт».</p>"
   + ev("t.me 200 «ORS»; <code>php artisan telegram:webhooks</code>: «Кабинет — не зарегистрирован»; supervisorctl: telegram-student-poll RUNNING (uptime 0:53)",
        "KEEP — ядро канала; бенчмарк: наш ИИ-куратор впереди рынка, конкуренты учебный цикл из ботов убрали."),
  note_placeholder="Например: «вернуть вебхук до конца октября»"),
 dict(id="V2-samskrte-dedupe", filt="dedupe", title="V2 · @samskrte_bot — KEEP и убрать дубль из landing_bots (row 7)",
  badges=["лид-магнит", "дубль найден пробой"],
  question="<p><b>Что это?</b> Бот лид-магнита/марафона. Проба нашла его вторую копию: <code>landing_bots</code> строка 7 (active), зарегистрированную на <b>глобальный</b> magnet-вебхук вместо своего <code>/{webhookKey}</code>.</p>"
   "<p><b>Что изменится при «согласен»?</b> Одна SQL-правка на .92 (~15 мин): строка 7 деактивируется/удаляется или переносится на свой webhookKey. Риск-класс инцидента 22.07 (потерянный edge) снижается.</p>"
   + ev("mysql .92: row 7 = samskrte_bot, active=1; telegram:webhooks: «Лендинг-бот @samskrte_bot … Совпадает: НЕТ»; инвентарь 30-07 дубля не знал",
        "KEEP бота + почистить дубль; в инвентарь внести поправку."),
  note_placeholder="Например: «row 7 удалить, не деактивировать»"),
 dict(id="V3-zapisi-keep", filt="keep", title="V3 · @zapisi_ORSbot — оставить как есть (KEEP)",
  badges=["записи", "welcome-карточки"],
  question="<p><b>Что это?</b> Чат бронирования: напоминания, welcome-карточки (H4314–H4318), roster peer, forward в n8n. Вебхук совпадает, флаг ON.</p>"
   "<p><b>Что изменится при «согласен»?</b> Ничего.</p>"
   + ev("t.me 200 «Почтальон ревнителей санскрита»; telegram:webhooks: «Записи — совпадает да»; n8n h4314welcome0000000 жив на .91",
        "KEEP — мультифункционален, свежеукреплён."),
  note_placeholder=""),
 dict(id="V4-ops-split-rename", filt="split", title="V4 · @testpodpiska12_bot — завести именованный ops-бот (SPLIT + RENAME)",
  badges=["«замени на реальный» в проде", "рельс: bots get names"],
  question="<p><b>Что это?</b> «Основной служебный бот» LMS: алерты probe, чаты кураторов/маркетологов/онбординга — и фолбэк-токен студенческого пути. В проде .env жив комментарий «замени на реальный юзернейм твоего бота»; t.me display name — «testpodpiska».</p>"
   "<p><b>Что изменится при «согласен»?</b> Создаётся именованный ops-бот (напр. @samskrte_ops_bot): ротация TELEGRAM_BOT_TOKEN/USERNAME, перерегистрация вебхука, кураторам нажать Start. ~1 ч ops, кода нет. Студент-фолбэк решается отдельно (пустой student-token больше не молчит на тест-бот).</p>"
   "<p><b>Если «отклонить»?</b> Тест-имя остаётся в проде; алерты и студ-фолбэк продолжают смешиваться.</p>"
   + ev(".env grep: TELEGRAM_BOT_USERNAME=testpodpiska12_bot + комментарий live; t.me 200 «testpodpiska»; webhooks-таблица",
        "SPLIT: ops-алерты — именованному боту; студенческий фолбэк развязать (rail «bots get names», рулинг МГ 17-09)."),
  note_placeholder="Например: имя нового ops-бота"),
 dict(id="V5-userbot-keep", filt="keep", title="V5 · @rusamskrtam (userbot) — оставить как есть (KEEP)",
  badges=["MTProto", "одна сессия"],
  question="<p><b>Что это?</b> Userbot-аккаунт: support-sync «Отдела заботы», harvest, roster + UX «Написать в Telegram». Одна MadelineProto-сессия, cron schedule:run.</p>"
   "<p><b>Что изменится при «согласен»?</b> Ничего; Business-бот (V8) со временем возьмёт «ответ от имени аккаунта», это отдельная полоса.</p>"
   + ev(".env: TELEGRAM_SUPPORT_USERNAME=rusamskrtam, ENABLED=true; t.me 200 «Куратор курсов ОРС»",
        "KEEP."),
  note_placeholder=""),
 dict(id="V6-grokusaurus-keep", filt="keep", title="V6 · @grokusaurus_bot — оставить как есть (KEEP)",
  badges=["не LMS", "ПК Марциса"],
  question="<p><b>Что это?</b> Grok «Отдела заботы», long-poll на ПК МГ, токен вне прода. Кураторы зовут локальный Grok, когда сайт/кабинет/ДЗ лежат.</p>"
   "<p><b>Что изменится при «согласен»?</b> Ничего.</p>"
   + ev("t.me 200 (bare title); токен по документации в C:\\Users\\user\\.grok\\channels\\telegram\\.env",
        "KEEP — отдельная полоса по рулингу «Отдел заботы» 09-09."),
  note_placeholder=""),
 dict(id="V7-landing-audit-kill", filt="kill", title="V7 · landing_bots legacy — ревизия и выключение мёртвых строк (AUDIT + KILL)",
  badges=["токен row 1 мёртв (404)", "5 строк без username"],
  question="<p><b>Что это?</b> 7 строк landing_bots: row 1 @webinar_17june_bot — <b>active=1, но Telegram отвечает 404 на getWebhookInfo</b> (токен мёртв, вебинар 17.06 давно прошёл); rows 2–6 — без username, непрозрачный legacy; row 7 — дубль @samskrte_bot (см. V2).</p>"
   "<p><b>Что изменится при «согласен»?</b> SQL-ревизия на .92 (~30 мин): deactivate/удалить мёртвые строки; правило вперёд — новый лендинг-бот всегда с именем (bots get names). Обратимо (backup строки).</p>"
   "<p><b>Если «отклонить»?</b> Инвентарь продолжает врать: «active» строка с мёртвым токеном остаётся миной при переезде входного узла.</p>"
   + ev("telegram:webhooks: «Лендинг-бот @webinar_17june_bot — ошибка запроса … error_code 404 Not Found»; mysql: rows 2–6 no-username active=1; t.me row 1 жив",
        "AUDIT+KILL: строка карты «active» опровергнута пробой (канарка №1 досье)."),
  note_placeholder=""),
 dict(id="V8-business-enable-or-park", filt="new", title="V8 · Telegram Business lane — включить по ранбуку (NEW) или осознанно отложить (park)",
  badges=["документирован", "в проде 0 vars"],
  question="<p><b>Что это?</b> Бот, отвечающий студенту <b>от имени аккаунта</b> школы (H5065): код в репо, ранбук готов — но в проде .env нет ни одной TELEGRAM_BUSINESS_* переменной.</p>"
   "<p><b>Если «согласен» (enable)?</b> Исполняется RUNBOOK_TELEGRAM_BUSINESS_ENABLE_2026-09-17 (~30 мин): env-полоса, вебхук, проверка curator-аналитики. Ответы бизнес-бота перестают смешиваться с личкой userbot.</p>"
   "<p><b>Если «отклонить» (park)?</b> Ничего не включается; в досье ставится пометка «осознанный park» вместо тихого недоделанного состояния.</p>"
   + ev(".env grep 18-09: 0 совпадений TELEGRAM_BUSINESS_*; инвентарь §2.5 и RUNBOOK от 17-09 описывают lane как готовую",
        "Решение за МГ; оба исхода честно фиксируются (канарка №3)."),
  note_placeholder="Например: причина park или дата включения"),
 dict(id="V9-n8n-prune", filt="kill", title="V9 · n8n (.91) — деактивировать черновые webinar-варианты (PRUNE)",
  badges=["87 воркфлоу", "6 вариантов webinar"],
  question="<p><b>Что это?</b> На .91 живёт 87 воркфлоу; webinar-семейство размножилось: Registration ×3, Warming ×2 (+ Warming Sequence) + основной — skeleton/fixed/final рядом с боевыми. Боевые (welcome h4314, «Почтальон — аварии», постинг H3746/H3812) не трогаем.</p>"
   "<p><b>Что изменится при «согласен»?</b> Один проход по .91 (~30 мин): скелетоны/черновики деактивируются (не удаляются — обратимо). Список деактивируемых приложу перед проходом.</p>"
   + ev("n8n list:workflow 18-09: 87 строк; имена вариантов: «Webinar Bot — Registration» ×3, «— Warming» ×2, «— Warming Sequence», «My workflow N» ×7",
        "PRUNE черновиков; полная ревизия всех 87 — отдельная работа, не в этом вердикте."),
  note_placeholder="Например: «сначала список на согласование»"),
 dict(id="V10-gaps-park", filt="park", title="V10 · Не строить бот-тренажёр/NPS/СR — тренажёр остаётся в кабинете (PARK)",
  badges=["бенчмарк: рынок ушёл из TG", "мы впереди"],
  question="<p><b>Что это?</b> Гипотеза 3 хендоффа: упустили ли мы бот-функции конкурентов (тренажёр, spaced repetition, NPS)? Бенчмарк 10 платформ: <b>ни одна</b> не ведёт учебный цикл в официальном TG-боте; Lingualeo ушёл в приложение; Anki-мосты — 100% community.</p>"
   "<p><b>Что изменится при «согласен»?</b> Продуктовая линия «бот-тренажёр» не открывается; тренажёр/SR развиваются в кабинете/приложении. Строки gaps в досье получают пометку «осознанный park».</p>"
   "<p><b>Если «отклонить»?</b> МГ задаёт направление (какую функцию и в каком боте строить) — вердикт переписывается по рулингу.</p>"
   + ev("Бенчмарк-таблица досье §4: 10 платформ, каждая строка с пруф-ссылкой; NO-DATA (Учи.ру) помечен явно",
        "PARK: строить против рынка не стоит; ИИ-куратор уже впереди официальных ботов всех 10."),
  note_placeholder="Например: какую функцию всё же строить и где"),
]
items = [dict(it, panels=it.get("panels", [])) for it in _raw_items]

# V13 identity gate: every matched username's label must appear verbatim in the question.
_IDENTITY = {
    "@samskrtamru_bot": "студенческий бот кабинета (LMS .92)",
    "@samskrte_bot": "бот лид-магнита/марафона (MarketingSetting + landing_bots row 7)",
    "@zapisi_ORSbot": "бот записи занятий (MarketingSetting.zapisi_*)",
    "@testpodpiska12_bot": "служебный бот LMS (TELEGRAM_BOT_*)",
    "@grokusaurus_bot": "Grok «Отдела заботы», ПК Марциса, не LMS",
    "@webinar_17june_bot": "лендинг-бот вебинара 17.06 (landing_bots row 1, токен мёртв)",
    "@samskrte_ops_bot": "предлагаемый новый именованный ops-бот (ещё не существует)",
    "@rusamskrtam": "userbot-аккаунт MTProto (support/harvest + живой саппорт)",
    "@samskrte": "публичный канал «ОПОВЕЩЕНИЯ САНСКРИТЯН», не бот",
}
import re as _re
for _it in items:
    _q = _it["question"]
    _found = [u for u in _IDENTITY if _re.search(_re.escape(u) + r"(?![A-Za-z0-9_])", _q)]
    _missing = [u for u in _found if _IDENTITY[u] not in _q]
    if _missing:
        _line = "<p><small><b>Лица:</b> " + "; ".join(
            "<b>%s</b> — %s" % (u, _IDENTITY[u]) for u in _missing) + ".</small></p>"
        _it["question"] = _q + _line

config = dict(
    sheet_id="systema-bots-map_h5135_18-09-26",
    title="Бот-архитектура: вердикты KEEP/MERGE/SPLIT/NEW/KILL (H5135)",
    subtitle="Карта estate + бенчмарк 10 edu-платформ — адъюдикация рекомендаций исследования. Досье: docs/BOTS_MAP_RESEARCH_H5135_18.09.26.md (Systema-Sanscriticum).",
    footer="<b>Согласиться</b> = принять рекомендованный вердикт как напечатан (что изменится — на каждой карте) · <b>Отклонить</b> = вердикт не принимается, напишите в заметке как правильно · <b>Отложить</b> = решить позже. Исследование прод не меняет: любое действие исполняется только после вашего вердикта.",
    approve_label="Согласиться с вердиктом",
    reject_label="Отклонить вердикт",
    filters=[("keep", "KEEP"), ("dedupe", "KEEP+dedupe"), ("split", "SPLIT/RENAME"), ("kill", "KILL/PRUNE"), ("new", "NEW"), ("park", "PARK")],
    generated="18-09-2026",
    show_ids=True,
    note_min_height_px=88,
    save_as="Systema-Sanscriticum/review/systema-bots-map_h5135_18-09-26_decisions.json",
    identity_gate={"patterns": ["@[A-Za-z0-9_]+"], "labels": {"@samskrtamru_bot": "студенческий бот кабинета (LMS .92)", "@samskrte_bot": "бот лид-магнита/марафона (MarketingSetting + landing_bots row 7)", "@zapisi_ORSbot": "бот записи занятий (MarketingSetting.zapisi_*)", "@testpodpiska12_bot": "служебный бот LMS (TELEGRAM_BOT_*)", "@grokusaurus_bot": "Grok «Отдела заботы», ПК Марциса, не LMS", "@webinar_17june_bot": "лендинг-бот вебинара 17.06 (landing_bots row 1, токен мёртв)", "@samskrte_ops_bot": "предлагаемый новый именованный ops-бот (ещё не существует)", "@rusamskrtam": "userbot-аккаунт MTProto (support/harvest + живой саппорт)", "@samskrte": "публичный канал «ОПОВЕЩЕНИЯ САНСКРИТЯН», не бот"}},
)

html = render_review_sheet(
    items=items,
    config=config,
    extras=True,
    screening=dict(deterministic=16, lookup=10, agent=0, human=len(items),
                   evidence_path="docs/BOTS_MAP_RESEARCH_H5135_18.09.26.md",
                   rules=["live-probe-confirmation", "benchmark-official-link"]),
)

stamp = "<!-- ssb-evidence: " + json.dumps({
    it["id"]: dict(verifier="GLM 5.3 (zai-coding-plan/glm-5.3-flash)", method="battery_run",
                   sources=["ssh root@193.232.229.92 (env names/usernames/landing_bots/supervisor/telegram:webhooks)",
                            "ssh root@193.232.229.91 n8n list:workflow",
                            "https://t.me/<username> публичные страницы",
                            "досье docs/BOTS_MAP_RESEARCH_H5135_18.09.26.md §2-§3"],
                   verified_date="18-09-2026") for it in items}, ensure_ascii=False) + " -->\n</body>"
html = html.replace("</body>", stamp, 1)

import sys
out = sys.argv[1] if len(sys.argv) > 1 else r"review/systema-bots-map_h5135_18-09-26_review.html"
import os; os.makedirs(os.path.dirname(os.path.abspath(out)), exist_ok=True)
open(out, "w", encoding="utf-8").write(html)
print("WROTE", out, len(html), "bytes,", len(items), "cards")
