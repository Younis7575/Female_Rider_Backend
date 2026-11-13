<?php

namespace App\Filament\Resources\Rides\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Full negotiation history: every offer and counter-offer with actor and timestamps. */
class OffersRelationManager extends RelationManager
{
    protected static string $relationship = 'offers';

    protected static ?string $title = 'Offer & counter-offer history';

    public function table(Table $table): Table
    {
        return $table->defaultSort('id')->columns([
            TextColumn::make('created_at')->dateTime('H:i:s')->label('Time'),
            TextColumn::make('driver.name')->label('Driver thread'),
            TextColumn::make('sequence')->label('#'),
            TextColumn::make('proposed_by')->badge()->color(fn ($state) => $state === 'driver' ? 'warning' : 'info'),
            TextColumn::make('amount')->money('PKR')->weight('bold'),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state->label())
                ->color(fn ($state) => match ($state->value) {
                    'accepted' => 'success', 'rejected', 'withdrawn' => 'danger', 'pending' => 'info', default => 'gray'
                }),
            TextColumn::make('eta_s')->label('ETA')->formatStateUsing(fn ($state) => $state ? round($state / 60).' min' : '—'),
            TextColumn::make('expires_at')->dateTime('H:i:s'),
            TextColumn::make('responded_at')->dateTime('H:i:s')->placeholder('—'),
        ]);
    }
}
