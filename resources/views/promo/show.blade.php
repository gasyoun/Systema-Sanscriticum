@extends('layouts.promo')

@section('content')
    {{-- BreadcrumbList (schema.org) — Главная → текущий лендинг. JSON-LD в body валиден. --}}
    @include('partials.schema-breadcrumbs', ['crumbs' => [
        ['name' => 'Главная', 'url' => url('/')],
        ['name' => $page?->title],
    ]])

    <div class="builder-sections">
        @foreach($page->content as $block)
            @includeIf("promo.blocks.{$block['type']}", ['data' => $block['data']])
        @endforeach
    </div>
@endsection
