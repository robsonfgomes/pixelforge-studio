<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Generations\Actions\CreateGeneration;
use App\Domain\Pricing\Exceptions\PriceTableUnavailable;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class GenerationController extends Controller
{
    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $createGeneration->handle($user, $request->toData());
        } catch (PriceTableUnavailable) {
            throw ValidationException::withMessages([
                'estimate' => 'Estimate unavailable. Please try again in a moment.',
            ]);
        }

        return back();
    }
}
