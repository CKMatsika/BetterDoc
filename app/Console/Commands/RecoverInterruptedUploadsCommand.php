<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UploadStatus;
use App\Models\ProcessingJob;
use App\Models\UploadSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RecoverInterruptedUploadsCommand extends Command
{
    protected $signature = 'betterdoc:uploads:recover';

    protected $description = 'Recover or clean up upload sessions and processing jobs interrupted by power outages.';

    public function handle(): int
    {
        $expiredCount = 0;
        $expiredSessions = UploadSession::withoutGlobalScopes()
            ->whereIn('status', [UploadStatus::Initiated->value, UploadStatus::Uploading->value, UploadStatus::Assembling->value])
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expiredSessions as $session) {
            for ($i = 0; $i < $session->total_chunks; $i++) {
                Storage::disk('betterdoc_quarantine')->delete("{$session->storage_temp_prefix}/part_{$i}");
            }
            $session->update(['status' => UploadStatus::Expired]);
            $expiredCount++;
        }

        $stalledJobs = ProcessingJob::withoutGlobalScopes()
            ->where('status', 'in_progress')
            ->where('heartbeat_at', '<', now()->subMinutes(10))
            ->update(['status' => 'pending']);

        $this->info("Recovered/expired {$expiredCount} upload sessions and reset {$stalledJobs} stalled processing jobs.");

        return self::SUCCESS;
    }
}
