<footer class="flex flex-col items-center justify-center text-center space-y-2 p-4 text-gray-600 dark:text-gray-400">
    {{ \Filament\Support\Facades\FilamentView::renderHook(\App\Enums\CustomRenderHooks::FooterStart->value) }}

    <a class="font-semibold" href="https://pelican.dev/docs/#core-team" target="_blank">
        &copy; {{ date('Y') }} {{ $appName }}
    </a>

    {{-- AGPL-3.0 section 13: offer this Panel's source to everyone who uses it. The release
         folder's LOOMNODE_BUILD ("<release id> <fork commit>") names the exact build. --}}
    @php
        $sourceUrl = rtrim(config('app.source_url'), '/');
        $releaseFile = base_path('LOOMNODE_BUILD');
        $release = is_readable($releaseFile) ? strtok(trim((string) file_get_contents($releaseFile)), " \n") : null;
        if ($release && preg_match('/^[A-Za-z0-9._-]+$/', $release)) {
            $sourceUrl .= '/tree/' . $release;
        }
    @endphp
    <a class="text-xs underline" href="{{ $sourceUrl }}" target="_blank" rel="noopener">
        Source code of this Panel (AGPL-3.0)
    </a>

    @if(config('app.debug'))
        <div class="flex space-x-1 text-xs">
            <x-filament::icon
                :icon="'tabler-clock'"
                @class(['w-4 h-4 text-gray-500 dark:text-gray-400'])
            />
            <span>{{ round(microtime(true) - LARAVEL_START, 3) }}s</span>
        </div>
    @endif

    {{ \Filament\Support\Facades\FilamentView::renderHook(\App\Enums\CustomRenderHooks::FooterEnd->value) }}
</footer>
