# Jev as Telegram sufler — shadow benchmark

_Generated: 2026-09-23 · model `jev-1.13.0` · corpus `tests/fixtures/Support/classifier_corpus_2026_08.json` (50 cases)_

**SHADOW ONLY** — no prod wiring, no live generation. Deterministic mechanics live in `tools/bench_jev_sufler.py`; the client is the H5275-shipped `Uprava/tools/jev_probe.py`.

## Floor (incumbent, deterministic)

- live regex floor on this corpus: **0.98** (49/50) — `tools/h5274_regex_floor.php`, PHP 8.5.9
- ClassifierPrecisionTest assertion threshold: **0.93** (a floor, not the live number)

## Arm A — suggester category space (A–F + none)

- Jev accuracy **0.86** (43/50)
- vs live floor 0.98: **DOES NOT BEAT**
- vs 93% assertion: **MISSES**
- latency: {'n': 50, 'mean_ms': 6034.5, 'median_ms': 5451.0, 'p95_ms': 11818.3, 'max_ms': 16411.9}
- cost: $0.000913 total · $1.825e-05/call · tokens {'in': 21732, 'out': 3300}

| class | n_gold | n_pred | tp | precision | recall | f1 |
|---|---|---|---|---|---|---|
| A | 3 | 4 | 3 | 0.75 | 1.0 | 0.8571 |
| B | 4 | 4 | 4 | 1.0 | 1.0 | 1.0 |
| C | 4 | 4 | 4 | 1.0 | 1.0 | 1.0 |
| D | 8 | 12 | 7 | 0.5833 | 0.875 | 0.7 |
| E | 5 | 6 | 5 | 0.8333 | 1.0 | 0.9091 |
| F | 5 | 5 | 5 | 1.0 | 1.0 | 1.0 |
| none | 21 | 15 | 15 | 1.0 | 0.7143 | 0.8333 |

### Arm A errors (7)

| # | message | gold | Jev |
|---|---|---|---|
| 12 | Доброе утро, оплатила первый блок, только не поняла где теперь смотреть, придёт  | D | E |
| 30 | Жаль, тогда прошу сделать возврат | None | D |
| 31 | Возврат | None | D |
| 32 | Здравствуйте! Я бы хотела оформить возврат средств за недостающую часть курса | None | D |
| 33 | попрошу вас сделать возврат оставшихся средств | None | D |
| 42 | Заходим | None | A |
| 46 | Добрый день! Хочу выразить огромную благодарность за организацию процесса и выбо | None | D |

## Arm B — objection space (В1–В11 + OTHER)

> reference = deterministic ORS-FAQ sufler (В1/В2/В3/В6/В9 + OTHER); no independent В-gold exists on this corpus → agreement, not accuracy

- agreement with the ORS-FAQ sufler: **0.54** (27/50)
- latency: {'n': 50, 'mean_ms': 5660.5, 'median_ms': 5240.7, 'p95_ms': 10501.5, 'max_ms': 13817.1}
- cost: $0.00111 total · $2.22e-05/call · tokens {'in': 26432, 'out': 5716}

| class | ref n | Jev n | agree |
|---|---|---|---|
| В1 | 0 | 1 | 0 |
| В2 | 4 | 0 | 0 |
| В3 | 0 | 0 | 0 |
| В4 | 0 | 0 | 0 |
| В5 | 0 | 0 | 0 |
| В6 | 1 | 3 | 0 |
| В7 | 0 | 3 | 0 |
| В8 | 0 | 9 | 0 |
| В9 | 5 | 0 | 0 |
| В10 | 0 | 0 | 0 |
| В11 | 0 | 0 | 0 |
| OTHER | 40 | 34 | 27 |

### Arm B disagreements (23)

