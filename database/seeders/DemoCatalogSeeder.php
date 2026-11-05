<?php

namespace Database\Seeders;

use App\Models\Track;
use Illuminate\Database\Seeder;

class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $tracks = [
            [
                'title' => 'Không Môn Đăng Hộ Đối',
                'artist' => 'Nguyễn Vĩ',
                'duration_seconds' => 377,
            ],
            [
                'title' => 'Nonstop 2026 Bay Phòng Bass Cực Mạnh — Nhạc Sàn Vinahouse DJ Mixtape 2026 — P3',
                'artist' => 'Unknown artist',
                'duration_seconds' => 4281,
            ],
            [
                'title' => 'Đến Khi Nào Remix (Bản Hot TikTok) — Anh Chỉ Lo Anh Ôm Em Chặt Quá Remix Hot TikTok 2026',
                'artist' => 'Unknown artist',
                'duration_seconds' => 1094,
            ],
        ];

        foreach ($tracks as $track) {
            Track::query()->firstOrCreate(
                ['title' => $track['title'], 'artist' => $track['artist']],
                [
                    ...$track,
                    'audio_path' => null,
                    'source' => 'Render demo catalog',
                    'license_name' => 'Audio must be uploaded separately to enable playback.',
                    'downloadable' => false,
                    'is_active' => true,
                ],
            );
        }
    }
}
