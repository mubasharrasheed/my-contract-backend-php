<?php

namespace App\Console\Commands;

use App\Models\Contract;
use Illuminate\Console\Command;

class MarkExpiredContractsCompleted extends Command
{
    protected $signature = 'contracts:mark-expired';

    protected $description = 'Mark contracts as completed when their expiry date has passed';

    public function handle(): int
    {
        Contract::query()
            ->where('status', Contract::STATUS_IN_PROGRESS)
            ->whereNotNull('expiry_date')
            ->chunkById(200, function ($contracts) {
                foreach ($contracts as $contract) {
                    $contract->refreshStatusFromExpiry();
                }
            });

        return self::SUCCESS;
    }
}