| # | message | sufler ref | Jev |
|---|---|---|---|
| 6 | сколько будет стоить курс по календарям? | В2 | OTHER |
| 7 | И вопрос если за блок платить, есть ли функция рассрочки или по частям оплата? | OTHER | В8 |
| 8 | А это свободный доступ или есть цена, не очень поняла? | В2 | В8 |
| 9 | сколько стоит курс и где ссылка | В2 | В8 |
| 10 | ссылку на оплату не нашла | OTHER | В8 |
| 11 | Подскажите где можно оплатить курс по грамматике? | В2 | В8 |
| 12 | Доброе утро, оплатила первый блок, только не поняла где теперь смотреть, придёт  | OTHER | В8 |
| 13 | Хотела уточнить про тарифы на следующий поток | В6 | OTHER |
| 14 | По поводу домашней работы не ответили) куда её прикреплять? | OTHER | В8 |
| 16 | куда мы должны прикреплять домашнюю работу по занятию 3? | OTHER | В8 |
| 21 | Я пропустила занятие, посмотрю видеоозапись вечером | OTHER | В1 |
| 23 | как войти в зум на занятие? | OTHER | В7 |
| 24 | Подключиться к занятию не получается, просит код | OTHER | В7 |
| 25 | пришлите линк на встречу, пожалуйста | OTHER | В7 |
| 26 | во сколько сегодня занятие? | OTHER | В6 |
| 27 | расписание следующей недели уже есть? | OTHER | В6 |
| 29 | в какой день начинаются занятия у вечерней группы? | OTHER | В6 |
| 30 | Жаль, тогда прошу сделать возврат | В9 | OTHER |
| 31 | Возврат | В9 | OTHER |
| 32 | Здравствуйте! Я бы хотела оформить возврат средств за недостающую часть курса | В9 | OTHER |
| 33 | попрошу вас сделать возврат оставшихся средств | В9 | OTHER |
| 40 | Да, через [telegram] | OTHER | В8 |
| 46 | Добрый день! Хочу выразить огромную благодарность за организацию процесса и выбо | В9 | OTHER |

## Interpretation

### 1. Half the Arm-A errors are a label-space artefact, not a model error

5 of the 7 Arm-A errors (cases 30–33, plus 12) are messages the model sent to
`D` (payment) or `E` (access) while gold is `none`. Cases 30–33 are **refund
requests** («сделать возврат», «возврат средств»). In the incumbent label space
`none` does **not** mean "no topic" — it means **"no canned answer, route to a
human"**. The regex floor is a *routing* classifier; Jev answers the *semantic*
question. Refunds genuinely are payment-adjacent, so the model's answer is
defensible and the label is still right by its own definition.

This does not rescue the verdict: even if all 4 refund→`D` errors are forgiven,
accuracy is **0.94 (47/50) — still below the live floor 0.98**. The conclusion is
robust under the most generous reading.

### 2. Latency is the harder blocker for a real-time sufler

The sufler is a live copilot: a hint has to arrive while the curator is still
typing. Median latency is **~5.2–5.5 s** (p95 **10.5–11.8 s**, max 16.4 s) per
single message, against a deterministic floor that is **sub-millisecond**. Cost
is irrelevant (~$0.000018–0.000022/call); latency is not. Even at equal accuracy,
a 5-second shadow cannot serve the real-time hint path.

### 3. RU quality — the model never predicts the price-objection class

Arm B is a **systematic** finding, not noise: over 50 RU messages Jev emitted
**zero** `В2` (price) predictions, while the marker floor caught 4. Plain RU price
phrasings went to `OTHER` or `В8`:

| message | sufler | Jev |
|---|---|---|
| сколько будет стоить курс по календарям? | В2 | OTHER |
| сколько стоит курс и где ссылка | В2 | В8 |
| Подскажите где можно оплатить курс по грамматике? | В2 | В8 |
| А это свободный доступ или есть цена, не очень поняла? | В2 | В8 |

The model reads «оплатить / оплата» as *payment mechanics* (`В8`) and misses the
price-objection idiom «сколько стоит». This is the English-primary behaviour the
mission asked to measure explicitly: the RU objection vocabulary is not reliably
in the model.

### 4. Label-space fork (recorded, not silently resolved)

The mission names the corpus as the `ClassifierPrecisionTest` fixture **and** the
label space as «B1–B11 intent classes». Those disagree: the fixture's gold is the
suggester space **A–F + none** (payment/recording/schedule/zoom/access/materials),
while «В1–В11» are the ORS-FAQ **objection** codes. Both arms were run so the
fork does not block the verdict; a human owns the reading.

### 5. Blocked sub-arm — the package eval corpus is not in the clone

The frozen package corpora (`corpora/eval/2026-07-05-masked.jsonl`,
`corpora/train/2026-08-22-masked.jsonl`) named in the architecture doc are **not
present** in the local `message-intent-classifier` clone (`corpora/` holds only
`README.md`); the only on-disk copy found sits under the 152-FZ-fenced private
stenogrammy tree and was **not opened**. The benchmark therefore runs on the
public 50-case fixture only. A larger-corpus re-run needs the masked jsonl
committed to the package per its own freeze protocol.

### 6. What would justify a re-test (GO-conditions)

1. an RU-idiom-anchored prompt or few-shot examples for `В2`/price vocabulary;
2. a lane where 5-second latency is irrelevant (offline batch triage, not a live hint);
3. a re-baselined label space where `none` means "no topic" rather than "route to human", so semantic and routing answers are not scored against each other.

Absent all three, the deterministic YAML+regex floor stands.

## Verdict

NO-GO: Jev 0.86 < live regex floor 0.98 on the frozen corpus — the deterministic YAML+regex floor stands; do not wire Jev into the sufler path.

_Гасунс_
