@props(['title', 'description' => null, 'stamped' => false])

{{--
    DEPRECATED — forwarding shim for `x-shell.nameplate`, which replaced it in the 2026-11-05 skin pass.
    All seven register indices were migrated in the same pass, so nothing in the app renders this file; it
    exists only so a page written against the old name still compiles while it is being migrated. Delete it
    when `grep -r "x-layout.masthead" resources/views` is empty, which it already is.

    The difference between the two is the register, not the shape: the title is signage (condensed display
    at 600) and everything under it is machine text in mono. docs/REDESIGN-CONCEPT.md §3 describes the
    masthead this replaced.
--}}
<x-shell.nameplate
    :title="$title"
    :description="$description"
    :stamped="$stamped"
    {{ $attributes }}
>
    @isset($action)
        <x-slot:action>{{ $action }}</x-slot:action>
    @endisset
</x-shell.nameplate>
