<?php

declare(strict_types=1);

namespace App\Http\ViewModels;

use App\Domain\Generations\Data\GenerationRequestData;
use App\Domain\Generations\Enums\ImageQuality;
use App\Domain\Generations\Enums\ImageSize;
use App\Domain\Generations\Models\Generation;
use App\Http\Resources\GenerationResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Props of the generations page. `generations` and `hasInProgress` are closures, so the page's polling reload
 * (`only: ['generations', 'hasInProgress']`) computes just those two.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class GenerationPageViewModel implements Arrayable
{
    private const int HISTORY_LIMIT = 50;

    public function __construct(
        #[CurrentUser] private User $user,
        private int $pagePollingWindowMinutes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'composer' => $this->composer(),
            'generations' => fn (): array => GenerationResource::collection($this->latestGenerations())->resolve(),
            'hasInProgress' => fn (): bool => Generation::query()
                ->forUser($this->user)
                ->recentlyActive(now()->subMinutes($this->pagePollingWindowMinutes))
                ->exists(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        return [
            'sizes' => array_map(
                fn (ImageSize $size): array => ['value' => $size->value, 'aspectRatio' => $size->aspectRatio()],
                ImageSize::cases(),
            ),
            'qualities' => array_map(
                fn (ImageQuality $quality): array => ['value' => $quality->value, 'label' => $quality->label()],
                ImageQuality::cases(),
            ),
            'defaults' => ['size' => ImageSize::Square->value, 'quality' => ImageQuality::Medium->value],
            'limits' => [
                'promptLength' => GenerationRequestData::MAX_PROMPT_LENGTH,
                'referenceImages' => GenerationRequestData::MAX_REFERENCE_IMAGES,
            ],
        ];
    }

    /**
     * @return iterable<Generation>
     */
    private function latestGenerations(): iterable
    {
        return Generation::query()
            ->forUser($this->user)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();
    }
}
