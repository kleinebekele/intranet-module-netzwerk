{{-- Ein Raum als kompakter Chip: umbenennen (Enter oder Häkchen) + löschen. --}}
<span class="inline-flex items-center gap-1">
    <form method="POST" action="{{ route('module.netzwerk.standorte.raeume.update', $raum) }}" class="inline-flex items-center gap-1">
        @csrf
        @method('PUT')
        <x-text-input name="name" :value="$raum->name" class="block w-32 text-sm" required title="Enter speichert" />
    </form>
    @if ($raum->geraete_count > 0)
        <span class="text-xs text-gray-400" title="{{ $raum->geraete_count }} Geräte an diesem Raum">({{ $raum->geraete_count }})</span>
    @endif
    <form method="POST" action="{{ route('module.netzwerk.standorte.raeume.destroy', $raum) }}" class="inline"
          onsubmit="return confirm('Diesen Raum löschen? Zugewiesene Geräte verlieren ihren Raum.');">
        @csrf
        @method('DELETE')
        <button type="submit" title="Raum löschen"
                class="inline-flex items-center rounded-md p-1 text-gray-300 hover:bg-gray-100 hover:text-red-700">
            <x-module-icon name="x" class="text-sm" />
        </button>
    </form>
</span>
