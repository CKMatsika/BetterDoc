<?php

declare(strict_types=1);

namespace App\Enums;

enum PrinterDriver: string
{
    case LocalSpool = 'local_spool';
    case CupsIpps = 'cups_ipps';
}
