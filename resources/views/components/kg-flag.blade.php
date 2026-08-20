{{-- Флаг Кыргызстана: солнце с сорока лучами и решётка тюндюка.
     Рисуем сами, а не эмодзи 🇰🇬 — на Linux оно часто отдаётся буквами «KG». --}}
<svg viewBox="0 0 30 20" {{ $attributes->merge(['class' => 'w-5 h-[13px]']) }} aria-hidden="true">
    <rect width="30" height="20" fill="#E8112D"/>
    <g fill="#FFEF00">
        @for ($ray = 0; $ray < 40; $ray++)
            <path d="M15 2.6 L15.6 5.9 L14.4 5.9 Z" transform="rotate({{ $ray * 9 }} 15 10)"/>
        @endfor
        <circle cx="15" cy="10" r="4.3"/>
    </g>
    <g fill="none" stroke="#E8112D" stroke-width="0.6">
        <circle cx="15" cy="10" r="3.2"/>
        <g>
            <path d="M11.8 10 A 3.9 3.9 0 0 1 18.2 10"/>
            <path d="M11.8 10 A 3.9 3.9 0 0 0 18.2 10"/>
            <path d="M11.8 10 H 18.2"/>
        </g>
        <g transform="rotate(90 15 10)">
            <path d="M11.8 10 A 3.9 3.9 0 0 1 18.2 10"/>
            <path d="M11.8 10 A 3.9 3.9 0 0 0 18.2 10"/>
            <path d="M11.8 10 H 18.2"/>
        </g>
    </g>
</svg>
