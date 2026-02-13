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
        <p style="font-size: 12px; color: var(--text-sec)">Ученик: {{ Auth::user()->name ?? 'Гость' }}</p>
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
            <p>Уроков пока нет. Расписание обновляется...</p>
        @endforelse
    </div>
</div>

<div id="video-modal">
    <div class="close-btn" onclick="closeVideo()">ЗАКРЫТЬ ×</div>
    <div id="video-container"></div>
</div>

<script>
    // Логика плеера (ваша оригинальная, адаптированная)
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
        // Здесь можно вставить вашу логику перелистывания карточек
        alert("Функция карточек в разработке, получено: " + JSON.stringify(cards).substring(0, 50) + "...");
    }
</script>

</body>
</html>
