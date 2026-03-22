<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Личный кабинет | Школа Санскрита</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --bg: #fdfcf8; --text-main: #3e3832; --primary: #d9a404; 
            --primary-hover: #b38600; --card-bg: #ffffff; --line: #e6e2d8; 
            --success: #65a30d; --youtube: #ef4444; --rutube: #0057b7; --file-bg: #f3f4f6; 
        }
        body { font-family: 'Manrope', sans-serif; background: #e5e7eb; margin: 0; color: var(--text-main); display: flex; justify-content: center; min-height: 100vh; }
        .app-container { width: 100%; max-width: 480px; background: var(--bg); min-height: 100vh; position: relative; padding: 20px; }
        
        .header { margin-bottom: 24px; border-bottom: 2px solid var(--line); padding-bottom: 12px; }
        .header h1 { font-size: 20px; font-weight: 800; margin: 0; }

        /* --- СТИЛИ ДЛЯ TELEGRAM БЛОКА --- */
        .tg-card { background: var(--card-bg); border: 1px solid #bae6fd; border-radius: 16px; padding: 16px; margin-bottom: 24px; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.05); }
        .tg-title { font-size: 16px; font-weight: 800; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; color: #0369a1; }
        .tg-desc { font-size: 13px; color: #6b635b; margin-bottom: 14px; line-height: 1.4; }
        .btn-tg { background: #e0f2fe; color: #0284c7; width: 100%; text-decoration: none; padding: 12px; border-radius: 12px; font-size: 14px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 6px; transition: 0.2s; box-sizing: border-box; }
        .btn-tg:hover { background: #bae6fd; }
        .tg-success { color: var(--success); font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 6px; }

        /* Список уроков */
        .lessons-container { display: flex; flex-direction: column; gap: 16px; }
        .lesson-card { background: var(--card-bg); border: 1px solid var(--line); border-radius: 16px; padding: 16px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .lesson-date { font-size: 12px; font-weight: 700; color: var(--primary); text-transform: uppercase; margin-bottom: 4px; }
        .lesson-title { font-size: 16px; font-weight: 700; margin-bottom: 8px; line-height: 1.4; }
        .lesson-topic { font-size: 14px; color: #6b635b; margin-bottom: 16px; }

        /* Кнопки */
        .btn-group { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        button { border: none; border-radius: 12px; padding: 10px; font-size: 13px; font-weight: 700; cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-yt { background: #fee2e2; color: var(--youtube); }
        .btn-rt { background: #e0e7ff; color: var(--rutube); }
        .btn-flash { background: #fef3c7; color: var(--primary); grid-column: span 2; }
        
        /* Модалка для видео */
        #video-modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); z-index:100; flex-direction:column; }
        #video-container { flex-grow:1; display:flex; align-items:center; justify-content:center; }
        #video-container iframe { width:100%; aspect-ratio: 16/9; border:none; }
        .close-btn { color: white; padding: 20px; text-align: right; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>

<div class="app-container">
    <div class="header">
        <h1>Моё обучение</h1>
        <p style="font-size: 12px; color: #6b635b; margin-top: 4px; margin-bottom: 0;">Ученик: {{ Auth::user()->name ?? 'Гость' }}</p>
    </div>

    <div class="tg-card">
        <div class="tg-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69.01-.03.01-.14-.07-.19-.08-.05-.19-.02-.27 0-.12.03-1.96 1.25-5.54 3.67-.52.36-.99.54-1.41.53-.46-.01-1.35-.26-2.01-.48-.81-.27-1.46-.42-1.4-.88.03-.22.35-.45.96-.69 3.75-1.64 6.25-2.72 7.5-3.24 3.56-1.49 4.3-1.74 4.78-1.75.11 0 .35.03.48.14.11.08.15.22.14.36z"/></svg>
            Telegram-бот Академии
        </div>
        
        @if(auth()->user() && auth()->user()->telegram_id)
            <div class="tg-success">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                Бот успешно подключен!
            </div>
            <div class="tg-desc" style="margin-bottom: 0; margin-top: 4px;">Теперь важные ссылки и расписание будут приходить вам в мессенджер.</div>
        @else
            <div class="tg-desc">Подключите бота, чтобы не пропустить важную информацию по обучению и доступы к урокам.</div>
            <a href="{{ route('telegram.connect') }}" target="_blank" class="btn-tg">
                Подключить бота
            </a>
        @endif
    </div>
    <div class="lessons-container">
        @forelse($lessons as $lesson)
            <div class="lesson-card">
                <div class="lesson-date">{{ \Carbon\Carbon::parse($lesson->lesson_date)->translatedFormat('d F Y') }}</div>
                <div class="lesson-title">{{ $lesson->title }}</div>
                <div class="lesson-topic">{{ $lesson->topic }}</div>

                <div class="btn-group">
                    @if($lesson->video_url)
                        <button class="btn-yt" onclick="openVideo('{{ $lesson->video_url }}')">
                            ▶ YouTube
                        </button>
                    @endif

                    @if($lesson->rutube_url)
                        <button class="btn-rt" onclick="openVideo('{{ $lesson->rutube_url }}')">
                            ▶ Rutube
                        </button>
                    @endif

                    @if($lesson->flash_cards)
                        <button class="btn-flash" onclick='startFlash(@json($lesson->flash_cards))'>
                            🎴 Карточки для запоминания
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <p style="text-align: center; color: #6b635b; margin-top: 20px;">Уроков пока нет. Расписание обновляется...</p>
        @endforelse
    </div>
</div>

<div id="video-modal">
    <div class="close-btn" onclick="closeVideo()">ЗАКРЫТЬ ×</div>
    <div id="video-container"></div>
</div>

<script>
    // Логика плеера
    function openVideo(url) {
        let embedSrc = '';
        if (url.includes('youtu.be') || url.includes('youtube.com')) {
            const id = url.split('/').pop().split('?')[0];
            embedSrc = `https://www.youtube.com/embed/${id}?autoplay=1`;
        } else if (url.includes('rutube.ru')) {
            const id = url.split('/').filter(Boolean).pop();
            embedSrc = `https://rutube.ru/play/embed/${id}`;
        }

        if (embedSrc) {
            document.getElementById('video-container').innerHTML = 
                `<iframe src="${embedSrc}" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>`;
            document.getElementById('video-modal').style.display = 'flex';
        } else {
            window.open(url, '_blank');
        }
    }

    function closeVideo() {
        document.getElementById('video-modal').style.display = 'none';
        document.getElementById('video-container').innerHTML = '';
    }

    function startFlash(cards) {
        console.log("Запуск карточек:", cards);
        alert("Функция карточек в разработке, получено: " + JSON.stringify(cards).substring(0, 50) + "...");
    }
</script>

</body>
</html>