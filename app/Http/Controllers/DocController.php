<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DocController extends Controller
{
    /**
     * Публичная страница одного документа из public/docs/.
     * slug = имя PDF-файла без расширения (privacy, oferta, soglasie-pd, …).
     */
    public function show(string $slug)
    {
        // slug — только буквы/цифры/дефис: отсекаем path traversal и слэши.
        abort_unless(preg_match('/^[a-z0-9\-]+$/i', $slug), 404);
        abort_unless(File::exists(public_path("docs/{$slug}.pdf")), 404);

        $title = config("docs.{$slug}", Str::headline($slug));

        return view('docs.show', [
            'slug' => $slug,
            'title' => $title,
        ]);
    }
}
