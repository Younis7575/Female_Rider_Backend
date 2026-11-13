<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of the customer ⇄ driver chat. Private conversation: only staff
 * with safety access (incident investigation) can open it.
 */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Chat';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth('admin')->user()?->can('safety.view');
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('id')->paginated([50, 100])->columns([
            TextColumn::make('created_at')->dateTime('d M H:i:s')->label('Time'),
            TextColumn::make('sender_role')->label('From')->badge()->color(fn ($state) => $state === 'driver' ? 'gray' : 'danger'),
            TextColumn::make('body')->label('Message')->wrap(),
            TextColumn::make('read_at')->dateTime('H:i:s')->label('Read')->placeholder('—'),
        ]);
    }
}
