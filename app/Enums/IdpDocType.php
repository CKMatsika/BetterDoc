<?php

declare(strict_types=1);

namespace App\Enums;

enum IdpDocType: string
{
    case Invoice = 'invoice';
    case Letter = 'letter';
    case Contract = 'contract';
    case Form = 'form';
    case IdDocument = 'id_document';
    case Memo = 'memo';
    case Other = 'other';
}
