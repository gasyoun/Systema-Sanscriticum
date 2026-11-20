<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\KocherginaMaterialBundles;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Открытые страницы наборов материалов курса Кочергиной (H8210).
 *
 * Материалы общедоступны без auth: выдача по теме/готовности, не по оплате.
 * Доступ к урокам/тарифам здесь не затрагивается — только справочные материалы.
 */
class KocherginaMaterialsController extends Controller
{
    public function hub(): View
    {
        return view('materials.kochergina.hub', [
            'bundles' => KocherginaMaterialBundles::bundles(),
        ]);
    }

    public function show(string $slug): View|Response
    {
        $bundle = KocherginaMaterialBundles::bundle($slug);

        if ($bundle === null) {
            abort(404);
        }

        return view('materials.kochergina.bundle', [
            'bundle' => $bundle,
        ]);
    }
}
