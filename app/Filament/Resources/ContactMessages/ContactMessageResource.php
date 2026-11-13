<?php

namespace App\Filament\Resources\ContactMessages;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\ContactMessage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ContactMessageResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = ContactMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Safety & Support';

    protected static ?string $navigationLabel = 'Website enquiries';

    protected static string $viewPermission = 'support.view';

    protected static ?string $managePermission = 'support.manage';

    public static function getNavigationBadge(): ?string
    {
        $n = ContactMessage::query()->where('status', 'new')->count();

        return $n ? (string) $n : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([Section::make()->columns(3)->schema([
            TextEntry::make('name'), TextEntry::make('email'), TextEntry::make('phone'),
            TextEntry::make('subject')->columnSpanFull(), TextEntry::make('message')->columnSpanFull(),
            TextEntry::make('status')->badge(), TextEntry::make('created_at')->dateTime(),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->since(),
            TextColumn::make('name')->searchable()->description(fn ($record) => $record->email),
            TextColumn::make('subject')->limit(50),
            TextColumn::make('status')->badge()->color(fn ($state) => $state === 'new' ? 'warning' : 'success'),
        ])->filters([SelectFilter::make('status')->options(['new' => 'New', 'handled' => 'Handled'])])
            ->recordActions([
                ViewAction::make(),
                Action::make('handled')->icon(Heroicon::OutlinedCheck)->visible(fn ($record) => $record->status === 'new' && static::canManage())->action(fn ($record) => $record->update(['status' => 'handled'])),
            ]);
    }

    public static function viewHeaderActions(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListContactMessages::route('/'), 'view' => Pages\ViewContactMessage::route('/{record}')];
    }
}
