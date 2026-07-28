<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Netzwerk · Standorte</h2>
    </x-slot>

    <div class="py-6">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-6">

            @if ($errors->any())
                <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-lg px-4 py-3">
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                <p class="text-sm text-gray-500 mb-4">
                    Standorte sind eine Hierarchie: <b>Gebäude → Stockwerke → Räume</b>. Am Gerät
                    wählst du dann einen Punkt daraus (ganzes Gebäude, ein Stockwerk oder ein Raum).
                    Räume lassen sich mit Kommas in Serie anlegen („R 101, R 102, R 103").
                </p>
                <form method="POST" action="{{ route('module.netzwerk.standorte.gebaeude.store') }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <x-text-input name="name" :value="old('name')" placeholder="Neues Gebäude …" class="block" required />
                    <button type="submit"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                        <x-module-icon name="plus" class="text-base" />
                        Gebäude anlegen
                    </button>
                </form>
            </div>

            @forelse ($gebaeude as $geb)
                <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6 space-y-4">

                    {{-- Kopf: Gebäude umbenennen / löschen --}}
                    <div class="flex flex-wrap items-center gap-2">
                        <x-module-icon name="home" class="text-xl text-gray-400" />
                        <form method="POST" action="{{ route('module.netzwerk.standorte.gebaeude.update', $geb) }}" class="inline-flex items-center gap-2">
                            @csrf
                            @method('PUT')
                            <x-text-input name="name" :value="$geb->name" class="block font-semibold" required />
                            <button type="submit" title="Speichern"
                                    class="inline-flex items-center rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700">
                                <x-module-icon name="save" class="text-base" />
                            </button>
                        </form>
                        <form method="POST" action="{{ route('module.netzwerk.standorte.gebaeude.destroy', $geb) }}" class="inline"
                              onsubmit="return confirm('Dieses Gebäude samt Stockwerken und Räumen löschen? Zugewiesene Geräte verlieren ihren Standort.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" title="Gebäude löschen"
                                    class="inline-flex items-center rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-red-700">
                                <x-module-icon name="trash" class="text-base" />
                            </button>
                        </form>
                        <span class="text-sm text-gray-400">{{ $geb->geraete_count }} {{ $geb->geraete_count === 1 ? 'Gerät' : 'Geräte' }}</span>
                    </div>

                    {{-- Stockwerke mit ihren Räumen --}}
                    @foreach ($geb->stockwerke as $stockwerk)
                        <div class="border-l-4 border-gray-200 pl-3 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <form method="POST" action="{{ route('module.netzwerk.standorte.stockwerke.update', $stockwerk) }}" class="inline-flex items-center gap-2">
                                    @csrf
                                    @method('PUT')
                                    <x-text-input name="name" :value="$stockwerk->name" class="block text-sm" required />
                                    <button type="submit" title="Speichern"
                                            class="inline-flex items-center rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700">
                                        <x-module-icon name="save" class="text-base" />
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('module.netzwerk.standorte.stockwerke.destroy', $stockwerk) }}" class="inline"
                                      onsubmit="return confirm('Dieses Stockwerk samt Räumen löschen? Zugewiesene Geräte verlieren Stockwerk und Raum.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Stockwerk löschen"
                                            class="inline-flex items-center rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-red-700">
                                        <x-module-icon name="trash" class="text-base" />
                                    </button>
                                </form>
                                @if ($stockwerk->geraete_count > 0)
                                    <span class="text-xs text-gray-400">{{ $stockwerk->geraete_count }} Geräte</span>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                @foreach ($stockwerk->raeume as $raum)
                                    @include('netzwerk::partials.raum-chip', ['raum' => $raum])
                                @endforeach
                                <form method="POST" action="{{ route('module.netzwerk.standorte.raeume.store', $geb) }}" class="inline-flex items-center gap-1">
                                    @csrf
                                    <input type="hidden" name="stockwerk_id" value="{{ $stockwerk->id }}">
                                    <x-text-input name="name" placeholder="+ Raum (Kommas = mehrere)" class="block w-56 text-sm" required />
                                    <button type="submit" title="Raum/Räume anlegen"
                                            class="inline-flex items-center rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700">
                                        <x-module-icon name="plus" class="text-base" />
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach

                    {{-- Räume direkt am Gebäude (ohne Stockwerk) --}}
                    <div class="border-l-4 border-gray-100 pl-3 space-y-2">
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-400">Räume ohne Stockwerk</div>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            @foreach ($geb->raeume as $raum)
                                @include('netzwerk::partials.raum-chip', ['raum' => $raum])
                            @endforeach
                            <form method="POST" action="{{ route('module.netzwerk.standorte.raeume.store', $geb) }}" class="inline-flex items-center gap-1">
                                @csrf
                                <x-text-input name="name" placeholder="+ Raum (Kommas = mehrere)" class="block w-56 text-sm" required />
                                <button type="submit" title="Raum/Räume anlegen"
                                        class="inline-flex items-center rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700">
                                    <x-module-icon name="plus" class="text-base" />
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- Neues Stockwerk --}}
                    <form method="POST" action="{{ route('module.netzwerk.standorte.stockwerke.store', $geb) }}" class="flex flex-wrap items-center gap-1">
                        @csrf
                        <x-text-input name="name" placeholder="+ Stockwerk …" class="block w-48 text-sm" required />
                        <button type="submit" title="Stockwerk anlegen"
                                class="inline-flex items-center rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700">
                            <x-module-icon name="plus" class="text-base" />
                        </button>
                    </form>

                </div>
            @empty
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-gray-500">
                    Noch keine Gebäude angelegt — oben das erste anlegen, danach Stockwerke und Räume darin.
                </div>
            @endforelse

        </div>
    </div>
</x-app-layout>
