<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Incidents\IncidentResource;
use App\Models\Incident;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OperationalQueues extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '10s';

    public static function canView(): bool
    {
        return (bool) auth('admin')->user()?->can('safety.view');
    }

    public function table(Table $table): Table
    {
        return $table->heading('Open safety alerts')
            ->query(Incident::query()->with('reporter')->whereIn('status', ['open', 'acknowledged', 'escalated'])->orderByRaw("FIELD(severity,'critical','high','medium','low')")->latest('id'))
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No open safety alerts')
            ->columns([
                TextColumn::make('created_at')->since(),
                TextColumn::make('reference')->weight('bold')->url(fn ($record) => IncidentResource::getUrl('view', ['record' => $record])),
                TextColumn::make('type')->badge()->color(fn ($state) => $state === 'sos' ? 'danger' : 'gray'),
                TextColumn::make('severity')->badge()->color(fn ($state) => $state === 'critical' ? 'danger' : 'warning'),
                TextColumn::make('reporter.name'),
                TextColumn::make('status')->badge(),
            ]);
    }
}
