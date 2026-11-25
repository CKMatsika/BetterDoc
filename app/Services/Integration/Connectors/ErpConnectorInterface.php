<?php

declare(strict_types=1);

namespace App\Services\Integration\Connectors;

use App\Enums\ErpSystemType;
use App\Models\IntegrationConnection;
use App\Models\User;

interface ErpConnectorInterface
{
    public function supports(): ErpSystemType;

    /**
     * @return array{ok: bool, message: string, details: array<string, mixed>}
     */
    public function testConnection(IntegrationConnection $connection): array;

    /**
     * @return array{processed: int, failed: int, details: array<string, mixed>}
     */
    public function pullVendors(IntegrationConnection $connection, User $actor): array;

    /**
     * @return array{processed: int, failed: int, details: array<string, mixed>}
     */
    public function pullPurchaseOrders(IntegrationConnection $connection, User $actor): array;

    /**
     * @return array{processed: int, failed: int, details: array<string, mixed>}
     */
    public function pushApprovedInvoices(IntegrationConnection $connection, User $actor): array;
}
