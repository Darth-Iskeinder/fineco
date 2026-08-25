<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Рабочая справка, не витрина: в поиске ей делать нечего. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('doc-title', 'Документация') · Kubik</title>
    {{-- Стили инлайном, как на витрине: страница открыта без входа и не должна
         зависеть от сборки Vite: работает и на сервере, где npm run build не запускали. --}}
    <style>
        :root {
            --paper:  #f7f5fb;
            --card:   #ffffff;
            --ink:    #191325;
            --muted:  #6b6383;
            --rule:   #e6e1f2;
            --violet: #5b2bb5;
            --violet-soft: #f1ecfb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--paper);
            color: var(--ink);
            font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        a { color: var(--violet); }
        .wrap {
            max-width: 1080px;
            margin: 0 auto;
            padding: 32px 20px 80px;
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 32px;
            align-items: start;
        }
        @media (max-width: 800px) {
            .wrap { grid-template-columns: 1fr; gap: 20px; }
            .side { position: static !important; }
        }
        .brand {
            display: block;
            font-weight: 700;
            font-size: 18px;
            text-decoration: none;
            color: var(--ink);
            margin-bottom: 18px;
        }
        .brand span { color: var(--violet); }
        .side { position: sticky; top: 32px; }
        .nav { list-style: none; margin: 0; padding: 0; }
        .nav li { margin-bottom: 2px; }
        .nav a, .nav .soon {
            display: block;
            padding: 7px 10px;
            border-radius: 9px;
            font-size: 14px;
            text-decoration: none;
            color: var(--ink);
        }
        .nav a:hover { background: var(--violet-soft); }
        .nav a.on { background: var(--violet-soft); color: var(--violet); font-weight: 600; }
        .nav .soon { color: #a8a2bb; }
        .nav .soon:after { content: " · скоро"; font-size: 12px; }
        .card {
            background: var(--card);
            border: 1px solid var(--rule);
            border-radius: 18px;
            padding: 32px 36px;
        }
        @media (max-width: 800px) { .card { padding: 24px 20px; } }
        .stamp { color: var(--muted); font-size: 13px; margin: 0 0 24px; }
        .doc h1 { font-size: 28px; line-height: 1.25; margin: 0 0 6px; }
        .doc h2 {
            font-size: 20px;
            margin: 34px 0 10px;
            padding-top: 18px;
            border-top: 1px solid var(--rule);
        }
        .doc h2:first-of-type { border-top: 0; padding-top: 0; }
        .doc h3 { font-size: 16px; margin: 22px 0 6px; }
        .doc p, .doc li { color: #2c2540; }
        .doc ul, .doc ol { padding-left: 22px; }
        .doc li { margin: 5px 0; }
        .doc strong { color: var(--ink); }
        .doc blockquote {
            margin: 16px 0;
            padding: 12px 16px;
            background: var(--violet-soft);
            border-left: 3px solid var(--violet);
            border-radius: 0 10px 10px 0;
            color: #3a3153;
        }
        .doc blockquote p { margin: 0; }
        /* Скриншоты: во всю ширину колонки, с рамкой, чтобы белый интерфейс
           не сливался с белой карточкой страницы. */
        .doc img {
            display: block;
            max-width: 100%;
            height: auto;
            margin: 18px 0;
            border: 1px solid var(--rule);
            border-radius: 12px;
        }
        .doc table { border-collapse: collapse; width: 100%; margin: 16px 0; font-size: 15px; }
        .doc th, .doc td { border: 1px solid var(--rule); padding: 8px 12px; text-align: left; vertical-align: top; }
        .doc th { background: #faf8fe; font-weight: 600; }
        .doc code {
            background: #f3f0fa;
            border-radius: 5px;
            padding: 1px 5px;
            font-size: 14px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .lead { color: var(--muted); margin: 0 0 26px; }
        .cards { display: grid; gap: 12px; }
        .item {
            display: block;
            padding: 16px 18px;
            border: 1px solid var(--rule);
            border-radius: 14px;
            text-decoration: none;
            color: inherit;
            background: #fff;
        }
        .item:hover { border-color: var(--violet); }
        .item.off { background: #faf9fd; color: #a8a2bb; }
        .item b { display: block; font-size: 16px; margin-bottom: 3px; }
        .item span { font-size: 14px; color: var(--muted); }
        .item.off span { color: #b5b0c4; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="side">
            <a class="brand" href="{{ route('docs.index') }}">Kubik<span>·</span>док</a>
            <ul class="nav">
                @foreach($sections as $slug => $s)
                    @if($s['ready'])
                        <li>
                            <a href="{{ route('docs.section', $slug) }}"
                               class="{{ ($current ?? null) === $slug ? 'on' : '' }}">{{ $s['title'] }}</a>
                        </li>
                    @else
                        <li><span class="soon">{{ $s['title'] }}</span></li>
                    @endif
                @endforeach
            </ul>
        </div>

        <div class="card">
            @yield('doc-body')
        </div>
    </div>
</body>
</html>
