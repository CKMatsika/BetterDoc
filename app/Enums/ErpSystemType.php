<?php

declare(strict_types=1);

namespace App\Enums;

enum ErpSystemType: string
{
    case InsuranceCore = 'insurance_core';
    case Xero = 'xero';
    case Netsuite = 'netsuite';
    case SagePastel = 'sage_pastel';
    case Belina = 'belina';
    case CustomWebhook = 'custom_webhook';

    public function label(): string
    {
        return match ($this) {
            self::InsuranceCore => 'Insurance Core ERP / Policy & Claims',
            self::Xero => 'Xero Cloud Accounting',
            self::Netsuite => 'Oracle NetSuite ERP',
            self::SagePastel => 'Sage Pastel (Partner / Evolution)',
            self::Belina => 'Belina Payroll & HR',
            self::CustomWebhook => 'Custom REST / HMAC Webhook',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
};
