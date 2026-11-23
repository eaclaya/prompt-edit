<?php

namespace App\Enums;

/** Billable token kinds; values are the keys used by `usage` and `rates` in the API. */
enum TokenType: string
{
    case InputText = 'input_text_tokens';
    case InputImage = 'input_image_tokens';
    case Output = 'output_tokens';
    case Reasoning = 'reasoning_tokens';
}
