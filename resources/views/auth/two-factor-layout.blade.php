<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Двухфакторная защита') — {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
               font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
               background: #f3f4f6; color: #111827; }
        .card { width: 100%; max-width: 460px; background: #fff; border: 1px solid rgba(0,0,0,.08);
                border-radius: 14px; padding: 28px; box-shadow: 0 6px 24px rgba(0,0,0,.06); }
        h1 { margin: 0 0 8px; font-size: 22px; }
        p { margin: 0 0 14px; }
        .muted { color: #6b7280; font-size: 14px; }
        label { display: block; font-weight: 600; margin: 0 0 6px; }
        input[name="code"] { width: 100%; padding: 12px 14px; font-size: 20px; letter-spacing: 3px;
                text-align: center; border: 1px solid #d1d5db; border-radius: 10px; }
        button, .button { display: inline-block; width: 100%; margin-top: 12px; padding: 12px 16px;
                font-size: 16px; font-weight: 600; color: #fff; background: #1f2937; border: 0;
                border-radius: 10px; cursor: pointer; text-align: center; text-decoration: none; }
        .secondary { background: transparent; color: #374151; border: 1px solid #d1d5db; font-weight: 500; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px;
                 padding: 10px 12px; margin: 0 0 14px; font-size: 14px; }
        .key { font: 600 18px/1.6 ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: 1px;
               background: #f9fafb; border: 1px dashed #d1d5db; border-radius: 10px; padding: 12px;
               text-align: center; word-break: break-all; }
        .qr { text-align: center; margin: 10px 0 16px; }
        .qr svg { width: 200px; height: 200px; }
        .codes { font: 600 16px/1.9 ui-monospace, SFMono-Regular, Menlo, monospace; background: #f9fafb;
                 border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 16px; columns: 2; }
        form { margin: 0; }
        .actions { margin-top: 18px; }
        @media (prefers-color-scheme: dark) {
            body { background: #0b1220; color: #e5e7eb; }
            .card { background: #111827; border-color: rgba(255,255,255,.08); }
            .muted { color: #9ca3af; }
            input[name="code"], .key, .codes { background: #0f172a; border-color: #374151; color: #e5e7eb; }
            button { background: #e5e7eb; color: #111827; }
            .secondary { background: transparent; color: #e5e7eb; border-color: #4b5563; }
        }
    </style>
</head>
<body>
    <main class="card">
        @yield('content')
    </main>
</body>
</html>
