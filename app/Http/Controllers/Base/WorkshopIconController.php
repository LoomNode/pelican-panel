<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Steam Workshop pictures for the Don't Starve Together Mods page, fetched by the Panel (LoomNode,
 * 2026-11-25), so a customer's browser never talks to Steam just by opening the page. Only pictures,
 * only from images.steamusercontent.com/ugc/, asked for small, and the browser keeps them for a week.
 */
class WorkshopIconController extends Controller
{
    private const TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    private const MAX_BYTES = 1024 * 1024;

    public function __invoke(string $file, string $hash): Response
    {
        try {
            $answer = Http::withUserAgent('Mozilla/5.0 (compatible; mod-browser)')
                ->timeout(6)
                ->withoutRedirecting()
                ->get('https://images.steamusercontent.com/ugc/' . $file . '/' . $hash . '/', ['imw' => 96, 'imh' => 96, 'ima' => 'fit', 'impolicy' => 'Letterbox']);
        } catch (Throwable) {
            abort(404);
        }

        $type = strtolower(trim(explode(';', (string) $answer->header('Content-Type'))[0]));
        $body = $answer->body();
        abort_unless($answer->status() === 200 && in_array($type, self::TYPES, true) && strlen($body) <= self::MAX_BYTES, 404);

        return response($body, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
