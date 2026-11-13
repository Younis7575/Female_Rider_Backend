<?php

namespace App\Filament\Resources\Drivers\RelationManagers;

use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DriverTripsRelationManager extends RelationManager
{
    protected static string $relationship = 'vehicles';

    protected static ?string $title = 'Trips (as driver)';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Trip::query()->where('driver_id', $this->getOwnerRecord()->user_id))
            ->defaultSort('id', 'desc')->columns([
                TextColumn::make('created_at')->dateTime('d M Y H:i'),
                TextColumn::make('customer.name')->label('Customer'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('final_fare')->money('PKR')->placeholder('—'),
                TextColumn::make('commission_amount')->money('PKR')->label('Commission'),
                TextColumn::make('driver_earning')->money('PKR')->label('Earning'),
            ])->recordActions([Action::make('open')->icon('heroicon-o-arrow-top-right-on-square')->url(fn ($record) => TripResource::getUrl('view', ['record' => $record]))]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
