<?php
    // Проверяем, есть ли блоки в конструкторе
    $useBuilder = !empty($page->content) && is_array($page->content) && count($page->content) > 0;
?>

@if($useBuilder)
    
    {{-- === НОВЫЙ РЕЖИМ (КОНСТРУКТОР) === --}}
    @extends('layouts.promo') 

    @section('content')
        <div class="builder-sections">
            @foreach($page->content as $block)
                {{-- Подключаем блок. Например: promo.blocks.hero_block --}}
                @includeIf("promo.blocks.{$block['type']}", ['data' => $block['data']])
            @endforeach
        </div>
    @endsection

@else
    
    {{-- === СТАРЫЙ РЕЖИМ (LEGACY) === --}}
    @include('promo.legacy', ['page' => $page])

@endif