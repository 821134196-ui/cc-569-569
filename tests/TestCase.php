<?php

namespace Tests;

use App\Domain\BusinessDayService;
use App\Models\BusinessDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected User $auditor;
    protected User $auditorB;
    protected User $frontdesk;
    protected User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditor = User::create(['name' => '夜审员甲', 'email' => 'a@t.local', 'password' => 'x', 'role' => User::ROLE_AUDITOR]);
        $this->auditorB = User::create(['name' => '夜审员乙', 'email' => 'b@t.local', 'password' => 'x', 'role' => User::ROLE_AUDITOR]);
        $this->frontdesk = User::create(['name' => '前台', 'email' => 'f@t.local', 'password' => 'x', 'role' => User::ROLE_FRONTDESK]);
        $this->finance = User::create(['name' => '财务', 'email' => 'c@t.local', 'password' => 'x', 'role' => User::ROLE_FINANCE]);
    }

    protected function openDay(string $date): BusinessDay
    {
        return app(BusinessDayService::class)->ensureExists($date);
    }

    /** 以指定用户身份调用 API。 */
    protected function asUser(User $user): self
    {
        return $this->withHeaders(['X-User-Id' => (string) $user->id]);
    }
}
