# Плашки занятий: номер в кружке и водяной номер Кочергиной (Opus 5 `claude-opus-5`, 22-09-2026)

- На реальных макетах ОРС (Патанджали, Продленка, хинди 1/2/5, «Введение») номер — это кружок с вырезанными цифрами: так «(14)» рисует OpenType-функция Fedra Sans Pro. Рендер (GD) OpenType не применяет и выдавал голое «(14)». Теперь скрипт узнаёт такой слой и пишет в шаблон `badge`, а [`LessonBannerRenderer`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Banners/LessonBannerRenderer.php) рисует круг сам — со сглаживанием, цифры вырезаны до цвета плашки. Кегль цифр подобран по оригиналу «Патанджали».
- «Учебник Кочергиной, гр. 53»: огромная «40» — тоже номер занятия, и лежит она между плашками и фото, с наложением «Мягкий свет» 59 %. Шаблон теперь бывает двухслойным: фон, водяной номер (`layer: under`, `blend: soft_light`, `opacity`), прозрачный верхний слой `overlay.png` (новые колонки `lesson_banner_templates.overlay_*`), дата и номер. Скрипт делит PSD сам: `--watermark-layer "40"`.
- Скрипт находит шрифты, установленные в Windows «только для меня», и кладёт их в `fonts/` под чистыми латинскими именами (включает #2765).
- Все 7 макетов из `F:\Плашки\psd` собраны в комплекты и сверены с оригиналами. Флаг `LESSON_BANNERS` по-прежнему выключен. Тесты: [`LessonBannerLayersTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Banners/LessonBannerLayersTest.php) (5) + прежние 18 зелёные.

_Гасунс_
