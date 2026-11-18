<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $subject_user_id
 * @property string $employee_number
 * @property string $department_name
 * @property string $job_title
 * @property string|null $folder_id
 * @property Carbon|null $hire_date
 * @property string $status
 */
class HrEmployeeFile extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'subject_user_id',
        'employee_number',
        'department_name',
        'job_title',
        'folder_id',
        'hire_date',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }
}
