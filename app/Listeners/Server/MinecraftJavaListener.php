<?php

namespace App\Listeners\Server;

use App\Events\Server\Installed;
use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * LoomNode (2026-11-13, Kyle: "yes switch java automatically"): after a Minecraft server installs, put it on the
 * Java image its Minecraft version needs. The egg's installer works that out (piston-meta javaVersion, or the
 * pack's Minecraft version) and writes java=N into .loomnode/install; a modpack switch can move a server from
 * Java 21 to 25 or back to 17, and the start script refuses to boot on the wrong one.
 *
 * Runs on the queue a few seconds after Wings reports the install, so Wings' own request isn't kept waiting
 * while the Panel reads a file from it. Only for eggs with the stage 2 Minecraft settings, only when the server
 * is on one of the egg's own images (an image an admin set by hand is left alone), and only on a good install.
 */
class MinecraftJavaListener
{
    public function handle(Installed $event): void
    {
        if (!$event->successful) {
            return;
        }

        $serverId = $event->server->id;
        dispatch(static fn () => MinecraftJavaListener::matchJava($serverId))->delay(now()->addSeconds(3));
    }

    public static function matchJava(int $serverId): void
    {
        $server = Server::query()->with('egg')->find($serverId);
        if (!$server) {
            return;
        }
        $env = $server->egg->variables()->pluck('env_variable')->all();
        if (!in_array('MC_MODS', $env, true) || !in_array('MC_MODPACK', $env, true)) {
            return;
        }

        $images = (array) $server->egg->docker_images;
        if (!in_array($server->image, $images, true)) {
            return;
        }

        try {
            $content = Container::getInstance()->make(DaemonFileRepository::class)->setServer($server)->getContent('.loomnode/install', 4096);
        } catch (Throwable) {
            return;
        }
        if (!preg_match('/^java=(\d{1,2})\s*$/m', $content, $m)) {
            return;
        }
        $need = (int) $m[1];
        preg_match('/^minecraft=([A-Za-z0-9._-]+)\s*$/m', $content, $mc);

        $image = self::imageFor($need, $images);
        if ($image === null || $image === $server->image) {
            return;
        }

        $old = $server->image;
        $server->forceFill(['image' => $image])->saveOrFail();
        Cache::forget('loomnode:install:' . $server->id);

        try {
            Activity::event('server:startup.image')
                ->subject($server)
                ->property(['old' => $old, 'new' => $image, 'reason' => 'Minecraft ' . ($mc[1] ?? '') . ' needs Java ' . $need])
                ->log();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The egg's image for Java N ("Java 21" => ...yolks:java_21), else the nearest newer one. Java 8 must be
     * exact: Forge for Minecraft 1.16 and older doesn't run on anything newer.
     *
     * @param  array<string, string>  $images  label => image
     */
    public static function imageFor(int $need, array $images): ?string
    {
        $byVersion = [];
        foreach ($images as $label => $image) {
            if (preg_match('/(?:^|\D)(\d{1,2})$/', (string) $label, $l) || preg_match('/java_?(\d{1,2})(?:\D|$)/i', (string) $image, $l)) {
                $byVersion[(int) $l[1]] ??= $image;
            }
        }
        if (isset($byVersion[$need])) {
            return $byVersion[$need];
        }
        if ($need <= 8) {
            return null;
        }
        ksort($byVersion);
        foreach ($byVersion as $version => $image) {
            if ($version > $need) {
                return $image;
            }
        }

        return null;
    }
}
