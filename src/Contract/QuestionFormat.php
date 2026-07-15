<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Answer shape an agent requests from the user via `ask_user`.
 */
enum QuestionFormat: string
{
    case SingleSelect = 'single_select';
    case MultiSelect = 'multi_select';
    case FreeText = 'free_text';

    public function isSelect(): bool
    {
        return $this === self::SingleSelect || $this === self::MultiSelect;
    }
}
