<div class="mm-top-navbar">
    <div class="mm-navbar-custom">
        <div class="flex items-center gap-3">
            <i class="ri-menu-line wrapper-menu"></i>
        </div>

        <div class="app-context-pill">
            <span class="app-context-icon"><i class="fa-solid fa-cubes-stacked"></i></span>
            <span class="app-context-copy">
                <strong>Warehouse Management</strong>
                <small>Inventory &amp; Accounting Control</small>
            </span>
        </div>

        <div class="flex items-center gap-1">
            <div class="change-mode">
                <i class="fa-solid fa-sun theme-icon theme-icon-light" aria-hidden="true"></i>
                <input type="checkbox" class="toggle toggle-sm" id="dark-mode" aria-label="Aktifkan mode gelap">
                <i class="fa-solid fa-moon theme-icon theme-icon-dark" aria-hidden="true"></i>
            </div>

            <button type="button" class="btn btn-ghost btn-sm btn-circle" id="btnFullscreen" title="Layar penuh">
                <i class="fa-solid fa-expand"></i>
            </button>

            @auth
                <div class="dropdown dropdown-end" x-data="notifikasiLonceng()">
                    <button type="button" tabindex="0" class="btn btn-ghost btn-sm btn-circle" title="Notifikasi" @click="muat()">
                        <div class="indicator">
                            <i class="fa-solid fa-bell"></i>
                            <span class="badge indicator-item badge-error badge-xs" x-show="belumDibaca > 0"
                                x-text="belumDibaca > 9 ? '9+' : belumDibaca"></span>
                        </div>
                    </button>
                    <div tabindex="0"
                        class="dropdown-content z-40 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-box border border-base-300 bg-base-100 shadow-lg">
                        <div class="flex items-center justify-between border-b border-base-300 px-4 py-3">
                            <span class="font-semibold">Notifikasi</span>
                            <button type="button" class="btn btn-ghost btn-xs" x-show="belumDibaca > 0" @click="bacaSemua()">
                                Tandai dibaca
                            </button>
                        </div>
                        <div class="max-h-80 overflow-y-auto divide-y divide-base-300">
                            <template x-if="memuat">
                                <div class="p-4 text-center text-sm text-base-content/50">
                                    <span class="loading loading-spinner loading-sm"></span>
                                </div>
                            </template>
                            <template x-if="!memuat && items.length === 0">
                                <div class="p-6 text-center text-sm text-base-content/50">
                                    <i class="fa-solid fa-bell-slash text-xl"></i>
                                    <p class="mt-1">Belum ada notifikasi.</p>
                                </div>
                            </template>
                            <template x-for="item in items" :key="item.id">
                                <a :href="item.url" class="flex items-start gap-3 p-3 transition hover:bg-base-200"
                                    :class="item.dibaca ? '' : 'bg-base-200/60'">
                                    <i class="fa-solid mt-1 shrink-0" :class="item.ikon"></i>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold" x-text="item.judul"></p>
                                        <p class="truncate text-xs text-base-content/60" x-text="item.ringkasan"></p>
                                        <p class="text-xs text-base-content/40" x-text="item.waktu"></p>
                                    </div>
                                </a>
                            </template>
                        </div>
                        <a href="{{ route('notifikasi.index') }}" class="btn btn-ghost btn-sm w-full rounded-t-none">
                            Lihat semua notifikasi
                        </a>
                    </div>
                </div>
            @endauth

            <div class="dropdown dropdown-end">
                <button type="button" tabindex="0" class="profile-circle">
                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}{{ strtoupper(substr(explode(' ', auth()->user()->name)[1] ?? '', 0, 1)) }}
                </button>
                @auth
                    <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-40 mt-2 w-56 p-2 shadow-lg border border-base-300">
                        <li class="menu-title px-2 py-1">
                            <span class="font-semibold text-base-content">{{ auth()->user()->name }}</span>
                            <span class="text-xs text-base-content/50">{{ auth()->user()->email }}</span>
                        </li>
                        <div class="divider my-1"></div>
                        <li>
                            <a href="{{ route('profil.show') }}" class="flex items-center gap-2">
                                <i class="fa-solid fa-id-card"></i> Profil Saya
                            </a>
                        </li>
                        @can('viewAny', App\Models\User::class)
                            <li>
                                <a href="{{ route('user.index') }}" class="flex items-center gap-2">
                                    <i class="fa-solid fa-users-gear"></i> Manajemen Pengguna
                                </a>
                            </li>
                        @endcan
                        <div class="divider my-1"></div>
                        <li>
                            <form id="logout-form" action="{{ route('logout') }}" method="POST" class="hidden">
                                @csrf
                            </form>
                            <a href="#" onclick="event.preventDefault(); confirmLogout();" class="flex items-center gap-2">
                                <i class="fa-solid fa-right-from-bracket"></i> Logout
                            </a>
                        </li>
                    </ul>
                @endauth
            </div>
        </div>
    </div>
</div>
