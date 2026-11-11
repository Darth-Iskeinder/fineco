{{-- Список открытых задач в карточке месяца. Ждёт $tasks, $today, $short, $taskState. --}}
<ul class="mt-3 space-y-2 pl-8">
    @foreach ($tasks as $task)
        @php $late = $task['due'] && $task['due']->lt($today); @endphp
        <li class="text-sm">
            <div class="font-medium text-slate-900">{{ $task['name'] }}</div>
            <div @class(['text-[13px]', 'text-red-700' => $late, 'text-slate-500' => !$late])>
                {{ $short($task['assignee']) }} · {{ $taskState($task['log']) }}@if ($task['due']), срок {{ $late ? 'был ' : '' }}{{ $task['due']->format('d.m') }}@endif
            </div>
        </li>
    @endforeach
</ul>
