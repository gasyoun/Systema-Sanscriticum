<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>{{ $archive['title'] }}</title>
<style>body{margin:0;background:#f4efe4;color:#20221f;font:18px/1.6 Georgia,serif}main{max-width:760px;margin:9vh auto;padding:32px}.seal{font:700 12px system-ui;letter-spacing:.14em;text-transform:uppercase;color:#8b3b2f}h1{font-size:48px;line-height:1.05}.offer{border-top:1px solid #bcaf99;padding:20px 0;display:flex;justify-content:space-between;gap:20px}a{color:#8b3b2f;font-weight:bold}</style></head>
<body><main data-private-archive="{{ $archiveKey }}"><div class="seal">Личное предложение · не для публичного каталога</div><h1>{{ $archive['title'] }}</h1><p>{{ $archive['description'] }}</p>
@forelse($tariffs as $tariff)<div class="offer"><span>{{ $tariff->title }} · {{ number_format((float) $tariff->price, 0, ',', ' ') }} ₽</span><a href="{{ route('checkout.show', ['tariff' => $tariff, 'source_site' => 'private_archive', 'archive' => $archiveKey]) }}">Перейти к оплате</a></div>@empty<p>Предложение временно не принимает оплату.</p>@endforelse
</main></body></html>
