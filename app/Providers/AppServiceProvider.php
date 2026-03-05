<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Resources\Json\JsonResource;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Enforce mass-assignment protection
        Model::preventLazyLoading(app()->isLocal());

        // Disable data wrapping on API resources
        JsonResource::withoutWrapping();
    }
}
