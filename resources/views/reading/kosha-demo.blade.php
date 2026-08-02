@extends('layouts.slovar')

@section('title', $pack['title'].' — чтение с разбором | Общество ревнителей санскрита')
@section('meta_description', 'Санскритский текст построчно с разбором каждого слова: лемма, морфология, значение.')
@section('robots', 'noindex, follow')

@section('content')
    @include('reading.partials.pack')
@endsection
