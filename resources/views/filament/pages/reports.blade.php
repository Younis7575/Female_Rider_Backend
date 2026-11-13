<x-filament-panels::page>
    <style>
        .fr-rep-grid{display:grid;grid-template-columns:1fr;gap:1rem}
        @media(min-width:1024px){.fr-rep-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(min-width:1536px){.fr-rep-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
        .fr-rep{border-radius:16px;background:#fff;border:1px solid rgba(0,0,0,.06);overflow:hidden}
        .dark .fr-rep{background:#18181b;border-color:rgba(255,255,255,.08)}
        .fr-rep h3{padding:.85rem 1.1rem;font-weight:700;background:linear-gradient(90deg,#C8102E,#7A0A12);color:#fff}
        .fr-rep dl{display:grid;grid-template-columns:1fr auto;gap:.55rem 1rem;padding:1rem 1.1rem}
        .fr-rep dt{color:#52525b;font-size:.9rem}.dark .fr-rep dt{color:#a1a1aa}
        .fr-rep dd{font-weight:700;text-align:right}
        .fr-range{display:flex;flex-wrap:wrap;gap:.75rem;align-items:end}
        .fr-range input{border-radius:10px;border:1px solid #d4d4d8;padding:.45rem .7rem;background:transparent}
    </style>

    <div class="fr-range">
        <label>From<br><input type="date" wire:model.live="from"></label>
        <label>Until<br><input type="date" wire:model.live="until"></label>
        <span style="color:#71717a;font-size:13px">Metrics are calculated from authoritative SQL records. Exports are pseudonymised and audited.</span>
    </div>

    <div class="fr-rep-grid">
        @foreach (['marketplace' => 'Marketplace', 'trips' => 'Trip quality', 'finance' => 'Finance', 'supply' => 'Driver supply', 'safety' => 'Safety', 'growth' => 'Growth & retention'] as $key => $title)
            @if ($key !== 'finance' || auth('admin')->user()?->can('finance.view'))
                <div class="fr-rep">
                    <h3>{{ $title }}</h3>
                    <dl>
                        @foreach ($this->report[$key] as $label => $value)
                            <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
                        @endforeach
                    </dl>
                </div>
            @endif
        @endforeach
    </div>
</x-filament-panels::page>
