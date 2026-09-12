<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| H4516 — per-domain split. Each file below is a verbatim slice of the
| former single web.php; they are required in the ORIGINAL registration
| order, which is load-bearing: most blocks must register before the
| promo catch-all /{slug} (registered last, in web/public.php).
| Do not reorder files without re-running the route:list parity check.
|
*/

require __DIR__.'/web/shop.php';
require __DIR__.'/web/api-surface.php';
require __DIR__.'/web/student-public.php';
require __DIR__.'/web/auth.php';
require __DIR__.'/web/content.php';
require __DIR__.'/web/institute.php';
require __DIR__.'/web/student.php';
require __DIR__.'/web/staff.php';
require __DIR__.'/web/support.php';
require __DIR__.'/web/payments.php';
require __DIR__.'/web/admin.php';
require __DIR__.'/web/public.php';
