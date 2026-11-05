{{-- Favicon of the platform: one folder (public/brand), emitted only for the files that exist, so nothing 404s before CCG supplies them. See README (Favicon). --}}
@foreach ([
    ['brand/favicon.svg', 'icon', 'image/svg+xml', null],
    ['brand/favicon.ico', 'icon', 'image/x-icon', '48x48'],
    ['brand/favicon.png', 'icon', 'image/png', '256x256'],
    ['brand/apple-touch-icon.png', 'apple-touch-icon', null, '180x180'],
    ['brand/favicon.png', 'apple-touch-icon', null, '256x256'],
] as [$file, $rel, $type, $sizes])
    @if (is_file(public_path($file)) && filesize(public_path($file)) > 0)
        <link rel="{{ $rel }}" href="{{ asset($file) }}?v={{ filemtime(public_path($file)) }}"@if ($type) type="{{ $type }}"@endif{!! $sizes ? ' sizes="'.e($sizes).'"' : '' !!}>
    @endif
@endforeach
