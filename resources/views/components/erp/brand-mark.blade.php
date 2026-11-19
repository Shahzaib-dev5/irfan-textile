@props(['size' => 40, 'alt' => 'IB Fashion'])

<img src="{{ asset('branding/ib-fashion-icon.png') }}" alt="{{ $alt }}"
    width="{{ $size }}" height="{{ $size }}"
    {{ $attributes->class(['shrink-0 rounded-lg object-contain']) }}>
