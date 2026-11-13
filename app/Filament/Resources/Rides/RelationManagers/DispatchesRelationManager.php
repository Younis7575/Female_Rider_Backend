<?php

namespace App\Filament\Resources\Rides\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DispatchesRelationManager extends RelationManager
{
    protected static string $relationship = 'dispatches';

    protected static ?string $title = 'Dispatch log (drivers notified)';

    public function table(Table $table): Table
    {
        return $table->defaultSort('id')->columns([
            TextColumn::make('notified_at')->dateTime('H:i:s'),
            TextColumn::make('driver.name')->label('Driver'),
            TextColumn::make('round'),
            TextColumn::make('distance_m')->label('Distance')->formatStateUsing(fn ($state) => $state ? round($state / 1000, 2).' km' : '—'),
            TextColumn::make('status')->badge(),
            TextColumn::make('responded_at')->dateTime('H:i:s')->placeholder('—'),
        ]);
    }
}
