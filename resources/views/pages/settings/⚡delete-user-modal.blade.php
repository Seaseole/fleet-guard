<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }

    /**
     * Clear sensitive input and validation when the native dialog closes.
     */
    public function closeModal(): void
    {
        $this->reset('password');
        $this->resetErrorBag();
    }
}; ?>

<x-ui.dialog
    name="confirm-user-deletion"
    maxWidth="lg"
    aria-labelledby="confirm-user-deletion-title"
    x-on:dialog-closed="$wire.closeModal()"
>
    <form method="POST" wire:submit="deleteUser" class="grid gap-6">
        <div class="grid gap-2">
            <x-ui.heading id="confirm-user-deletion-title">{{ __('Are you sure you want to delete your account?') }}</x-ui.heading>

            <x-ui.subheading>
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
            </x-ui.subheading>
        </div>

        {{-- `name` is not decoration: `x-ui.input` looks the validation error up by it, and without it
             a wrong password refuses the delete and shows nothing. --}}
        <x-ui.input wire:model="password" name="password" :label="__('Password')" type="password" viewable />

        <div class="flex items-center justify-end gap-2">
            <x-ui.button variant="outline" type="button" x-on:click="$dispatch('close-confirm-user-deletion')">
                {{ __('Cancel') }}
            </x-ui.button>

            <x-ui.button variant="danger-solid" type="submit" data-test="confirm-delete-user-button">
                {{ __('Delete account') }}
            </x-ui.button>
        </div>
    </form>
</x-ui.dialog>
