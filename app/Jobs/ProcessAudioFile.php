<?php

namespace App\Jobs;

use App\Models\AudioProcessing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class ProcessAudioFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 5400;

    public function __construct(public readonly int $audioProcessingId)
    {
    }

    public function handle(): void
    {
        $processing = AudioProcessing::query()->findOrFail($this->audioProcessingId);
        $disk = Storage::disk('local');
        $source = $disk->path($processing->source_path);
        $sourceInfo = $this->probe($source);
        $audioStream = collect($sourceInfo['streams'] ?? [])->first(
            fn (array $stream): bool => ($stream['codec_type'] ?? null) === 'audio',
        );
        $codec = is_array($audioStream) ? (string) ($audioStream['codec_name'] ?? '') : '';
        abort_unless(
            str_starts_with($codec, 'pcm_') || $codec === 'flac',
            422,
            'Only lossless WAV, AIFF, or FLAC audio masters are accepted.',
        );

        $duration = (float) ($sourceInfo['format']['duration'] ?? 0);
        abort_if($duration <= 0 || $duration > config('audio.max_duration_seconds'), 422, 'Audio duration is not supported.');

        $processing->update(['status' => 'processing', 'failure_code' => null]);
        $directory = 'audio-processed/'.$processing->id;
        $disk->makeDirectory($directory);

        $measurement = $this->analyzeLoudness($source);
        $aacPath = $directory.'/stream.m4a';
        $mp3Path = $directory.'/stream.mp3';
        $this->encode(
            $source,
            $disk->path($aacPath),
            'aac',
            (string) config('audio.aac_bitrate'),
            $measurement,
        );
        $this->encode(
            $source,
            $disk->path($mp3Path),
            'libmp3lame',
            (string) config('audio.mp3_bitrate'),
            $measurement,
        );

        abort_unless(
            is_file($disk->path($aacPath)) && filesize($disk->path($aacPath)) > 0
                && is_file($disk->path($mp3Path)) && filesize($disk->path($mp3Path)) > 0,
            500,
            'Audio encoding did not produce both output files.',
        );

        $processing->update([
            'aac_path' => $aacPath,
            'mp3_path' => $mp3Path,
            'status' => 'complete',
            'metadata' => [
                'source_codec' => $codec,
                'duration_seconds' => round($duration, 2),
                'sample_rate' => (int) config('audio.sample_rate'),
                'normalization' => [
                    'integrated_lufs' => (float) config('audio.loudness_target.integrated_lufs'),
                    'true_peak_dbtp' => (float) config('audio.loudness_target.true_peak_dbtp'),
                    'loudness_range_lu' => (float) config('audio.loudness_target.loudness_range_lu'),
                ],
            ],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        AudioProcessing::query()
            ->whereKey($this->audioProcessingId)
            ->whereNotIn('status', ['complete'])
            ->update(['status' => 'failed', 'failure_code' => 'audio.processing_failed']);
    }

    private function probe(string $source): array
    {
        $process = new Process([
            (string) config('audio.ffprobe'),
            '-v', 'error',
            '-show_entries', 'stream=codec_type,codec_name:format=duration',
            '-of', 'json',
            $source,
        ]);
        $process->setTimeout(60);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true);
        abort_unless(is_array($result), 422, 'Unable to inspect the audio file.');

        return $result;
    }

    private function analyzeLoudness(string $source): array
    {
        $process = new Process([
            (string) config('audio.ffmpeg'),
            '-hide_banner', '-nostats', '-i', $source,
            '-af',
            sprintf(
                'loudnorm=I=%s:TP=%s:LRA=%s:print_format=json',
                config('audio.loudness_target.integrated_lufs'),
                config('audio.loudness_target.true_peak_dbtp'),
                config('audio.loudness_target.loudness_range_lu'),
            ),
            '-f', 'null', '-',
        ]);
        $process->setTimeout(1800);
        $process->run();
        $stderr = $process->getErrorOutput();
        preg_match_all('/\{[^{}]*"input_i"[^{}]*\}/s', $stderr, $matches);
        $measurement = isset($matches[0]) ? json_decode(end($matches[0]) ?: '', true) : null;
        abort_unless(
            $process->isSuccessful() && is_array($measurement),
            422,
            'Loudness analysis failed for the supplied audio.',
        );

        return $measurement;
    }

    private function encode(
        string $source,
        string $destination,
        string $codec,
        string $bitrate,
        array $measurement,
    ): void {
        $filter = sprintf(
            'loudnorm=I=%s:TP=%s:LRA=%s:measured_I=%s:measured_TP=%s:measured_LRA=%s:measured_thresh=%s:offset=%s:linear=true:print_format=summary',
            config('audio.loudness_target.integrated_lufs'),
            config('audio.loudness_target.true_peak_dbtp'),
            config('audio.loudness_target.loudness_range_lu'),
            $this->number($measurement, 'input_i'),
            $this->number($measurement, 'input_tp'),
            $this->number($measurement, 'input_lra'),
            $this->number($measurement, 'input_thresh'),
            $this->number($measurement, 'target_offset'),
        );
        $process = new Process([
            (string) config('audio.ffmpeg'),
            '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $source,
            '-map', '0:a:0',
            '-vn',
            '-af', $filter,
            '-ar', (string) config('audio.sample_rate'),
            '-c:a', $codec,
            '-b:a', $bitrate,
            ...($codec === 'aac' ? ['-movflags', '+faststart'] : []),
            $destination,
        ]);
        $process->setTimeout(1800);
        $process->mustRun();
    }

    private function number(array $measurement, string $key): string
    {
        $value = $measurement[$key] ?? null;
        abort_unless(is_numeric($value), 422, 'Loudness analysis returned incomplete measurements.');

        return sprintf('%.6f', (float) $value);
    }
}
