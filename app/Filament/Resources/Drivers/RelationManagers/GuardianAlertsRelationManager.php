<?php

namespace App\Filament\Resources\Drivers\RelationManagers;

use App\Models\GuardianAlert;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** WhatsApp location messages sent to the driver's guardian. */
class GuardianAlertsRelationManager extends RelationManager
{
    protected static string $relationship = 'guardianAlerts';

    protected static ?string $title = 'Guardian WhatsApp alerts';

    public function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->dateTime('d M H:i')->label('Time'),
            TextColumn::make('event')->badge()->formatStateUsing(fn ($state) => match ($state) {
                'trip_started' => 'Picked up rider', 'trip_completed' => 'Ride completed', default => 'Guardian added / test',
            }),
            TextColumn::make('trip.uuid')->label('Trip')->limit(8)->placeholder('—'),
            TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                'sent' => 'success', 'failed' => 'danger', 'skipped' => 'gray', default => 'warning',
            }),
            TextColumn::make('location_source')->label('Location')->placeholder('—')
                ->url(fn (GuardianAlert $record) => $record->mapsUrl(), shouldOpenInNewTab: true),
            TextColumn::make('error')->limit(60)->placeholder('—')->wrap(),
        ]);
    }
}
