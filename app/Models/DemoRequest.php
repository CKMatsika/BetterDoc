<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property string $organisation
 * @property string $work_email
 * @property string|null $phone
 * @property string|null $message
 * @property string|null $source_ip
 * @property string $status
 * @property Carbon|null $created_at
 */
class DemoRequest extends Model
{
    use HasUuids;

    protected $table = 'demo_requests';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'name',
        'organisation',
        'work_email',
        'phone',
        'message',
        'source_ip',
        'status',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
