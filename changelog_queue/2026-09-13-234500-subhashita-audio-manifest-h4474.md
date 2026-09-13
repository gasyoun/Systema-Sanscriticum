_Created: 13-09-2026 · Last updated: 13-09-2026_

# H4474: 111 субхашит-записей с Яндекс.Диска сведены с Бётлингком — манифест + план аудио-слоя SRS (OxAlpha, run by Opus 5 `claude-opus-5[1m]`, 13-09-2026)

Аудио-дельта к текстовому слою subhashita-reader-pack: 126 mp3 из инвентаря [YADISK_INVENTORY_07-09-2026](https://github.com/gasyoun/Uprava/blob/main/reports/YADISK_INVENTORY_07-09-2026.md) §3 оказались **111 уникальными записями** (96 `Su<N>` + 15 Kochergina; две девангари-именованные папки — байт-в-байт зеркала, и именно они дают пратику в имени файла). Аудио в репозиторий не кладётся — садится только метаданные.

- **Манифест** [resources/data/subhashita_audio_manifest.tsv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/subhashita_audio_manifest.tsv): 111 строк, 14 колонок (`audio_id`, путь на Яндекс.Диске, длительность из ffprobe, битрейт, пратика, номер антологии + страница, номер Бётлингка, метод сопоставления). Суммарно **35.1 мин**, все 128 kbps, 15–21 с на субхашиту.
- **Сопоставление: 59 из 111 записей несут номер Indische Sprüche** (22 по полному стиху из `Subhashita-Recordings-Text.docx`, 24 по пратике оглавления антологии «सुभाषित-दैनन्दिनम्» 2014, 11 по деванагари в имени файла, 2 ambiguous). Проверка [scripts/verify_subhashita_audio_manifest.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/verify_subhashita_audio_manifest.py): **pass 54 · variant 5 · fail 0**, exit 0.
- **52 несопоставленных — не дефект:** антология черпает и из Махабхараты, Панчатантры, Хитопадеши, Бхартрихари; живой пробой подтвердил, что गते शोकं न कुर्वीत и विदेशेषु धनं विद्या в корпусе 7537 изречений отсутствуют вовсе. Набор Kochergina (15) не сопоставим по построению — однословная пратика в имени.
- **Права — открытый вопрос, работа не остановлена:** записи лежат в учительском дереве собственного Яндекс.Диска, но исполнитель нигде не задокументирован; текстовый слой (Бётлингк, 2-е изд. 1870–73) — public domain. Ни одного mp3 не опубликовано; человеческое решение нужно только перед этапом 3 (кнопка воспроизведения студенту).
- **План врезки** [docs/PLAN_SUBHASHITA_AUDIO_SRS_LAYER_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SUBHASHITA_AUDIO_SRS_LAYER_2026.md) — три этапа по образцу `kosha_srs_deck_b1_demo` + `ImportKoshaSrsDeckB1Demo`: фид колоды → хостинг 33 МБ аудио → кнопка в карточке.
- **Инфра-факт:** удалённый `yadisk:` (rclone/WebDAV, [Uprava FINDINGS §719](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md)) существовал только на Mac — на Windows-боксе пересоздан из прод-`.env` одним `rclone config create`, без участия человека.

_Гасунс_
