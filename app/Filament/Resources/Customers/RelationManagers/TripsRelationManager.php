<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Trips\TripResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TripsRelationManager extends RelationManager
{
    protected static string $relationship = 'customerTrips';

    protected static ?string $title = 'Ride history (as customer)';

    public function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->dateTime('d M Y H:i'),
            TextColumn::make('rideRequest.pickup_address')->label('From')->limit(30),
            TextColumn::make('rideRequest.dropoff_address')->label('To')->limit(30),
            TextColumn::make('driver.name')->label('Driver'),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state->label()),
            TextColumn::make('final_fare')->money('PKR')->placeholder('—'),
        ])->recordActions([Action::make('open')->icon('heroicon-o-arrow-top-right-on-square')->url(fn ($record) => TripResource::getUrl('view', ['record' => $record]))]);
    }
}
