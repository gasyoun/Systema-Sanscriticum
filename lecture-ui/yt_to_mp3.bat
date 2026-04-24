@echo off
chcp 65001 > nul

if "%~1"=="" (
    echo Использование: перетащи ссылку на этот bat файл
    echo или запусти: yt_to_mp3.bat https://youtu.be/...
    pause
    exit
)

echo Скачиваю аудио...
py -3.11 -m yt_dlp -x --audio-format mp3 --audio-quality 128K -o "MP3\%%(title)s.%%(ext)s" "%~1"

echo Готово!
pause
