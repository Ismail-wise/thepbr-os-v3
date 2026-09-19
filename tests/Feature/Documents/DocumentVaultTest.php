<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Evidence\Evidence;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Presentation\Http\Controllers\Records\DocumentVaultController;
use App\Presentation\Http\Controllers\Records\EvidenceController;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DocumentVaultTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $capturedSessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['events']->listen(
            RequestHandled::class,
            function (RequestHandled $event): void {
                if (! $event->request->hasSession()) {
                    return;
                }

                $sessionId = $event->request->session()->getId();

                if ($sessionId !== '') {
                    $this->capturedSessionIds[] = $sessionId;
                }
            },
        );
    }

    protected function tearDown(): void
    {
        try {
            if (config('session.driver') === 'redis') {
                $redis = Redis::connection('default');
                $prefix = (string) config('session.prefix', '');

                foreach (array_unique($this->capturedSessionIds) as $sessionId) {
                    $redis->del($prefix.$sessionId);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_document_vault_route_contract_is_exact(): void
    {
        $expected = [
            'records.documents.index' => [
                'GET',
                'records/documents',
                DocumentVaultController::class,
                'index',
            ],
            'records.documents.store' => [
                'POST',
                'records/documents',
                DocumentVaultController::class,
                'store',
            ],
            'records.documents.show' => [
                'GET',
                'records/documents/{document}',
                DocumentVaultController::class,
                'show',
            ],
            'records.documents.versions.store' => [
                'POST',
                'records/documents/{document}/versions',
                DocumentVaultController::class,
                'storeVersion',
            ],
            'records.documents.versions.download' => [
                'GET',
                'records/documents/{document}/versions/{documentVersion}/download',
                DocumentVaultController::class,
                'downloadVersion',
            ],
            'records.documents.versions.evidence.store' => [
                'POST',
                'records/documents/{document}/versions/{documentVersion}/evidence',
                EvidenceController::class,
                'store',
            ],
            'records.evidence.links.store' => [
                'POST',
                'records/evidence/{evidence}/links',
                EvidenceController::class,
                'link',
            ],
            'records.evidence.verify' => [
                'POST',
                'records/evidence/{evidence}/verify',
                EvidenceController::class,
                'verify',
            ],
        ];

        foreach ($expected as $name => [$verb, $uri, $controller, $method]) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertInstanceOf(Route::class, $route);
            $this->assertSame($uri, $route->uri());
            $this->assertContains($verb, $route->methods());
            $this->assertSame(
                $controller.'@'.$method,
                $route->getActionName(),
            );
            $this->assertContains(
                EnsureCurrentBusinessContext::class,
                $route->gatherMiddleware(),
            );
        }
    }

    public function test_document_vault_list_and_detail_fail_closed(): void
    {
        $this->withoutVite();

        [$user, $business, $membership] = $this->context();

        $this->allowSystem(
            $business,
            $membership,
            'records.view',
        );

        $visible = $this->document(
            $business,
            $membership,
            'Visible Document',
        );

        $hidden = $this->document(
            $business,
            $membership,
            'Hidden Document',
        );

        $this->allowDocument(
            $business,
            $membership,
            $visible,
            DocumentAccessRight::View,
        );

        $this->version(
            $business,
            $membership,
            $visible,
            1,
        );

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $business->getKey(),
            ])
            ->get('/records/documents')
            ->assertOk()
            ->assertSee('Visible Document')
            ->assertDontSee('Hidden Document');

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $business->getKey(),
            ])
            ->get('/records/documents/'.$visible->getKey())
            ->assertOk();

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $business->getKey(),
            ])
            ->get('/records/documents/'.$hidden->getKey())
            ->assertNotFound();

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $business->getKey(),
            ])
            ->get('/records/documents/'.Str::uuid7())
            ->assertNotFound();
    }

    public function test_authorized_surface_uploads_versions_downloads_and_creates_evidence(): void
    {
        Storage::fake('business_documents');

        [$user, $business, $membership] = $this->context();

        $this->allowSystem(
            $business,
            $membership,
            'records.manage',
        );

        $this->allowSystem(
            $business,
            $membership,
            'records.view',
        );

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $business->getKey(),
            ])
            ->post('/records/documents', [
                'title' => 'Agreement',
                'category' => DocumentCategory::AgreementsContracts->value,
                'file' => $this->pdf('agreement.pdf'),
            ])
            ->assertRedirect();

        $document = Document::query()
            ->where('business_id', $business->getKey())
            ->where('title', 'Agreement')
            ->sole();

        $this->assertDatabaseCount('document_versions', 1);

        $this->assertDatabaseHas('document_access_grants', [
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => DocumentAccessRight::View->value,
            'effect' => 'allow',
        ]);

        $this->assertDatabaseHas('document_access_grants', [
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => DocumentAccessRight::Manage->value,
            'effect' => 'allow',
        ]);

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $business->getKey(),
            ])
            ->post(
                '/records/documents/'.$document->getKey().'/versions',
                [
                    'file' => $this->pdf('agreement-v2.pdf'),
                ],
            )
            ->assertRedirect();

        $versions = DocumentVersion::query()
            ->where('business_id', $business->getKey())
            ->where('document_id', $document->getKey())
            ->orderBy('version_number')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertSame(1, $versions[0]->version_number);
        $this->assertSame(2, $versions[1]->version_number);
        $this->assertSame(
            (string) $versions[0]->getKey(),
            (string) $versions[1]->supersedes_document_version_id,
        );

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $business->getKey(),
            ])
            ->get(
                '/records/documents/'
                .$document->getKey()
                .'/versions/'
                .$versions[1]->getKey()
                .'/download',
            )
            ->assertOk();

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $business->getKey(),
            ])
            ->post(
                '/records/documents/'
                .$document->getKey()
                .'/versions/'
                .$versions[1]->getKey()
                .'/evidence',
                [
                    'confidentiality' => 'restricted',
                    'source_date' => '2026-09-19',
                ],
            )
            ->assertRedirect();

        $evidence = Evidence::query()->sole();

        $this->assertSame(
            (string) $versions[1]->getKey(),
            (string) $evidence->document_version_id,
        );

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $business->getKey(),
            ])
            ->post(
                '/records/evidence/'.$evidence->getKey().'/verify',
                [
                    'verification_method' => 'manual_review',
                    'verification_note' => 'Checked exact version.',
                ],
            )
            ->assertRedirect();

        $this->assertNotNull($evidence->refresh()->verified_at);
    }

    public function test_product_surface_does_not_expose_storage_internals(): void
    {
        $paths = [
            base_path(
                'app/Presentation/Http/Controllers/Records/DocumentVaultController.php',
            ),
            base_path(
                'app/Presentation/Http/Controllers/Records/EvidenceController.php',
            ),
            base_path(
                'resources/js/pages/Records/Documents/Index.vue',
            ),
            base_path(
                'resources/js/pages/Records/Documents/Show.vue',
            ),
        ];

        $source = implode(
            "\n",
            array_map(
                static fn (string $path): string => (string) file_get_contents($path),
                $paths,
            ),
        );

        foreach ([
            'storage_key',
            'temporaryUrl',
            'temporary_url',
            'presigned',
            'AWS_SECRET',
            'AWS_ACCESS',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            '/(?:Document|DocumentVersion|Evidence)::find\s*\(/',
            $source,
        );
    }

    /**
     * @return array{User, Business, Membership}
     */
    private function context(): array
    {
        $user = User::query()->create([
            'email' => Str::uuid7().'@example.test',
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => 'Document Vault '.Str::uuid7(),
            'origin_type' => BusinessOriginType::StartedThroughPbr,
            'business_stage' => BusinessStage::Idea,
            'setup_phase' => SetupPhase::Formation,
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => 'USD',
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active,
        ]);

        return [$user, $business, $membership];
    }

    private function allowSystem(
        Business $business,
        Membership $membership,
        string $key,
    ): void {
        $permission = Permission::query()->create([
            'key' => $key,
        ]);

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => PermissionEffect::Allow,
        ]);
    }

    private function allowDocument(
        Business $business,
        Membership $membership,
        Document $document,
        DocumentAccessRight $right,
    ): void {
        DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => $right,
            'effect' => 'allow',
        ]);
    }

    private function document(
        Business $business,
        Membership $membership,
        string $title,
    ): Document {
        return Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => $title,
            'category' => DocumentCategory::CorporateLegal,
            'created_by_membership_id' => $membership->getKey(),
        ]);
    }

    private function version(
        Business $business,
        Membership $membership,
        Document $document,
        int $number,
    ): DocumentVersion {
        return DocumentVersion::query()->create([
            'business_id' => $business->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => $number,
            'original_filename' => 'document-'.$number.'.pdf',
            'storage_key' => 'test/'.Str::uuid7(),
            'size_bytes' => 16,
            'mime_type' => 'application/pdf',
            'content_sha256' => str_repeat('a', 64),
            'uploaded_by_membership_id' => $membership->getKey(),
            'effective_from' => null,
            'supersedes_document_version_id' => null,
        ]);
    }

    private function pdf(string $filename): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $filename,
            "%PDF-1.4\n% thePBR OS test document\n",
        );
    }
}
