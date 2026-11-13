<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mod pictures for the Mods page, fetched from Modrinth's file server by the Panel (LoomNode, 2026-11-13),
 * so a customer's browser never talks to Modrinth just by opening the page. Only pictures, only from
 * cdn.modrinth.com/data/, and the browser keeps them for a week.
 */
class ModIconController extends Controller
{
    private const TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    private const MAX_BYTES = 600 * 1024;

    public function __invoke(string $project, string $file): Response
    {
        try {
            $answer = Http::withUserAgent('LoomNode/1.0 (support@loomnode.cloud)')
                ->timeout(6)
                ->withoutRedirecting()
                ->get('https://cdn.modrinth.com/data/' . $project . '/' . $file);
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
