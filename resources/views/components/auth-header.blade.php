@props(['title', 'description' => null])

<div class="grid gap-2 text-center">
    <x-ui.heading :size="$description ? 'lg' : 'xl'" level="1">{{ $title }}</x-ui.heading>

    @if($description)
        <x-ui.subheading>{{ $description }}</x-ui.subheading>
    @endif
</div>
