@props(['name', 'field', 'value' => null, 'options' => [], 'readOnly' => false, 'help' => null, 'locked' => false])
@php
    $parts = explode('.', $name);
    $inputName = array_shift($parts).implode('', array_map(fn($part) => '['.$part.']', $parts));
    $current = ($readOnly || $locked) ? $value : old($name, $value);
    $inputId = 'field-'.str_replace('.', '-', $name);
@endphp
<div class="{{ $field[1] === 'textarea' ? 'sm:col-span-2' : '' }}">
    @if($locked && !$readOnly)<input type="hidden" name="{{ $inputName }}" value="{{ $value }}">@endif
    @if($field[1] === 'checkbox')
        <label for="{{ $inputId }}" class="flex min-h-11 items-center gap-3 text-sm"><input type="hidden" name="{{ $inputName }}" value="0" @disabled($readOnly || $locked)><input id="{{ $inputId }}" type="checkbox" name="{{ $inputName }}" value="1" @checked($current) @disabled($readOnly || $locked) class="rounded border-line text-brand">{{ $field[0] }}</label>
    @else
        <label for="{{ $inputId }}" class="block text-sm font-medium text-slate-700">{{ $field[0] }}</label>
        @if($field[1] === 'select')
            <select id="{{ $inputId }}" name="{{ $inputName }}" @if($help) aria-describedby="{{ $inputId }}-help" @endif @disabled($readOnly || $locked) class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm disabled:bg-slate-50"><option value="">Select…</option>@foreach($options as $key => $option)<option value="{{ $key }}" @selected((string)$current === (string)$key)>{{ $option }}</option>@endforeach</select>
        @elseif($field[1] === 'textarea')
            <textarea id="{{ $inputId }}" name="{{ $inputName }}" rows="3" @disabled($readOnly || $locked) class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm disabled:bg-slate-50">{{ $current }}</textarea>
        @else
            <input id="{{ $inputId }}" type="{{ $field[1] }}" name="{{ $inputName }}" value="{{ $current }}" @disabled($readOnly || $locked) @if($field[1] === 'number') step="any" min="0" @endif @if($name === 'season') list="collection-labels" @endif class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm disabled:bg-slate-50">
        @endif
    @endif
    @if($help)<p class="mt-1.5 text-xs leading-5 text-slate-500" id="{{ $inputId }}-help">{{ $help }}</p>@endif
    @error($name)<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
</div>
