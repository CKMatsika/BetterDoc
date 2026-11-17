<?php

declare(strict_types=1);

namespace App\Enums;

enum TemplateCategory: string
{
    case Memo = 'memo';
    case Letter = 'letter';
    case Minutes = 'minutes';
    case Contract = 'contract';
    case Directive = 'directive';
}
