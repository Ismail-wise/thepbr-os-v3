<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\IssuePbrAccessCode;
use App\Application\Identity\RevokePbrAccessCode;
use App\Domain\Identity\Enums\PbrAccessCodeStatus;
use App\Infrastructure\Persistence\Eloquent\Identity\PbrAccessCode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PbrAccessCodeAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_code_is_stored_only_as_fingerprint_and_last_four(): void
    {
        $result = $this->app
            ->make(IssuePbrAccessCode::class)
            ->handle(
                boundEmail: 'Client@Example.COM',
                expiresInHours: 72,
                clientReference: 'CLIENT-42',
                batchReference: 'SEMINAR-2026-10',
                notes: 'Paid client',
                actorLabel: 'PBR Administrator',
                reason: 'Client onboarding approved',
            );

        self::assertSame(48, strlen($result['token']));
        self::assertMatchesRegularExpression(
            '/\A[A-Z0-9]{48}\z/',
            $result['token'],
        );
        self::assertFalse(
            Schema::hasColumn('pbr_access_codes', 'token'),
        );

        $stored = PbrAccessCode::query()->findOrFail($result['id']);

        self::assertSame(
            hash('sha256', $result['token']),
            $stored->getRawOriginal('token_fingerprint'),
        );
        self::assertSame(
            substr($result['token'], -4),
            $stored->token_last4,
        );
        self::assertSame(
            'client@example.com',
            $stored->bound_email,
        );
        self::assertSame(
            PbrAccessCodeStatus::Pending,
            $stored->status,
        );
        self::assertSame('CLIENT-42', $stored->client_reference);
        self::assertSame('SEMINAR-2026-10', $stored->batch_reference);
    }

    public function test_pending_access_code_can_be_revoked_once_and_history_is_immutable(): void
    {
        $result = $this->app
            ->make(IssuePbrAccessCode::class)
            ->handle(
                boundEmail: null,
                expiresInHours: null,
                clientReference: null,
                batchReference: null,
                notes: null,
                actorLabel: 'PBR Administrator',
                reason: 'Controlled test issuance',
            );

        $revoke = $this->app->make(RevokePbrAccessCode::class);

        self::assertTrue(
            $revoke->handle(
                $result['id'],
                'PBR Administrator',
                'Client request cancelled',
            ),
        );

        self::assertFalse(
            $revoke->handle(
                $result['id'],
                'PBR Administrator',
                'Duplicate revoke attempt',
            ),
        );

        $this->assertDatabaseHas('pbr_access_codes', [
            'id' => $result['id'],
            'status' => 'revoked',
            'revoked_by_label' => 'PBR Administrator',
            'revocation_reason' => 'Client request cancelled',
        ]);

        $this->expectException(QueryException::class);

        DB::table('pbr_access_codes')
            ->where('id', $result['id'])
            ->update([
                'notes' => 'History rewrite attempt',
            ]);
    }

    public function test_database_rejects_invalid_access_code_lifecycle(): void
    {
        $this->expectException(QueryException::class);

        DB::table('pbr_access_codes')->insert([
            'id' => '11111111-1111-7111-8111-111111111111',
            'token_fingerprint' => str_repeat('a', 64),
            'token_last4' => 'AB12',
            'status' => 'redeemed',
            'bound_email' => null,
            'client_reference' => null,
            'batch_reference' => null,
            'notes' => null,
            'expires_at' => null,
            'created_by_label' => 'PBR Administrator',
            'creation_reason' => 'Invalid lifecycle probe',
            'created_at' => now(),
            'redeemed_by_user_id' => null,
            'redeemed_at' => null,
            'revoked_at' => null,
            'revoked_by_label' => null,
            'revocation_reason' => null,
        ]);
    }

    public function test_admin_commands_exist_without_accepting_a_raw_token_argument(): void
    {
        foreach ([
            'access:code:issue',
            'access:code:revoke',
        ] as $name) {
            self::assertArrayHasKey($name, Artisan::all());

            $definition = Artisan::all()[$name]->getDefinition();

            self::assertFalse($definition->hasArgument('token'));
            self::assertFalse($definition->hasOption('token'));
        }

        $this->artisan('access:code:issue', [
            '--email' => 'cli-client@example.test',
            '--expires-in-hours' => '24',
            '--client' => 'CLI-CLIENT',
            '--actor' => 'CLI Administrator',
            '--reason' => 'Approved client onboarding',
        ])
            ->expectsOutputToContain(
                'Copy this access code now.',
            )
            ->expectsOutputToContain('Access code:')
            ->assertSuccessful();

        $this->assertDatabaseHas('pbr_access_codes', [
            'bound_email' => 'cli-client@example.test',
            'client_reference' => 'CLI-CLIENT',
            'status' => 'pending',
        ]);
    }
}
