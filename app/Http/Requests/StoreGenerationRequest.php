<?php

namespace App\Http\Requests;

use App\Contracts\Data\EstimateInput;
use App\Enums\Quality;
use App\Enums\Size;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The body of POST /generations and POST /estimate (StoreGenerationRequest in
 * resources/js/types/generations.ts). Only these fields ever reach Pixelforge.
 */
class StoreGenerationRequest extends FormRequest
{
    public const int PROMPT_MAX_LENGTH = 4000;

    public const int REFERENCE_IMAGES_MAX = 4;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.self::PROMPT_MAX_LENGTH],
            'size' => ['required', Rule::enum(Size::class)],
            'quality' => ['required', Rule::enum(Quality::class)],
            'reference_images' => ['sometimes', 'nullable', 'array', 'list', 'max:'.self::REFERENCE_IMAGES_MAX],
            'reference_images.*' => ['required', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    public function prompt(): string
    {
        return $this->string('prompt')->toString();
    }

    public function size(): Size
    {
        return $this->enum('size', Size::class) ?? Size::default();
    }

    public function quality(): Quality
    {
        return $this->enum('quality', Quality::class) ?? Quality::default();
    }

    /** @return list<string> */
    public function referenceImages(): array
    {
        /** @var list<string> */
        return array_values((array) $this->validated('reference_images', []));
    }

    public function estimateInput(): EstimateInput
    {
        return new EstimateInput($this->prompt(), $this->size(), $this->quality(), count($this->referenceImages()));
    }
}
