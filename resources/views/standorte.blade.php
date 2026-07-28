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
                    Standorte stehen im Bearbeiten-Formular jedes Geräts zur Auswahl.
                    Nur das Gebäude ist Pflicht – Stockwerk und Raum nach Bedarf.
                </p>
                <form method="POST" action="{{ route('module.netzwerk.standorte.store') }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <x-text-input name="gebaeude" :value="old('gebaeude')" placeholder="Gebäude *" class="block" required />
                    <x-text-input name="stockwerk" :value="old('stockwerk')" placeholder="Stockwerk" class="block" />
                    <x-text-input name="raum" :value="old('raum')" placeholder="Raum" class="block" />
                    <button type="submit"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                        <x-module-icon name="plus" class="text-base" />
                        Anlegen
                    </button>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-gray-500 border-b border-gray-200 bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 font-medium">Gebäude</th>
                                <th class="px-4 py-2 font-medium">Stockwerk</th>
                                <th class="px-4 py-2 font-medium">Raum</th>
                                <th class="px-4 py-2 font-medium">zugewiesen</th>
                                <th class="px-4 py-2 font-medium text-right">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($standorte as $standort)
                                <tr>
                                    <td class="px-4 py-2">
                                        <form method="POST" action="{{ route('module.netzwerk.standorte.update', $standort) }}"
                                              id="standort-{{ $standort->id }}">
                                            @csrf
                                            @method('PUT')
                                            <x-text-input name="gebaeude" :value="$standort->gebaeude" class="block" required />
                                        </form>
                                    </td>
                                    <td class="px-4 py-2">
                                        <x-text-input name="stockwerk" :value="$standort->stockwerk" form="standort-{{ $standort->id }}" class="block" />
                                    </td>
                                    <td class="px-4 py-2">
                                        <x-text-input name="raum" :value="$standort->raum" form="standort-{{ $standort->id }}" class="block" />
                                    </td>
                                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap">
                                        {{ $standort->geraete_count }} {{ $standort->geraete_count === 1 ? 'Gerät' : 'Geräte' }}
                                    </td>
                                    <td class="px-4 py-2 text-right whitespace-nowrap">
                                        <button type="submit" form="standort-{{ $standort->id }}" title="Speichern"
                                                class="inline-flex items-center rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700">
                                            <x-module-icon name="save" class="text-base" />
                                        </button>
                                        <form method="POST" action="{{ route('module.netzwerk.standorte.destroy', $standort) }}" class="inline"
                                              onsubmit="return confirm('Diesen Standort löschen? Zugewiesene Geräte verlieren ihren Standort.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" title="Löschen"
                                                    class="inline-flex items-center rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-red-700">
                                                <x-module-icon name="trash" class="text-base" />
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-6 text-gray-500">Noch keine Standorte angelegt.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
