<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppOutboxRecoveryService;
use Illuminate\Console\Command;

class RecoverWhatsAppOutboxCommand extends Command
{
    protected $signature = 'whatsapp:outbox-recover
                            {--limit=50 : Maximum number of messages to process}
                            {--threshold=2 : Minimum age in minutes for pending messages}
                            {--sync : Execute dispatch synchronously instead of queuing}';

    protected $description = 'Recover stranded pending and enqueue-failed WhatsApp outbox messages';

    public function handle(WhatsAppOutboxRecoveryService $recoveryService): int
    {
        $limit = (int) $this->option('limit');
        $threshold = (int) $this->option('threshold');
        $sync = (bool) $this->option('sync');

        $this->info("Scanning for stranded WhatsApp outbox messages (threshold: {$threshold}m, limit: {$limit})...");

        $results = $recoveryService->recoverStuckMessages(
            limit: $limit,
            stuckThresholdMinutes: $threshold,
            sync: $sync
        );

        $reconciledStats = $results['reconciled_receipts'] ?? [];
        $reconciledCount = is_array($reconciledStats) ? ($reconciledStats['reconciled'] ?? 0) : (int) $reconciledStats;
        $staleCount = is_array($reconciledStats) ? ($reconciledStats['expired_stale'] ?? 0) : 0;

        $this->info("Recovery pass complete: {$results['scanned']} scanned, {$results['recovered']} recovered, {$results['skipped']} skipped, {$results['failed']} failed (Reconciliation: {$reconciledCount} reconciled, {$staleCount} stale skipped).");

        return Command::SUCCESS;
    }
}
