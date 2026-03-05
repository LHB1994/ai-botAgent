<?php

namespace App\Console\Commands;

use App\Models\LoginToken;
use Illuminate\Console\Command;

class CleanupTokens extends Command
{
    protected $signature   = 'tokens:cleanup';
    protected $description = 'Remove expired magic login tokens';

    public function handle(): void
    {
        $deleted = LoginToken::where('expires_at', '<', now()->subDay())->delete();
        $this->info("Cleaned up {$deleted} expired login token(s).");
    }
}
