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

        $storageDir = storage_path('app/guide-shots/accountant');
        $storagePath = $storageDir.DIRECTORY_SEPARATOR.$file;
        $mapDir = base_path('docs/screenshots/accountant-map');
        $mapPath = $mapDir.DIRECTORY_SEPARATOR.$file;

        if (is_file($storagePath)) {
            $path = $storagePath;
            $root = realpath($storageDir);
        } elseif ($file === 'money-map-1600.png' && is_file($mapPath)) {
            // Карта без ПДн лежит в git; живые кадры экранов — по-прежнему storage.
            $path = $mapPath;
            $root = realpath($mapDir);
        } else {
            abort(404);
        }

        $real = realpath($path);

        abort_unless(is_string($real) && is_string($root) && str_starts_with($real, $root), 404);
        abort_unless(is_file($real), 404);

        return response()->file($real, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
