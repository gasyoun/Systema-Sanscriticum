@extends('layouts.promo')

@section('content')
    <div class="builder-sections">
        @foreach($page->content as $block)
            @includeIf("promo.blocks.{$block['type']}", ['data' => $block['data']])
        @endforeach
    </div>
@endsection
