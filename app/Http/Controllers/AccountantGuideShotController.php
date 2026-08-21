<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\RoleGate;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * H3214 — кадры книги бухгалтера с диска, не из git.
 *
 * Имя файла — только basename ([a-z0-9.-].png). Каталог константный.
 */
final class AccountantGuideShotController extends Controller
{
    public function show(string $file): BinaryFileResponse|Response
    {
        abort_unless(RoleGate::finance(), 403);
        abort_unless((bool) preg_match('/^[a-z0-9][a-z0-9._-]*\.png$/i', $file), 404);

        $path = storage_path('app/guide-shots/accountant/'.$file);
        $real = realpath($path);
        $root = realpath(storage_path('app/guide-shots/accountant'));

        abort_unless(is_string($real) && is_string($root) && str_starts_with($real, $root), 404);
        abort_unless(is_file($real), 404);

        return response()->file($real, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
