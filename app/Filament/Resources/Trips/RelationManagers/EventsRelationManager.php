<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Timeline';

    public function table(Table $table): Table
    {
        return $table->defaultSort('id')->paginated(false)->columns([
            TextColumn::make('created_at')->dateTime('d M H:i:s')->label('Time'),
            TextColumn::make('event')->badge(),
            TextColumn::make('from_status')->placeholder('—'),
            TextColumn::make('to_status')->placeholder('—'),
            TextColumn::make('actor_type')->badge()->color('gray'),
            TextColumn::make('meta')->formatStateUsing(fn ($state) => is_array($state) ? collect($state)->map(fn ($v, $k) => "$k: ".(is_scalar($v) ? $v : json_encode($v)))->implode(', ') : $state)->wrap()->placeholder('—'),
        ]);
    }
}
