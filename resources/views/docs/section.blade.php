@extends('docs.layout')

@section('doc-title', $meta['title'])

@section('doc-body')
    <div class="doc">{!! $body !!}</div>
@endsection
