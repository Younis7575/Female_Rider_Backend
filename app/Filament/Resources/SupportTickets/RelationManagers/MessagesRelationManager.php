<?php

namespace App\Filament\Resources\SupportTickets\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Conversation';

    public function table(Table $table): Table
    {
        return $table->defaultSort('id')->paginated(false)->columns([
            TextColumn::make('created_at')->dateTime('d M H:i'),
            TextColumn::make('sender_type')->label('From')->badge()->color(fn ($state, $record) => $record->is_internal ? 'gray' : ($state === 'admin' ? 'primary' : 'info'))
                ->formatStateUsing(fn ($state, $record) => $record->is_internal ? 'internal note' : ($state === 'admin' ? 'support' : 'user')),
            TextColumn::make('body')->wrap(),
        ]);
    }
}
