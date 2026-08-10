<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Дымовой тест харнеса Dusk (H2532).
 *
 * Задача одна: доказать, что браузерный харнес рабочий — вход под ролью
 * преподавателя открывает ЛК. Никаких скриншотов гида (это H2502), никаких
 * денежных экранов.
 *
 * ЛК преподавателя — это панель Filament `/admin`: отдельной панели для
 * преподавателя нет, фейсконтроль сидит в User::canAccessPanel(), где ветка
 * `default` пускает isTeacher() (см. app/Models/User.php). Панель редиректит
 * с `/admin` на свой первый доступный ресурс — у преподавателя это
 * `/admin/lessons`, поэтому проверяем префикс пути, а не точный URL.
 *
 * DatabaseTruncation, а не RefreshDatabase: транзакция RefreshDatabase живёт в
 * тест-процессе, а браузер стучится к отдельному процессу `artisan serve` и её
 * не видит — созданный пользователь был бы браузеру не виден.
 *
 * Якоря намеренно текстовые (подписи навигации), а не CSS-селекторы вёрстки:
 * первый же редизайн ломает `.fi-sidebar-nav a:nth-child(3)`, а название
 * раздела переживает его. Имя пользователя якорем НЕ годится — Filament держит
 * его внутри выпадающего меню аватара, в тексте страницы его нет.
 */
class SmokeTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_teacher_can_open_cabinet(): void
    {
        $teacher = User::factory()->create([
            'role' => Roles::TEACHER,
            'name' => 'Dusk Smoke Teacher',
        ]);

        $this->browse(function (Browser $browser) use ($teacher) {
            $browser->loginAs($teacher)
                ->visit('/admin')
                // Дошли до панели, а не отлетели на форму входа и не в 403.
                ->assertPathBeginsWith('/admin')
                ->assertDontSee('Забыли пароль')
                // Навигация ЛК отрисовалась — значит панель реально собралась,
                // ассеты Filament на месте и роль пропущена фейсконтролем.
                ->assertSee('Обучение')
                ->assertSee('Расписание');
        });
    }
}
