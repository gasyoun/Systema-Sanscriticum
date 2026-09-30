<?php

namespace Tests\Feature\Analytics;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Пиксель VK Ads на витрине (layouts/shop → partials/shop-vk-pixel):
 * включается ID в config('analytics.vk_pixel.shop_pixel_id'), шлёт цели
 * витрины через обёртку window.shopReachGoal и не дублирует payment_success
 * с пикселем промо-воронки из сессии. payment_success в ВК — только для
 * подтверждённой оплаты, один раз на платёж (ключ в localStorage), с суммой.
 */
class ShopVkPixelTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('analytics.metrika.enabled', true);
        config()->set('analytics.metrika.shop_counter_id', '106964341');
        $this->course = Course::factory()->create(['is_visible' => true, 'slug' => 'vk-pixel-test']);
    }

    public function test_course_page_carries_vk_pixel_and_wraps_shop_goals(): void
    {
        config()->set('analytics.vk_pixel.shop_pixel_id', '3512345');

        $html = $this->get(route('shop.course.show', $this->course->slug))->assertOk()->getContent();

        $this->assertStringContainsString('top-fwz1.mail.ru/js/code.js', $html);
        $this->assertStringContainsString('_tmr.push({id: "3512345", type: "pageView"', $html);
        $this->assertStringContainsString('window.SHOP_VK_PIXEL_ID = "3512345"', $html);
        // Метрика на месте, и обёртка ВК подключена после неё.
        $this->assertLessThan(
            strpos($html, 'window.SHOP_VK_PIXEL_ID'),
            strpos($html, 'window.SHOP_METRIKA_ID'),
        );
    }

    public function test_pixel_absent_without_id_or_when_disabled(): void
    {
        config()->set('analytics.vk_pixel.shop_pixel_id', null);
        $this->get(route('shop.course.show', $this->course->slug))
            ->assertOk()
            ->assertDontSee('top-fwz1.mail.ru', false);

        config()->set('analytics.vk_pixel.shop_pixel_id', '3512345');
        config()->set('analytics.vk_pixel.enabled', false);
        $this->get(route('shop.course.show', $this->course->slug))
            ->assertOk()
            ->assertDontSee('top-fwz1.mail.ru', false);
    }

    public function test_non_numeric_id_is_not_injected_into_js(): void
    {
        config()->set('analytics.vk_pixel.shop_pixel_id', '1"});alert(1);//');

        $this->get(route('shop.course.show', $this->course->slug))
            ->assertOk()
            ->assertDontSee('top-fwz1.mail.ru', false)
            ->assertDontSee('alert(1)', false);
    }

    public function test_confirmed_payment_fires_shop_vk_goal_once_with_amount_and_once_key(): void
    {
        config()->set('analytics.vk_pixel.shop_pixel_id', '3512345');

        $html = $this->successPageAs('paid');

        $this->assertSame(1, substr_count($html, $this->vkGoal('3512345')));
        $this->assertStringContainsString('var value = 12500', $html);
        $this->assertMatchesRegularExpression("/var onceKey = 'vk_payment_success_\\d+'/", $html);
    }

    public function test_pending_payment_and_guest_do_not_fire_vk_goal(): void
    {
        config()->set('analytics.vk_pixel.shop_pixel_id', '3512345');

        $this->assertStringNotContainsString("goal: 'payment_success'", $this->successPageAs('pending'));

        auth()->logout();
        $guest = $this->get('/payment/success')->assertOk()->getContent();
        $this->assertStringNotContainsString("goal: 'payment_success'", $guest);
    }

    public function test_payment_success_same_session_pixel_is_not_double_counted(): void
    {
        config()->set('analytics.vk_pixel.shop_pixel_id', '3512345');

        $html = $this->successPageAs('paid', ['vk_id' => '3512345']);

        $this->assertSame(1, substr_count($html, '_tmr.push({id: "3512345", type: "pageView"'));
        $this->assertSame(1, substr_count($html, $this->vkGoal('3512345')));
    }

    public function test_payment_success_different_session_pixel_gets_both_goals(): void
    {
        config()->set('analytics.vk_pixel.shop_pixel_id', '3512345');

        $html = $this->successPageAs('paid', ['vk_id' => '777']);

        $this->assertSame(1, substr_count($html, $this->vkGoal('777')));
        $this->assertSame(1, substr_count($html, $this->vkGoal('3512345')));
    }

    private function successPageAs(string $status, array $session = []): string
    {
        $user = User::factory()->create();
        Payment::withoutEvents(fn (): Payment => Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'amount' => 12500,
            'tariff' => 'full',
            'status' => $status,
        ]));

        return $this->actingAs($user)->withSession($session)
            ->get('/payment/success')->assertOk()->getContent();
    }

    private function vkGoal(string $pixelId): string
    {
        return "_tmr.push({ type: 'reachGoal', id: \"{$pixelId}\", goal: 'payment_success', value: value });";
    }
}
