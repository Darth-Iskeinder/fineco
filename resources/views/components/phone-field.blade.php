{{--
    Поле кыргызского номера: код страны — подпись у рамки, а не поле.

    Вводятся девять национальных цифр, маска расставляет пробелы. Вставку из
    мессенджера (+996, ведущий ноль) она приводит к тем же девяти цифрам, а не
    отбивает ошибкой — это один и тот же номер.

    Два режима:
      - обычная форма: <x-phone-field name="phone" :value="old('phone')" />
      - поле внутри Alpine-формы: <x-phone-field model="form.info.phone" />
--}}
@props([
    'name' => 'phone',
    'id' => null,
    'value' => null,
    'model' => null,
])

<div class="relative"
     x-data="{
         mask(value) {
             let digits = String(value ?? '').replace(/\D/g, '');

             // Лишнее в начале узнаём по длине, а не по самим цифрам: у операторов
             // есть коды 99X, и «996 123 456» — это номер целиком, а не код страны
             // с остатком.
             if (digits.length > 9 && digits.startsWith('996')) digits = digits.slice(3);
             if (digits.length > 9 && digits.startsWith('0')) digits = digits.slice(1);
             digits = digits.slice(0, 9);

             return [digits.slice(0, 3), digits.slice(3, 6), digits.slice(6, 9)]
                 .filter(Boolean)
                 .join(' ');
         }
     }"
     @if(!$model) x-init="$refs.input.value = mask($refs.input.value)" @endif>
    <div class="absolute inset-y-2 left-0 flex items-center gap-2 pl-4 pr-3 border-r border-slate-200 pointer-events-none">
        <x-kg-flag class="w-5 h-[13px] rounded-sm shrink-0" />
        <span class="text-slate-500 select-none">+996</span>
    </div>

    <input type="tel"
           x-ref="input"
           inputmode="numeric"
           autocomplete="tel-national"
           placeholder="779 779 979"
           @if($model)
               :value="mask({{ $model }})"
               @input="{{ $model }} = $event.target.value = mask($event.target.value)"
           @else
               name="{{ $name }}"
               id="{{ $id ?? $name }}"
               value="{{ $value }}"
               @input="$event.target.value = mask($event.target.value)"
           @endif
           {{ $attributes->merge(['class' => 'block w-full pl-[100px] pr-4 py-3 border border-slate-200 rounded-xl text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200']) }}>
</div>
