<div>
    <div class="mb-6">
        <h2 class="text-2xl font-semibold text-white">Settings</h2>
        <p class="text-sm text-surface-400 mt-1">Security and account preferences</p>
    </div>

    @if($success)
        <div class="card border-emerald-700 bg-emerald-900/20 mb-4 text-sm text-emerald-400">{{ $success }}</div>
    @endif
    @if($error)
        <div class="card border-red-800 bg-red-900/20 mb-4 text-sm text-red-400">{{ $error }}</div>
    @endif

    <div class="card max-w-lg">
        <h3 class="font-semibold text-white mb-2">Two-Factor Authentication</h3>
        <p class="text-sm text-surface-400 mb-4">Add an extra layer of security using an authenticator app (Google Authenticator, Authy, etc.).</p>

        @if($twoFactorEnabled)
            <div class="flex items-center gap-2 mb-4">
                <span class="badge badge-green">Enabled</span>
            </div>
            <form wire:submit="disableTwoFactor" class="space-y-3">
                <div>
                    <label>Confirm with your password</label>
                    <input type="password" wire:model="currentPassword">
                </div>
                <button type="submit" class="btn btn-danger btn-sm">Disable 2FA</button>
            </form>
        @elseif($setupSecret)
            <div class="space-y-4">
                @if($qrCodeSvg)
                    <div class="flex justify-center p-4 bg-white rounded-lg">{!! $qrCodeSvg !!}</div>
                @endif
                <p class="text-xs text-surface-500 font-mono text-center break-all">Secret: {{ $setupSecret }}</p>
                <form wire:submit="confirmTwoFactor" class="space-y-3">
                    <div>
                        <label>Verification code</label>
                        <input type="text" wire:model="verificationCode" maxlength="6" placeholder="000000"
                               style="text-align:center;letter-spacing:0.2em;font-family:monospace;">
                        @error('verificationCode') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Confirm</button>
                        <button type="button" wire:click="cancelSetup" class="btn btn-secondary btn-sm">Cancel</button>
                    </div>
                </form>
            </div>
        @else
            <button wire:click="startTwoFactorSetup" class="btn btn-primary btn-sm">Enable 2FA</button>
        @endif
    </div>
</div>
