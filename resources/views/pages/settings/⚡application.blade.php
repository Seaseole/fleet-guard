<?php

use App\Domain\Invoicing\Actions\SetStandardVatRate;
use App\Domain\Invoicing\Queries\EffectiveVat;
use App\Domain\Kernel\Settings\Actions\UpdateCompanyProfile;
use App\Domain\Kernel\Settings\Actions\UpdateCompanySettings;
use App\Domain\Kernel\Settings\CompanySettings;
use App\Domain\Kernel\Settings\SettingsCatalogue;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Application settings')] class extends Component
{
    /** @var array<string, mixed> */
    public array $settings = [];

    public string $currency = '';

    public string $profileName = '';

    public string $profileRegistrationNumber = '';

    public string $profileVatNumber = '';

    public string $timezone = '';

    public string $country = '';

    public string $fiscalYearStart = '';

    public function mount(CompanySettings $settings): void
    {
        $company = CurrentCompany::model();

        $this->currency = $company->base_currency_code;
        $this->profileName = $company->name;
        $this->profileRegistrationNumber = (string) $company->registration_number;
        $this->profileVatNumber = (string) $company->vat_number;
        $this->timezone = $company->timezone;
        $this->country = config('fleet.commercial.countries')[$company->country_code] ?? $company->country_code;
        $this->fiscalYearStart = $company->fiscal_year_starts_on?->format('j F') ?? '—';

        foreach (SettingsCatalogue::fields() as $key => $field) {
            $this->settings[SettingsCatalogue::property($key)] = match (true) {
                $field['type'] === 'money' => SettingsCatalogue::moneyForInput($key),
                // The rate's truth is the register, so the field reads from it: an Owner sees the
                // percentage today's documents actually charge, not a copy in the blob (§20.37).
                SettingsCatalogue::store($key) === 'door' => app(EffectiveVat::class)->standardPercent(),
                default => $settings->get($key),
            };
        }
    }

    public function canManage(): bool
    {
        return Gate::allows('settings.manage');
    }

    public function save(UpdateCompanySettings $update, UpdateCompanyProfile $updateProfile, SetStandardVatRate $setVat): void
    {
        abort_unless(Gate::allows('settings.manage'), 403);

        $rules = SettingsCatalogue::rules();
        $rules['settings.'.SettingsCatalogue::property('fuel.variance_critical_pct')][] =
            'gt:settings.'.SettingsCatalogue::property('fuel.variance_threshold_pct');
        $rules['profileName'] = ['required', 'string', 'max:255'];
        $rules['profileRegistrationNumber'] = ['nullable', 'string', 'max:64'];
        $rules['profileVatNumber'] = ['nullable', 'string', 'max:64'];

        $validated = $this->validate($rules);

        $values = [];

        foreach (SettingsCatalogue::blobKeys() as $key) {
            $values[$key] = $validated['settings'][SettingsCatalogue::property($key)];
        }

        try {
            $update($values);

            // The one door field goes to its own register, in the same save: a form that wrote the
            // blob and left the window stale would be two truths with one click (§20.37).
            $setVat->execute((float) $validated['settings'][SettingsCatalogue::property('tax.standard_rate_pct')], auth()->id());
        } catch (DomainException $exception) {
            $this->addError(
                'settings.'.SettingsCatalogue::property('maintenance.work_order_approval_threshold_base'),
                $exception->getMessage(),
            );

            return;
        } catch (\Throwable $refused) {
            $this->addError(
                'settings.'.SettingsCatalogue::property('tax.standard_rate_pct'),
                $refused->getMessage(),
            );

            return;
        }

        $updateProfile([
            'name' => $validated['profileName'],
            'registration_number' => $validated['profileRegistrationNumber'] ?? null,
            'vat_number' => $validated['profileVatNumber'] ?? null,
        ]);

        $this->dispatch('toast', message: __('Settings saved.'), kind: 'success');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-ui.heading level="2" class="sr-only">{{ __('Application settings') }}</x-ui.heading>

    <x-pages::settings.layout :heading="__('Application settings')" :subheading="__('The company\'s name and numbers, and the thresholds and windows it runs on — shared by everyone in this company. Your own preferences live under Your settings. Changing one does not rewrite history: a figure already recorded on a document or an alert stays as it was.')">
        <form wire:submit="save" class="my-6 w-full space-y-5">
            <x-ui.card :title="__('Company details')">
                <div class="grid gap-5">
                    <x-ui.input
                        wire:model="profileName"
                        name="profileName"
                        :label="__('Site name')"
                        :hint="__('Shown in the app header, page titles and invitation emails.')"
                        :disabled="! $this->canManage()"
                        required
                    />

                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.input
                            wire:model="profileRegistrationNumber"
                            name="profileRegistrationNumber"
                            :label="__('Registration number')"
                            :hint="__('Printed on invoices and credit notes.')"
                            :disabled="! $this->canManage()"
                        />

                        <x-ui.input
                            wire:model="profileVatNumber"
                            name="profileVatNumber"
                            :label="__('VAT number')"
                            :hint="__('The number a tax invoice must carry.')"
                            :disabled="! $this->canManage()"
                        />
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Fixed at setup')">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-0.5">
                        <dt class="text-xs text-fg-ink-muted">{{ __('Base currency') }}</dt>
                        <dd class="text-sm text-fg-ink">{{ $currency }}</dd>
                    </div>

                    <div class="grid gap-0.5">
                        <dt class="text-xs text-fg-ink-muted">{{ __('Timezone') }}</dt>
                        <dd class="text-sm text-fg-ink">{{ $timezone }}</dd>
                    </div>

                    <div class="grid gap-0.5">
                        <dt class="text-xs text-fg-ink-muted">{{ __('Fiscal year starts') }}</dt>
                        <dd class="text-sm text-fg-ink">{{ $fiscalYearStart }}</dd>
                    </div>

                    <div class="grid gap-0.5">
                        <dt class="text-xs text-fg-ink-muted">{{ __('Country') }}</dt>
                        <dd class="text-sm text-fg-ink">{{ $country }}</dd>
                    </div>
                </dl>

                <x-ui.text class="mt-4 text-xs text-fg-ink-muted">
                    {{ __('These four define how money is scaled, when a business day ends and which numbering period a document belongs to. Changing one after documents exist would re-read history under a new rule, so they are set during onboarding rather than edited here.') }}
                </x-ui.text>
            </x-ui.card>

            @foreach (SettingsCatalogue::groups() as $group => $keys)
                <x-ui.card :title="$group">
                    <div class="grid gap-5">
                        @foreach ($keys as $key)
                            @php
                                $field = SettingsCatalogue::fields()[$key];
                                $property = 'settings.'.SettingsCatalogue::property($key);
                            @endphp

                            @if ($field['type'] === 'choice')
                                <div class="grid gap-1.5">
                                    <label for="{{ 'setting-'.SettingsCatalogue::property($key) }}" class="text-xs font-medium text-fg-ink-secondary">{{ $field['label'] }}</label>

                                    <x-ui.select
                                        name="{{ $property }}"
                                        id="{{ 'setting-'.SettingsCatalogue::property($key) }}"
                                        wire:model="{{ $property }}"
                                        :disabled="! $this->canManage()"
                                    >
                                        @foreach (SettingsCatalogue::options($key) as $option)
                                            <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                                        @endforeach
                                    </x-ui.select>

                                    @if (filled($field['hint'] ?? null))
                                        <p class="text-xs text-fg-ink-muted">{{ $field['hint'] }}</p>
                                    @endif
                                </div>
                            @else
                                <x-ui.input
                                    wire:model="{{ $property }}"
                                    name="{{ $property }}"
                                    :label="$field['label']"
                                    :hint="$field['hint']"
                                    type="number"
                                    :step="$field['type'] === 'int' ? 1 : ($field['type'] === 'money' ? '0.01' : '0.01')"
                                    :min="$field['min']"
                                    :max="$field['max']"
                                    :prefix="$field['type'] === 'money' ? $currency : null"
                                    :suffix="$field['type'] === 'float' && $field['group'] === 'Tax' ? '%' : null"
                                    :disabled="! $this->canManage()"
                                    numeric
                                />
                            @endif
                        @endforeach
                    </div>
                </x-ui.card>
            @endforeach

            @if ($this->canManage())
                <div class="flex justify-end">
                    <x-ui.button variant="primary" type="submit" data-test="save-company-settings-button">
                        {{ __('Save') }}
                    </x-ui.button>
                </div>
            @else
                <x-ui.text class="text-sm text-fg-ink-muted">
                    {{ __('Your role can see these values but not change them. Ask the Owner to make an edit.') }}
                </x-ui.text>
            @endif
        </form>
    </x-pages::settings.layout>
</section>
