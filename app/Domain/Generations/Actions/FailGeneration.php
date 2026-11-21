<?php

declare(strict_types=1);

namespace App\Domain\Generations\Actions;

use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Models\Generation;
use App\Domain\Pricing\ValueObjects\Money;
use Illuminate\Support\Facades\DB;

final readonly class FailGeneration
{
    public function handle(Generation $generation, string $errorCode, string $errorMessage): void
    {
        DB::transaction(function () use ($generation, $errorCode, $errorMessage): void {
            $locked = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo(GenerationStatus::Failed)) {
                return;
            }

            $locked->forceFill([
                'status' => GenerationStatus::Failed,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'provider_cost' => Money::zero(),
                'charge' => Money::zero(),
            ])->save();
        });
    }
}
