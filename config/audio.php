<?php

return [
    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    'loudness_target' => [
        'integrated_lufs' => -14,
        'true_peak_dbtp' => -1,
        'loudness_range_lu' => 11,
    ],
    'aac_bitrate' => '256k',
    'mp3_bitrate' => '320k',
    'sample_rate' => 48000,
    'max_duration_seconds' => 1800,
];
