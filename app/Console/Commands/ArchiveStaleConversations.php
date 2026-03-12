<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;

/**
 * 将超过 7 天无新消息的活跃对话自动归档。
 * Run:      php artisan conversations:archive-stale
 * Schedule: daily via routes/console.php
 */
class ArchiveStaleConversations extends Command
{
    protected $signature   = 'conversations:archive-stale';
    protected $description = 'Archive active conversations with no messages in the last 7 days';

    public function handle(): void
    {
        $cutoff = now()->subDays(7);

        // 条件：活跃对话，且 last_message_at 超过 7 天前（或从未发过消息且建立超过 7 天）
        $stale = Conversation::where('status', 'active')
            ->where(function ($q) use ($cutoff) {
                $q->where('last_message_at', '<', $cutoff)
                  ->orWhere(function ($q2) use ($cutoff) {
                      $q2->whereNull('last_message_at')
                         ->where('created_at', '<', $cutoff);
                  });
            })
            ->get();

        if ($stale->isEmpty()) {
            $this->info('✓ No stale conversations to archive.');
            return;
        }

        $count = 0;
        foreach ($stale as $conv) {
            $conv->archive();
            $count++;
        }

        $this->info("✓ Archived {$count} stale conversation(s).");
    }
}
