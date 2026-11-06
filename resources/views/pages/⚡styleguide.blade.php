<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The design-system showcase (docs/component-ui.md Phase 2.1): every primitive, every variant,
 * every state on one page, so a redesign pass is verified by eye against one surface rather than
 * guessed at across the app. Local and staging only — production answers 404, and the page says
 * so at its own door rather than relying on the route.
 */
new #[Title('Styleguide')] class extends Component {
    public function mount(): void
    {
        abort_unless(in_array(app()->environment(), ['local', 'staging', 'testing'], true), 404);
    }
} ?>

<div class="mx-auto grid max-w-5xl gap-10 px-4 py-8">
    <x-layout.page-header
        :title="__('Styleguide')"
        :description="__('Every component, every variant, every state. The contract lives in docs/DESIGN-SYSTEM.md.')"
        :breadcrumbs="[['label' => __('Fleet Guard'), 'href' => route('dashboard')], ['label' => __('Styleguide')]]"
    >
        <x-slot:action>
            <x-ui.button variant="primary" icon="plus">{{ __('New trip') }}</x-ui.button>
        </x-slot:action>
    </x-layout.page-header>

    <section class="grid gap-3">
        <x-ui.heading level="2">{{ __('Page header + toolbar') }}</x-ui.heading>

        <x-layout.toolbar>
            <x-ui.segmented-control :options="['board' => 'Board', 'list' => 'List', 'timeline' => 'Timeline']" value="board" :label="__('View')" />
            <x-ui.input name="sg-search" placeholder="{{ __('Search') }}" class="w-56" />
            <x-ui.button variant="outline">{{ __('Filter') }}</x-ui.button>
        </x-layout.toolbar>
    </section>

    {{-- Buttons: all variants, all sizes, all states. --}}
    <section class="grid gap-3">
        <x-ui.heading level="2">{{ __('Button') }}</x-ui.heading>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button variant="primary">{{ __('Primary') }}</x-ui.button>
            <x-ui.button variant="secondary">{{ __('Secondary') }}</x-ui.button>
            <x-ui.button variant="outline">{{ __('Outline') }}</x-ui.button>
            <x-ui.button variant="ghost">{{ __('Ghost') }}</x-ui.button>
            <x-ui.button variant="link">{{ __('Link') }}</x-ui.button>
            <x-ui.button variant="danger">{{ __('Danger') }}</x-ui.button>
            <x-ui.button variant="destructive">{{ __('Destructive') }}</x-ui.button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button size="xs">{{ __('xs') }}</x-ui.button>
            <x-ui.button size="sm">{{ __('sm') }}</x-ui.button>
            <x-ui.button size="md">{{ __('md') }}</x-ui.button>
            <x-ui.button size="lg">{{ __('lg') }}</x-ui.button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button variant="primary" loading>{{ __('Loading') }}</x-ui.button>
            <x-ui.button variant="primary" disabled>{{ __('Disabled') }}</x-ui.button>
            <x-ui.button variant="outline" icon="plus">{{ __('With icon') }}</x-ui.button>
            <x-ui.button variant="outline" icon-trailing="chevron-right">{{ __('Trailing') }}</x-ui.button>
            <x-ui.button href="#" variant="primary">{{ __('As link') }}</x-ui.button>
        </div>
    </section>

    {{-- Badges: one per tone, both sizes. --}}
    <section class="grid gap-3">
        <x-ui.heading level="2">{{ __('Badge') }}</x-ui.heading>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.badge tone="neutral">{{ __('Neutral') }}</x-ui.badge>
            <x-ui.badge tone="success">{{ __('Success') }}</x-ui.badge>
            <x-ui.badge tone="warning">{{ __('Warning') }}</x-ui.badge>
            <x-ui.badge tone="danger">{{ __('Danger') }}</x-ui.badge>
            <x-ui.badge tone="info">{{ __('Info') }}</x-ui.badge>
            <x-ui.badge tone="accent">{{ __('Accent') }}</x-ui.badge>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.badge tone="danger" size="xs">{{ __('xs') }}</x-ui.badge>
            <x-ui.badge tone="danger">{{ __('sm') }}</x-ui.badge>
            <x-ui.badge tone="warning" icon="clock">{{ __('Custom icon') }}</x-ui.badge>
        </div>
    </section>

    {{-- Alerts: all tones, with and without a title. --}}
    <section class="grid gap-3">
        <x-ui.heading level="2">{{ __('Alert') }}</x-ui.heading>

        <x-ui.alert tone="info" :title="__('Info')">{{ __('A neutral notice; danger tones announce with role=alert.') }}</x-ui.alert>
        <x-ui.alert tone="success" :title="__('Success')">{{ __('Something completed.') }}</x-ui.alert>
        <x-ui.alert tone="warning" :title="__('Warning')">{{ __('Something needs attention.') }}</x-ui.alert>
        <x-ui.alert tone="danger" :title="__('Danger')">{{ __('Something failed or is overdue.') }}</x-ui.alert>
        <x-ui.alert>{{ __('An alert without a title.') }}</x-ui.alert>
    </section>

    {{-- Form fields: one contract — label, hint, error, disabled. --}}
    <section class="grid gap-3">
        <x-ui.heading level="2">{{ __('Fields') }}</x-ui.heading>

        <div class="grid gap-4 md:grid-cols-2">
            <x-ui.input :label="__('Rest')" name="sg-rest" placeholder="—" />
            <x-ui.input :label="__('With hint')" name="sg-hint" hint="{{ __('Helper text lives here.') }}" />
            <x-ui.input :label="__('In error')" name="sg-error" :error="__('This value is not a plate.')" />
            <x-ui.input :label="__('Disabled')" name="sg-disabled" disabled />
            <x-ui.input :label="__('Money (numeric)')" name="sg-money" numeric suffix="BWP" value="1 284,00" />
            <x-ui.input :label="__('Password')" name="sg-pass" type="password" viewable value="secret" />
            <x-ui.select :label="__('Select (native)')" name="sg-select" :options="['a' => 'Option A', 'b' => 'Option B']" :widget="false" />
            <x-ui.textarea :label="__('Textarea')" name="sg-text" rows="3" />
            <x-ui.checkbox name="sg-check" :label="__('Checkbox')" checked />
            <x-ui.otp name="sg-otp" :label="__('One-time code')" />
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <x-ui.radio.group>
                <x-ui.radio.item name="sg-theme" value="light" :label="__('Light')" />
                <x-ui.radio.item name="sg-theme" value="dark" :label="__('Dark')" />
            </x-ui.radio.group>

            {{-- The view switcher: tablist semantics, arrow keys, one tab stop. `model` names the
                 Livewire property; with #[Url] on the page it drives ?view=board|list|timeline. --}}
            <div class="flex items-center gap-3">
                <x-ui.segmented-control :options="['board' => 'Board', 'list' => 'List', 'timeline' => 'Timeline']" value="board" :label="__('View')" />
                <x-ui.segmented-control :options="[['label' => 'Day', 'icon' => 'sun'], 'Week', 'Month']" value="Week" :label="__('Range')" />
            </div>
        </div>
    </section>

    {{-- Text roles. --}}
    <section class="grid gap-3">
        <x-ui.heading level="2">{{ __('Text') }}</x-ui.heading>

        <div class="grid gap-1">
            <x-ui.text color="ink" size="base">{{ __('Ink, base') }}</x-ui.text>
            <x-ui.text>{{ __('Secondary (default), sm') }}</x-ui.text>
            <x-ui.text color="muted">{{ __('Muted') }}</x-ui.text>
            <x-ui.text color="accent">{{ __('Accent') }}</x-ui.text>
            <x-ui.text color="success">{{ __('Success') }}</x-ui.text>
            <x-ui.text color="warning">{{ __('Warning') }}</x-ui.text>
            <x-ui.text color="danger">{{ __('Danger') }}</x-ui.text>
            <x-ui.text size="xs">{{ __('xs') }}</x-ui.text>
        </div>

        <p class="text-sm leading-5">
            {{ __('An inline') }}
            <x-ui.link href="#">{{ __('accent link') }}</x-ui.link>
            {{ __('inside body text keeps its underline.') }}
        </p>
    </section>

    {{-- Cards and composites. --}}
    <section class="grid gap-3">
        <x-ui.heading level="2">{{ __('Card') }}</x-ui.heading>

        <div class="grid gap-4 md:grid-cols-2">
            <x-ui.card :title="__('Plain card')" :description="__('With a description line.')">
                <x-ui.text>{{ __('Body content sits in the default slot.') }}</x-ui.text>
            </x-ui.card>

            <x-ui.card :title="__('With header and footer')">
                <x-slot:header>
                    <x-ui.link href="#">{{ __('Open the register') }}</x-ui.link>
                </x-slot:header>

                <x-ui.text>{{ __('Header slot on the right of the title.') }}</x-ui.text>

                <x-slot:footer>
                    <x-ui.button size="sm">{{ __('Save') }}</x-ui.button>
                </x-slot:footer>
            </x-ui.card>

            <x-ui.metric-card :label="__('Receivables')" figure="P 412 800,00" :caption="__('3 invoices overdue')" href="#" />
            <x-ui.metric-card :label="__('Gated metric')" :gated="true" gate="view-ledger" />

            <x-ui.figure :label="__('Net cash position')" value="P 1 284 320,00" :caption="__('Across 3 banks, at 14:20')" />
            <x-ui.threshold :label="__('Documents expiring')" value-text="12 of 30" :value="12" :limit="30" :limit-text="__('window: 30 days')" />
            <x-ui.threshold :label="__('Unmeasurable')" value-text="—" />
        </div>
    </section>

    <section class="grid gap-3">
        <x-ui.heading level="2">{{ __('Pipeline') }}</x-ui.heading>

        <x-ui.pipeline :label="__('Trip stages')" :steps="[
            ['label' => __('Enquiry'), 'figure' => '4', 'word' => __('waiting'), 'caption' => __('No quote issued yet')],
            ['label' => __('Quoted'), 'figure' => '2', 'word' => __('priced'), 'caption' => __('Awaiting customer sign-off')],
            ['label' => __('Planned'), 'figure' => '3', 'word' => __('committed'), 'tone' => 'info'],
            ['label' => __('On the road'), 'figure' => '7', 'word' => __('running'), 'tone' => 'success'],
            ['label' => __('Proof owed'), 'figure' => '5', 'word' => __('blocked'), 'tone' => 'warning', 'caption' => __('Delivery note not attached')],
        ]" />
    </section>

    {{-- Overlays. --}}
    <section class="grid gap-3">
        <x-ui.heading level="2">{{ __('Dialog, dropdown, tooltip') }}</x-ui.heading>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button variant="primary" x-on:click="$dispatch('open-sg-center')">{{ __('Open dialog') }}</x-ui.button>
            <x-ui.button x-on:click="$dispatch('open-sg-drawer')">{{ __('Open drawer') }}</x-ui.button>

            <x-ui.dropdown name="sg-menu" :label="__('Dropdown')">
                <x-slot:trigger>
                    <x-ui.button variant="outline" icon-trailing="chevron-down">{{ __('Dropdown') }}</x-ui.button>
                </x-slot:trigger>

                <x-ui.menu>
                    <x-ui.menu.item href="#">{{ __('First action') }}</x-ui.menu.item>
                    <x-ui.menu.item href="#">{{ __('Second action') }}</x-ui.menu.item>
                    <x-ui.menu.separator />
                    <x-ui.menu.item href="#" tone="danger">{{ __('Destructive action') }}</x-ui.menu.item>
                </x-ui.menu>
            </x-ui.dropdown>

            <x-ui.tooltip :text="__('A metric definition, keyboard-reachable.')">
                <x-ui.badge tone="info">{{ __('Hover or focus me') }}</x-ui.badge>
            </x-ui.tooltip>
        </div>

        <x-ui.dialog name="sg-center" :title="__('A centered dialog')">
            <x-ui.text>{{ __('Native dialog: focus trapped, Escape closes.') }}</x-ui.text>

            <x-slot:footer>
                <x-ui.button x-on:click="$dispatch('close-sg-center')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="primary">{{ __('Confirm') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.dialog>

        <x-ui.dialog name="sg-drawer" :title="__('A side drawer')" variant="drawer">
            <x-ui.text>{{ __('The drawer variant — excluded from the redesign, shown here to prove it still works.') }}</x-ui.text>
        </x-ui.dialog>
    </section>

    {{-- Table. --}}
    <section class="grid gap-3">
        <x-ui.heading level="2">{{ __('Table') }}</x-ui.heading>

        <x-ui.table :caption="__('Sample register')">
            <x-slot:head>
                <x-ui.table.th>{{ __('Vehicle') }}</x-ui.table.th>
                <x-ui.table.th :sortable="true" direction="asc">{{ __('Driver') }}</x-ui.table.th>
                <x-ui.table.th numeric>{{ __('Distance') }}</x-ui.table.th>
                <x-ui.table.th hide-below="lg">{{ __('Status') }}</x-ui.table.th>
            </x-slot:head>

            <tr>
                <x-ui.table.td identifier>B1234ABC</x-ui.table.td>
                <x-ui.table.td>{{ __('K. Motswase') }}</x-ui.table.td>
                <x-ui.table.td numeric>1 842,3 km</x-ui.table.td>
                <x-ui.table.td hide-below="lg"><x-ui.badge tone="success">{{ __('On route') }}</x-ui.badge></x-ui.table.td>
            </tr>
            <tr>
                <x-ui.table.td identifier>B7788KLT</x-ui.table.td>
                <x-ui.table.td>{{ __('D. Phiri') }}</x-ui.table.td>
                <x-ui.table.td numeric>412,0 km</x-ui.table.td>
                <x-ui.table.td hide-below="lg"><x-ui.badge tone="warning">{{ __('Border queue') }}</x-ui.badge></x-ui.table.td>
            </tr>
        </x-ui.table>
    </section>

    {{-- States: empty, skeleton, gated, avatars. --}}
    <section class="grid gap-3">
        <x-ui.heading level="2">{{ __('Empty, skeleton, avatar') }}</x-ui.heading>

        <div class="grid gap-4 md:grid-cols-2">
            <x-ui.empty-state :title="__('Nothing here yet')" :description="__('Trips you plan will appear in this list.')" icon="map" />
            <x-ui.empty-state :title="__('No trips match these filters')" :description="__('Clear the filters to see the whole register.')" icon="hashtag" />
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <x-ui.skeleton variant="card" />
            <x-ui.skeleton variant="chart" />
            <x-ui.skeleton variant="list" />
            <x-ui.skeleton class="h-4 w-2/5 rounded-fg-sm" />
        </div>

        <div class="flex items-center gap-3">
            <x-ui.avatar :name="__('Eugene Seaseole')" />
            <x-ui.avatar :initials="'FS'" size="sm" />
            <x-ui.avatar :name="__('Large')" size="lg" />
        </div>
    </section>

    {{-- Navlist and separator. --}}
    <section class="grid gap-3">
        <x-ui.heading level="2">{{ __('Navlist, separator') }}</x-ui.heading>

        <div class="grid gap-4 md:grid-cols-2">
            <x-ui.navlist :heading="__('Operations')">
                <x-ui.navlist.item href="#" icon="home" :current="true">{{ __('Overview') }}</x-ui.navlist.item>
                <x-ui.navlist.item href="#" icon="truck">{{ __('Vehicles') }}</x-ui.navlist.item>
                <x-ui.navlist.item href="#" icon="map">{{ __('Trips') }}</x-ui.navlist.item>
            </x-ui.navlist>
        </div>

        <x-ui.separator />
    </section>
</div>
