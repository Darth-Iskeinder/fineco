@extends('docs.layout')

@section('doc-title', 'Справка по Kubik')

@section('doc-body')
    <h1 style="font-size:28px;margin:0 0 6px">Справка по Kubik</h1>
    <p class="lead">
        Здесь простыми словами написано, как работает система: откуда берутся задачи, как
        считается смета, кто какие компании видит. Без технических подробностей, только про
        то, что вы видите на экране.
    </p>
    <p class="lead">
        Читать подряд не нужно. Разделы названы так же, как пункты меню в системе: открывайте
        тот, в котором сейчас работаете. В конце разделов собраны частые вопросы и то, что
        обычно удивляет в первый раз, туда стоит заглянуть, если что-то повело себя неожиданно.
    </p>

    {{-- Порядок карточек и группа «Администрирование» повторяют левое меню системы,
         поэтому заголовок группы печатаем при первой карточке с таким признаком. --}}
    @php($shownGroup = null)

    <div class="cards">
        @foreach($sections as $slug => $s)
            @if(($s['group'] ?? null) !== $shownGroup)
                @php($shownGroup = $s['group'] ?? null)
                @if($shownGroup)
                    <p class="group">{{ $shownGroup }}</p>
                @endif
            @endif

            @if($s['ready'])
                <a class="item" href="{{ route('docs.section', $slug) }}">
                    <b>{{ $s['title'] }}</b>
                    <span>{{ $s['summary'] }}</span>
                </a>
            @else
                <div class="item off">
                    <b>{{ $s['title'] }} · скоро</b>
                    <span>{{ $s['summary'] }}</span>
                </div>
            @endif
        @endforeach
    </div>

    <p class="note">
        Разделы, помеченные «скоро», мы ещё пишем. Если вопрос срочный, спросите
        администратора вашей фирмы: он знает, как настроена система у вас.
    </p>
@endsection
