@extends('layouts.shop')
@section('title', 'Проверьте почту — мини-курс санскрита')

@section('content')
<div class="min-h-[70vh] bg-gray-50 py-10 sm:py-16 px-4">
    <div class="max-w-lg mx-auto bg-white p-6 sm:p-8 rounded-3xl shadow-sm border border-gray-100 text-center">
        <div class="text-4xl mb-4">📬</div>
        <h1 class="text-2xl font-extrabold text-gray-900">Проверьте почту</h1>
        <p class="mt-3 text-gray-600">
            С этим адресом уже есть личный кабинет. Мы отправили письмо со ссылкой:
            нажмите её, и курс «{{ $course->title }}» откроется в вашем кабинете.
        </p>
        <p class="mt-4 text-sm text-gray-500">
            Письма нет? Загляните в «Спам» или <a href="{{ route('login') }}" class="text-orange-600 hover:underline">войдите по паролю</a>
            и вернитесь на <a href="{{ route('mini-course.quick') }}" class="text-orange-600 hover:underline">эту страницу</a>.
        </p>
    </div>
</div>
@endsection
