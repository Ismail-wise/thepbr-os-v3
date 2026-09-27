<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Application\Finance\GetFinanceWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/F6CFinancePolicyTest.php';

final class F6CTenantPermissionTest extends TestCase
{
    use F6CFixtureSupport;
    use RefreshDatabase;

    public function test_default_deny_blocks_active_member_without_finance_capability(): void
    {
        $context = $this->f6cContext();
        $this->f6cEffectiveFinance($context);
        [$otherUser] = $this->f6cMember($context['business'], 'no-finance-access');

        self::assertNull(
            $this->app->make(GetFinanceWorkspace::class)->execute(
                $otherUser,
                $context['business'],
            ),
        );
    }

    public function test_authorized_member_sees_only_current_business_finance_workspace(): void
    {
        $context = $this->f6cContext();
        $this->f6cEffectiveFinance($context);
        $other = $this->f6cContext();
        $this->f6cEffectiveFinance($other);

        $workspace = $this->app->make(GetFinanceWorkspace::class)->execute(
            $context['user'],
            $context['business'],
        );

        self::assertNotNull($workspace);
        self::assertSame(
            (string) $context['business']->getKey(),
            $workspace['business']['id'],
        );
        self::assertCount(0, array_filter(
            $workspace['payments']->all(),
            fn (object $row): bool => (string) $row->business_id !== (string) $context['business']->getKey(),
        ));
    }
}
