<?php

namespace App\Support;

use App\Models\ApiMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $code = 'common.ok',
        int $status = 200,
        ?array $meta = null,
    ): JsonResponse {
        $body = [
            'success' => true,
            'message' => ['code' => $code, 'text' => self::messageText($code)],
            'data' => $data,
        ];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    public static function error(
        string $code,
        int $status,
        ?array $errors = null,
    ): JsonResponse {
        $body = [
            'success' => false,
            'message' => ['code' => $code, 'text' => self::messageText($code)],
            'data' => null,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }

    public static function messageText(string $code): string
    {
        $locale = App::currentLocale();

        try {
            return Cache::remember(
                "api-message:{$locale}:{$code}",
                now()->addMinutes(5),
                fn (): string => ApiMessage::query()
                    ->where('code', $code)
                    ->where('locale', $locale)
                    ->value('text') ?? $code,
            );
        } catch (QueryException $exception) {
            report($exception);

            return $code;
        }
    }
}
