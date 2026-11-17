<?php

declare(strict_types=1);

namespace App\Enums;

enum AnnotationKind: string
{
    case Note = 'note';
    case Highlight = 'highlight';
    case RedactionMark = 'redaction_mark';
}
