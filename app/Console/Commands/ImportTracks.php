<?php

namespace App\Console\Commands;

use App\Models\Track;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\File as FileSystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ImportTracks extends Command
{
    protected $signature = 'tracks:import
        {directory : Directory containing licensed MP3 files}
        {--confirm-rights : Confirm that you have rights to distribute these tracks}
        {--license-name=Rights confirmed by account owner : License or rights attribution to show in the catalog}';

    protected $description = 'Import rights-cleared MP3 files into the public music catalog';

    public function handle(): int
    {
        if (! $this->option('confirm-rights')) {
            $this->error('Confirm distribution rights with --confirm-rights before importing tracks.');

            return self::FAILURE;
        }

        $directory = $this->argument('directory');

        if (! FileSystem::isDirectory($directory)) {
            $this->error("Directory not found: {$directory}");

            return self::FAILURE;
        }

        $files = array_values(array_filter(
            FileSystem::files($directory),
            fn (\SplFileInfo $file): bool => strtolower($file->getExtension()) === 'mp3',
        ));

        if ($files === []) {
            $this->error("No MP3 files found in: {$directory}");

            return self::FAILURE;
        }

        $tracks = [];

        foreach ($files as $file) {
            $hash = hash_file('sha256', $file->getPathname());

            if ($hash === false) {
                $this->error("Could not read file: {$file->getFilename()}");

                return self::FAILURE;
            }

            $process = new Process([
                config('audio.ffprobe'),
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $file->getPathname(),
            ]);
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->error("Could not read audio duration for {$file->getFilename()}: ".$process->getErrorOutput());

                return self::FAILURE;
            }

            $duration = filter_var(trim($process->getOutput()), FILTER_VALIDATE_FLOAT);

            if ($duration === false || $duration <= 0) {
                $this->error("No valid audio duration found for {$file->getFilename()}.");

                return self::FAILURE;
            }

            $tracks[] = [
                'file' => $file,
                'hash' => $hash,
                'duration_seconds' => (int) round($duration),
            ];
        }

        $disk = Storage::disk('public');
        $created = 0;
        $updated = 0;

        foreach ($tracks as $item) {
            /** @var \SplFileInfo $file */
            $file = $item['file'];
            $audioPath = "tracks/imported/{$item['hash']}.mp3";
            $existing = Track::query()->where('audio_path', $audioPath)->exists();

            $disk->putFileAs('tracks/imported', new File($file->getPathname()), "{$item['hash']}.mp3");

            Track::updateOrCreate(
                ['audio_path' => $audioPath],
                [
                    'title' => pathinfo($file->getFilename(), PATHINFO_FILENAME),
                    'artist' => 'Unknown artist',
                    'duration_seconds' => $item['duration_seconds'],
                    'source' => 'Local import',
                    'license_name' => $this->option('license-name'),
                    'downloadable' => true,
                    'metadata' => [
                        'original_filename' => $file->getFilename(),
                        'sha256' => $item['hash'],
                    ],
                ],
            );

            $existing ? $updated++ : $created++;
        }

        $this->info("Imported {$created} track(s); updated {$updated} existing track(s).");

        return self::SUCCESS;
    }
}
