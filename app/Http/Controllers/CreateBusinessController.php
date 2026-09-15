<?php

namespace App\Http\Controllers;

use App\Application\Businesses\CreateBusiness;
use App\Application\Businesses\ResolveCurrentBusiness;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CreateBusinessController
{
    public function create(Request $request): Response
    {
        return Inertia::render('Businesses/Create', [
            'originTypes' => [
                [
                    'value' => BusinessOriginType::StartedThroughPbr->value,
                    'label' => 'Started through PBR',
                ],
                [
                    'value' => BusinessOriginType::ExistingBusinessImportedIntoPbr->value,
                    'label' => 'Existing Business imported into PBR',
                ],
            ],
            'businessStages' => [
                ['value' => BusinessStage::Idea->value, 'label' => 'Idea'],
                ['value' => BusinessStage::Validation->value, 'label' => 'Validation'],
                ['value' => BusinessStage::Planning->value, 'label' => 'Planning'],
                ['value' => BusinessStage::PreLaunch->value, 'label' => 'Pre-launch'],
                ['value' => BusinessStage::Operating->value, 'label' => 'Operating'],
                ['value' => BusinessStage::Growth->value, 'label' => 'Growth'],
                ['value' => BusinessStage::Restructuring->value, 'label' => 'Restructuring'],
                ['value' => BusinessStage::Exit->value, 'label' => 'Exit'],
            ],
            'success' => $request->session()->get('success'),
        ]);
    }

    public function store(
        Request $request,
        CreateBusiness $createBusiness,
        ResolveCurrentBusiness $resolveCurrentBusiness,
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'origin_type' => [
                'required',
                'string',
                Rule::enum(BusinessOriginType::class),
            ],
            'business_stage' => [
                'required',
                'string',
                Rule::enum(BusinessStage::class),
            ],
            'base_currency' => [
                'required',
                'string',
                'regex:/\A[A-Z]{3}\z/',
            ],
        ]);

        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $createdBusiness = $createBusiness->handle(
            $user,
            $validated['name'],
            BusinessOriginType::from($validated['origin_type']),
            BusinessStage::from($validated['business_stage']),
            $validated['base_currency'],
        );

        $authorizedBusiness = $resolveCurrentBusiness->handle(
            $user,
            $createdBusiness->getKey(),
        );

        abort_if($authorizedBusiness === null, 500);

        $request->session()->put(
            EnsureCurrentBusinessContext::SESSION_KEY,
            $authorizedBusiness->getKey(),
        );

        return redirect()
            ->route('businesses.create')
            ->with('success', 'Business created successfully.');
    }
}
