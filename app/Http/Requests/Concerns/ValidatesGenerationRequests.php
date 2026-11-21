<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Domain\Generations\Data\GenerationRequestData;
use App\Domain\Generations\Enums\ImageQuality;
use App\Domain\Generations\Enums\ImageSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ValidatesGenerationRequests
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['sometimes', 'list', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https'],
        ];
    }

    public function toData(): GenerationRequestData
    {
        $validated = $this->validated();

        return new GenerationRequestData(
            $validated['prompt'],
            ImageSize::from($validated['size']),
            ImageQuality::from($validated['quality']),
            $validated['reference_images'] ?? [],
        );
    }
}
