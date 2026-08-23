@extends('docs.layout')

@section('doc-title', 'Документация Kubik')

@section('doc-body')
    <h1 style="font-size:28px;margin:0 0 6px">Как работает Kubik</h1>
    <p class="lead">
        Описание бизнес-логики системы: что происходит и почему, без технических подробностей.
        Написано, чтобы не поднимать код ради вопроса «а как это считалось».
    </p>

    <div class="cards">
        @foreach($sections as $slug => $s)
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
@endsection
