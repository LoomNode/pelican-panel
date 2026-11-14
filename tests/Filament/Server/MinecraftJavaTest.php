<?php

use App\Events\Server\Installed;
use App\Listeners\Server\MinecraftJavaListener;
use App\Models\Server;

require_once __DIR__ . '/MinecraftHelpers.php';

const JAVA_IMAGES = [
    'Java 25' => 'ghcr.io/pelican-eggs/yolks:java_25',
    'Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21',
    'Java 17' => 'ghcr.io/pelican-eggs/yolks:java_17',
    'Java 8' => 'ghcr.io/pelican-eggs/yolks:java_8',
];

function javaServer(string $image): Server
{
    [, $server] = generateTestAccount();
    $egg = cloneEggAndVariables($server->egg);
    $egg->forceFill(['docker_images' => JAVA_IMAGES])->save();
    $server->forceFill(['egg_id' => $egg->id, 'image' => $image])->save();

    return minecraftServer($server->fresh(['egg']));
}

it('puts a Minecraft server on the Java its install needs', function () {
    $server = javaServer('ghcr.io/pelican-eggs/yolks:java_21');
    installFile(str_replace(['minecraft=1.21.1', 'java=21'], ['minecraft=26.3', 'java=25'], FABRIC_INSTALL));

    (new MinecraftJavaListener())->handle(new Installed($server, true, false));

    expect($server->fresh()->image)->toBe('ghcr.io/pelican-eggs/yolks:java_25');
    $this->assertDatabaseHas('activity_logs', ['event' => 'server:startup.image']);
});

it('leaves the image alone when it already fits, after a failed install, and for an image set by hand', function () {
    $server = javaServer('ghcr.io/pelican-eggs/yolks:java_21');
    installFile(FABRIC_INSTALL);
    (new MinecraftJavaListener())->handle(new Installed($server, true, false));
    expect($server->fresh()->image)->toBe('ghcr.io/pelican-eggs/yolks:java_21');

    $failed = javaServer('ghcr.io/pelican-eggs/yolks:java_17');
    (new MinecraftJavaListener())->handle(new Installed($failed, false, false));
    expect($failed->fresh()->image)->toBe('ghcr.io/pelican-eggs/yolks:java_17');

    $custom = javaServer('example.com/custom:java_17');
    (new MinecraftJavaListener())->handle(new Installed($custom, true, false));
    expect($custom->fresh()->image)->toBe('example.com/custom:java_17');
});

it('does nothing for other games', function () {
    [, $server] = generateTestAccount();
    $image = $server->image;
    (new MinecraftJavaListener())->handle(new Installed($server, true, false));

    expect($server->fresh()->image)->toBe($image);
});

it('picks the exact Java, else the nearest newer one, but never a newer one for Java 8', function () {
    expect(MinecraftJavaListener::imageFor(21, JAVA_IMAGES))->toBe('ghcr.io/pelican-eggs/yolks:java_21')
        ->and(MinecraftJavaListener::imageFor(16, JAVA_IMAGES))->toBe('ghcr.io/pelican-eggs/yolks:java_17')
        ->and(MinecraftJavaListener::imageFor(8, JAVA_IMAGES))->toBe('ghcr.io/pelican-eggs/yolks:java_8')
        ->and(MinecraftJavaListener::imageFor(8, ['Java 17' => 'ghcr.io/pelican-eggs/yolks:java_17']))->toBeNull()
        ->and(MinecraftJavaListener::imageFor(30, JAVA_IMAGES))->toBeNull();
});
