<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Netzwerk · Gerät bearbeiten</h2>
    </x-slot>

    @php
        // Vorauswahl: gepflegter Typ, sonst der (per Adresszeile mitgegebene)
        // erkannte — aber nur, wenn es ihn im CRUD wirklich gibt.
        $vorauswahl = $geraet?->geraetetyp_id
            ?? $typen->first(fn ($t) => $erkannt !== null && mb_strtolower($t->name) === mb_strtolower($erkannt))?->id;
    @endphp

    <div class="py-6">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                <div class="flex items-center gap-2 flex-wrap mb-1">
                    <span class="font-semibold text-lg text-gray-800">{{ $anzeige }}</span>
                    <span class="ml-auto">
                        <a href="{{ $zurueck }}" class="text-sm text-gray-500 hover:text-gray-700 hover:underline">zurück</a>
                    </span>
                </div>
                <div class="text-sm text-gray-500 flex flex-wrap gap-x-6 gap-y-1 mb-6">
                    @if ($ip)<span class="font-mono">{{ $ip }}</span>@endif
                    @if ($mac)<span class="font-mono">{{ $mac }}</span>@endif
                </div>

                <form method="POST" action="{{ route('module.netzwerk.geraet.speichern') }}" class="space-y-5">
                    @csrf
                    <input type="hidden" name="mac" value="{{ $mac }}">
                    <input type="hidden" name="ip" value="{{ $ip }}">
                    <input type="hidden" name="zurueck" value="{{ $zurueck }}">

                    <div>
                        <x-input-label for="geraetetyp_id" value="Gerätetyp" />
                        <select id="geraetetyp_id" name="geraetetyp_id"
                                class="mt-1 block w-full sm:max-w-md rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">— kein Typ —</option>
                            @foreach ($typen as $typ)
                                <option value="{{ $typ->id }}" @selected((int) old('geraetetyp_id', $vorauswahl) === $typ->id)>{{ $typ->name }}</option>
                            @endforeach
                        </select>
                        @if ($geraet?->geraetetyp_id === null && $erkannt !== null)
                            <p class="mt-1 text-xs text-gray-400">Automatisch erkannt: {{ $erkannt }} — Speichern übernimmt die Auswahl dauerhaft.</p>
                        @endif
                        <x-input-error :messages="$errors->get('geraetetyp_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="standort" value="Standort" />
                        <select id="standort" name="standort"
                                class="mt-1 block w-full sm:max-w-md rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">— kein Standort —</option>
                            @foreach ($standortOptionen as $gruppe)
                                <optgroup label="{{ $gruppe['label'] }}">
                                    @foreach ($gruppe['optionen'] as $option)
                                        <option value="{{ $option['wert'] }}" @selected(old('standort', $standortWert) === $option['wert'])>{{ $option['text'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-400">
                            Ganzes Gebäude, ein Stockwerk oder ein Raum — die Hierarchie pflegst du unter
                            <a href="{{ route('module.netzwerk.standorte') }}" class="underline hover:text-gray-600">Netzwerk → Standorte</a>.
                        </p>
                        <x-input-error :messages="$errors->get('standort')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="info" value="Info" />
                        <textarea id="info" name="info" rows="4"
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                  placeholder="Allgemeine Hinweise zu diesem Gerät …">{{ old('info', $geraet?->info) }}</textarea>
                        <x-input-error :messages="$errors->get('info')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                            <x-module-icon name="save" class="text-base" />
                            Speichern
                        </button>
                        <a href="{{ $zurueck }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
                            <x-module-icon name="x" class="text-base" />
                            Abbrechen
                        </a>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
