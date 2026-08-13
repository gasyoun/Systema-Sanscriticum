@if(empty($items))
    <p class="text-sm text-gray-500">Каталог пуст — релиз ещё не импортирован.</p>
@else
    <ul class="space-y-3 overflow-x-hidden">
        @foreach($items as $item)
            @php($pid = $progressById[$item['id']] ?? null)
            <li>
                @if(!empty($preview))
                    <div class="px-4 py-3 rounded-xl bg-white border border-gray-200">
                        <p class="font-bold text-gray-900 break-words">{{ $item['title'] }}</p>
                        <p class="text-xs text-gray-500 mt-1">{{ $item['tier'] }}</p>
                    </div>
                @else
                    <a href="{{ route('student.visualdcs.show', [$surface, rawurlencode($item['id'])]) }}"
                       class="block px-4 py-3 rounded-xl bg-white border border-gray-200 hover:border-brand focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                        <p class="font-bold text-gray-900 break-words">{{ $item['title'] }}</p>
                        <p class="text-xs text-gray-500 mt-1">
                            {{ $item['tier'] }}
                            @if($pid)
                                · {{ $pid->status }}
                            @endif
                        </p>
                    </a>
                @endif
            </li>
        @endforeach
    </ul>
@endif
