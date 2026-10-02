<?php

use App\Models\Media;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete uploads never attached to an owner (and their files) after the retention window
// (config filesystems.media_orphan_hours). Needs the scheduler running: see docs/setup.md.
Schedule::command('model:prune', ['--model' => [Media::class]])->daily();
