<?php

namespace App\Filament\Resources\Incidents\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Evidence is restricted to holders of safety.evidence.view; every open is audited. */
class EvidenceRelationManager extends RelationManager
{
    protected static string $relationship = 'evidence';

    protected static ?string $title = 'Restricted evidence';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth('admin')->user()?->can('safety.evidence.view');
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->dateTime(),
            TextColumn::make('mime'),
        ])->recordActions([
            Action::make('open')->icon('heroicon-o-eye')->url(fn ($record) => route('admin.secure.file', ['type' => 'evidence', 'id' => $record->id]), true),
        ]);
    }
}
