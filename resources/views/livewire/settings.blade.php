<div>
    <x-cipi::page-header title="Settings" subtitle="Your account and the security of this panel." />

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 space-y-4">
            <div class="card">
                <div class="card-header"><div><h2 class="card-title">Profile</h2><p class="card-subtitle">Used to sign in and shown in the header.</p></div></div>
                <form wire:submit="saveProfile" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="profile-name">Name</label>
                            <input id="profile-name" type="text" wire:model="name" autocomplete="name">
                            @error('name') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="profile-email">Email</label>
                            <input id="profile-email" type="email" wire:model="email" autocomplete="email">
                            @error('email') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="flex justify-end"><button type="submit" class="btn btn-primary">Save profile</button></div>
                </form>
            </div>

            <div class="card">
                <div class="card-header"><div><h2 class="card-title">Password</h2><p class="card-subtitle">At least 12 characters with upper and lower case, a number and a symbol. Other sessions are signed out.</p></div></div>
                <form wire:submit="changePassword" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="pw-current">Current password</label>
                            <input id="pw-current" type="password" wire:model="currentPassword" autocomplete="current-password">
                            @error('currentPassword') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="pw-new">New password</label>
                            <input id="pw-new" type="password" wire:model="newPassword" autocomplete="new-password">
                            @error('newPassword') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="pw-confirm">Confirm</label>
                            <input id="pw-confirm" type="password" wire:model="newPasswordConfirmation" autocomplete="new-password">
                            @error('newPasswordConfirmation') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="flex justify-end"><button type="submit" class="btn btn-primary">Change password</button></div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <div><h2 class="card-title flex items-center gap-2"><x-cipi::icon name="shield" /> Two-factor authentication</h2><p class="card-subtitle">A 6-digit code from an authenticator app (1Password, Aegis, Google Authenticator…) on every sign-in.</p></div>
                    <span class="badge {{ $twoFactorEnabled ? 'badge-green' : 'badge-amber' }}">{{ $twoFactorEnabled ? 'On' : 'Off' }}</span>
                </div>

                @if($twoFactorEnabled)
                    <form wire:submit="disableTwoFactor" class="flex flex-wrap items-end gap-3">
                        <div class="flex-1" style="min-width: 14rem;">
                            <label for="tfa-pass">Confirm with your password to turn it off</label>
                            <input id="tfa-pass" type="password" wire:model="disablePassword" autocomplete="current-password">
                            @error('disablePassword') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-danger">Disable 2FA</button>
                    </form>
                    <p class="field-hint mt-3">Locked out? On the server: <code>cipi gui reset-user</code>.</p>
                @elseif($setupSecret)
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                        <div>
                            @if($qrCodeSvg)<div class="qr-box">{!! $qrCodeSvg !!}</div>@endif
                            <div class="mt-3"><x-cipi::secret label="Setup key" :value="$setupSecret" /></div>
                        </div>
                        <form wire:submit="confirmTwoFactor" class="space-y-4">
                            <ol class="space-y-2 text-sm text-soft" style="list-style: decimal; padding-left: 1.1rem;">
                                <li>Scan the QR code with your authenticator app, or type the setup key.</li>
                                <li>Enter the 6-digit code it shows.</li>
                            </ol>
                            <div>
                                <label for="tfa-code">Verification code</label>
                                <input id="tfa-code" type="text" wire:model="verificationCode" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" class="otp-input" autofocus>
                                @error('verificationCode') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="btn-group">
                                <button type="submit" class="btn btn-primary">Turn on 2FA</button>
                                <button type="button" wire:click="cancelSetup" class="btn btn-secondary">Cancel</button>
                            </div>
                        </form>
                    </div>
                @else
                    <x-cipi::alert type="warn" class="mb-4">This panel can deploy and delete apps on every connected server. Protect it with a second factor.</x-cipi::alert>
                    <button wire:click="startTwoFactorSetup" class="btn btn-primary"><x-cipi::icon name="shield" /> Set up 2FA</button>
                @endif
            </div>
        </div>

        <div class="space-y-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title">About</h2></div>
                <dl class="kv">
                    @foreach($versions as $label => $version)
                        <dt>{{ $label }}</dt><dd class="font-mono text-xs">{{ $version }}</dd>
                    @endforeach
                    <dt>Cipi API</dt><dd class="font-mono text-xs">1.31 ready</dd>
                </dl>
                <p class="field-hint mt-4">Update with <code>cipi gui update</code> on the server that hosts the panel.</p>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Resources</h2></div>
                <ul class="space-y-2 text-sm">
                    <li><a href="https://cipi.sh/docs/gui" target="_blank" rel="noopener" class="text-link">Panel documentation</a></li>
                    <li><a href="https://cipi.sh/docs/advanced#cipi-api" target="_blank" rel="noopener" class="text-link">REST API &amp; tokens</a></li>
                    <li><a href="https://github.com/cipi-sh/gui" target="_blank" rel="noopener" class="text-link">cipi-sh/gui on GitHub</a></li>
                    <li><a href="https://cipi.sh/whats-new" target="_blank" rel="noopener" class="text-link">What's new in Cipi</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>
