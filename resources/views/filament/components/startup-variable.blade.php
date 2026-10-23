@php
    $statePath = $getStatePath();
    $isRequired = $isRequired();
    $isDisabled = $isDisabled();
    $type = $getType();
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <x-slot name="label">
        {{ $getLabel() }}
    </x-slot>

    <x-filament::input.wrapper
        :disabled="$isDisabled"
        :prefix="$getPrefixLabel()"
        :valid="! $errors->has($statePath)"
        :attributes="\Filament\Support\prepare_inherited_attributes($getExtraAttributeBag())->class([
            'fi-fo-text-input overflow-hidden' => $type === \App\Enums\StartupVariableType::Text,
            'fi-fo-select' => $type === \App\Enums\StartupVariableType::Select,
            'fi-fo-toggle p-1' => $type === \App\Enums\StartupVariableType::Toggle
        ])"
    >
        @if ($type === \App\Enums\StartupVariableType::Select)
            {{-- A dropdown saves the moment a choice is picked, not on blur: picking one and then
                 going to another page never left the box, so the choice was lost (LoomNode, 2026-10-22). --}}
            <x-filament::input.select
                :id="$getId()"
                :required="$isRequired"
                :disabled="$isDisabled"
                :attributes="
                    $getExtraInputAttributeBag()
                        ->merge([
                            str_replace('.blur', '', $applyStateBindingModifiers('wire:model')) => $statePath,
                        ], escape: false)
                "
            >
                @if (!$isRequired)
                    <option value="">
                        @if (!$isDisabled)
                            {{ trans('filament-forms::components.select.placeholder') }}
                        @endif
                    </option>
                @endif

                @foreach ($getSelectOptions() as $value)
                    <option value="{{ $value }}">
                        {{ $value }}
                    </option>
                @endforeach
            </x-filament::input.select>
        @elseif($type === \App\Enums\StartupVariableType::Toggle)
            <x-filament::toggle
                :id="$getId()"
                :required="$isRequired"
                :disabled="$isDisabled"
                :attributes="
                    $getExtraInputAttributeBag()
                        ->class(['ml-2'])
                        ->merge([
                            'state' => '$wire.' . $applyStateBindingModifiers('$entangle(\'' . $statePath . '\')'),
                        ], escape: false)
                "
            />
        @else
            <x-filament::input
                :id="$getId()"
                :required="$isRequired"
                :disabled="$isDisabled"
                :placeholder="$getPlaceholder()"
                :type="$type->value"
                :inputmode="$type === \App\Enums\StartupVariableType::Number ? 'numeric' : null"
                :max="$getMaxValue()"
                :min="$getMinValue()"
                :attributes="
                    \Filament\Support\prepare_inherited_attributes($getExtraInputAttributeBag())
                        ->merge($getExtraAlpineAttributes(), escape: false)
                        ->merge([
                            $applyStateBindingModifiers('wire:model') => $statePath,
                            // Livewire's page links cancel the mouse-down, so focus never left this box and
                            // the typed value was lost on the way to Console; let go of focus first, which
                            // saves it (LoomNode, 2026-10-23).
                            'x-on:mousedown.document.capture' => 'if (document.activeElement === $el && $event.target !== $el) $el.blur()',
                        ], escape: false)
                "
            />
        @endif
    </x-filament::input.wrapper>
</x-dynamic-component>
