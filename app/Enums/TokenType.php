<?php

namespace App\Enums;

/**
 * The four token kinds Pixelforge bills for. Values match the keys used in
 * the API's `usage`, `rates` and pricing responses.
 */
enum TokenType: string
{
    case InputText = 'input_text_tokens';
    case InputImage = 'input_image_tokens';
    case Output = 'output_tokens';
    case Reasoning = 'reasoning_tokens';

    public function label(): string
    {
        return match ($this) {
            self::InputText => 'Prompt text',
            self::InputImage => 'Reference images',
            self::Output => 'Image output',
            self::Reasoning => 'Reasoning',
        };
    }

    /** Whether the count is only known once the generation completes. */
    public function isKnownUpfront(): bool
    {
        return match ($this) {
            self::InputImage, self::Output => true,
            self::InputText, self::Reasoning => false,
        };
    }
}
