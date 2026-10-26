<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Internal (staff/owner) accounts
|--------------------------------------------------------------------------
|
| MG ruling 26-10-2026, verbatim: «@pe4kinsmart и @gasyoun никогда не
| считать, ни тут ни вообще, это админы».
|
| These user ids must never be counted as students: not in the public
| waitlist counter, not in a group's size, not in a forecast, not in a
| threshold that opens payment. The handle `pe4kinsmart` sits on TWO rows
| (937 «SuperAdmin» and 6767 «Кравченко Иван» with role=NULL), so a
| role/is_admin filter is NOT sufficient — keep the id list.
|
| Uprava's mirror of this list: tools/internal_accounts.py.
|
*/

return [
    'user_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('INTERNAL_USER_IDS', '937,6755,6767'))
    ))),
];
