{{-- Bedienung der Einstellung „wlan_gruppen" auf der Ekkon-Task-Seite von
     Netzwerk/Alarme (typ "view"). Bekommt $task, $schluessel, $feld, $wert. --}}
@php
    $gruppen = \Intranet\Modules\Netzwerk\Support\WlanGruppen::parse((string) $wert);
    $verfuegbar = \Intranet\Modules\Netzwerk\Support\WlanGruppen::verfuegbareSsids();
    $standardSchwelle = 10;
@endphp

<h4 class="font-semibold text-gray-700 mb-1">{{ $feld['label'] ?? 'Überwachte WLANs' }}</h4>
<p class="text-sm text-gray-500 mb-3">
    Ein oder mehrere WLANs auswählen (mehrere = zusammengezählt, z. B. das 2,4- und das
    5-GHz-Netz desselben Gäste-WLANs), erlaubten Andrang eintragen, hinzufügen. Gemeldet wird,
    sobald MEHR Geräte gleichzeitig eingebucht sind – einmal je Überschreitung, nicht jeden Lauf.
</p>

@if ($errors->any())
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
        {{ $errors->first() }}
    </div>
@endif

<form method="POST" action="{{ route('module.netzwerk.wlan-gruppen.hinzufuegen') }}"
      class="flex flex-wrap items-end gap-3">
    @csrf
    <div>
        <label class="block text-sm font-medium text-gray-700" for="wlan-ssids">WLANs (Mehrfachauswahl mit Strg)</label>
        <select id="wlan-ssids" name="ssids[]" multiple size="{{ min(8, max(3, count($verfuegbar))) }}"
                class="mt-1 block w-72 max-w-full rounded-lg border-gray-300 text-sm">
            @forelse ($verfuegbar as $ssid)
                <option value="{{ $ssid }}" @selected(in_array($ssid, (array) old('ssids', []), true))>{{ $ssid }}</option>
            @empty
                <option value="" disabled>noch keine WLAN-Clients gesehen</option>
            @endforelse
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700" for="wlan-schwelle">Andrang ab (Geräte)</label>
        <input id="wlan-schwelle" type="number" name="schwelle" min="0" value="{{ old('schwelle', $standardSchwelle) }}"
               class="mt-1 block w-32 rounded-lg border-gray-300 text-sm" required>
    </div>
    <button type="submit"
            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
        Hinzufügen
    </button>
</form>

@if ($gruppen !== [])
    <ul class="mt-4 divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm">
        @foreach ($gruppen as $i => $g)
            <li class="flex flex-wrap items-center gap-3 px-3 py-2">
                <span class="font-medium text-gray-800">{{ implode(' + ', $g['ssids']) }}</span>
                <span class="text-gray-500">Andrang ab mehr als <b class="text-gray-700">{{ $g['schwelle'] }}</b> Geräten</span>
                <form method="POST" action="{{ route('module.netzwerk.wlan-gruppen.entfernen', $i) }}" class="ml-auto"
                      onsubmit="return confirm('Diese WLAN-Gruppe nicht mehr überwachen?');">
                    @csrf
                    <button type="submit" class="text-xs text-red-700 hover:underline">Entfernen</button>
                </form>
            </li>
        @endforeach
    </ul>
@else
    <p class="mt-3 text-sm text-gray-500 italic">Noch kein WLAN überwacht.</p>
@endif
