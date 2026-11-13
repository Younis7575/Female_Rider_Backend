<?php

namespace App\Filament\Resources\SupportTickets;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\AdminUser;
use App\Models\SupportTicket;
use App\Services\Notifications\NotificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SupportTicketResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = SupportTicket::class;

    protected static ?string $navigationLabel = 'Support tickets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Safety & Support';

    protected static ?int $navigationSort = 2;

    protected static string $viewPermission = 'support.view';

    protected static ?string $managePermission = 'support.manage';

    protected static bool $allowCreate = false;

    protected static bool $allowEdit = false;

    public static function getNavigationBadge(): ?string
    {
        $n = SupportTicket::query()->where('status', 'open')->count();

        return $n ? (string) $n : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('reference')->weight('bold'),
                TextEntry::make('category')->badge(),
                TextEntry::make('priority')->badge()->color(fn ($state) => in_array($state, ['high', 'urgent']) ? 'danger' : 'gray'),
                TextEntry::make('status')->badge(),
                TextEntry::make('user.name')->label('Customer / driver')->helperText(fn ($record) => $record->user?->phone),
                TextEntry::make('trip.uuid')->label('Trip')->limit(13)->placeholder('—'),
                TextEntry::make('assignee.name')->placeholder('Unassigned'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('subject')->columnSpanFull()->weight('bold'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')->modifyQueryUsing(fn ($query) => $query->with('user', 'assignee'))->columns([
            TextColumn::make('reference')->searchable()->weight('bold'),
            TextColumn::make('subject')->limit(40)->searchable(),
            TextColumn::make('user.name')->label('From'),
            TextColumn::make('category')->badge(),
            TextColumn::make('priority')->badge()->color(fn ($state) => in_array($state, ['high', 'urgent']) ? 'danger' : 'gray'),
            TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                'open' => 'warning', 'resolved', 'closed' => 'success', default => 'info'
            }),
            TextColumn::make('assignee.name')->placeholder('—'),
            TextColumn::make('updated_at')->since(),
        ])->filters([
            SelectFilter::make('status')->options(['open' => 'Open', 'pending' => 'Pending', 'awaiting_user' => 'Awaiting user', 'resolved' => 'Resolved', 'closed' => 'Closed']),
            SelectFilter::make('category')->options(['general' => 'General', 'payment' => 'Payment', 'lost_item' => 'Lost item', 'complaint' => 'Complaint', 'account' => 'Account', 'driver' => 'Driver', 'safety' => 'Safety', 'refund' => 'Refund', 'fare_dispute' => 'Fare dispute']),
        ])->recordActions([ViewAction::make()]);
    }

    public static function viewHeaderActions(): array
    {
        return [
            Action::make('reply')->icon(Heroicon::OutlinedChatBubbleLeft)->color('primary')->visible(fn () => static::canManage())
                ->schema([
                    Textarea::make('body')->required()->rows(5),
                    Toggle::make('internal')->label('Internal note (not visible to user)'),
                    Select::make('status')->options(['awaiting_user' => 'Awaiting user', 'pending' => 'Pending (internal)', 'resolved' => 'Resolved', 'closed' => 'Closed'])->default('awaiting_user'),
                ])
                ->action(function ($record, array $data) {
                    $record->messages()->create(['sender_type' => 'admin', 'sender_id' => auth('admin')->id(), 'body' => $data['body'], 'is_internal' => (bool) $data['internal']]);
                    $record->update(['status' => $data['status'], 'assigned_admin_id' => $record->assigned_admin_id ?? auth('admin')->id(), 'resolved_at' => in_array($data['status'], ['resolved', 'closed']) ? now() : null]);
                    if (! $data['internal']) {
                        app(NotificationService::class)->send($record->user, 'support.reply', 'Support replied', 'New reply on ticket '.$record->reference, ['ticket_id' => $record->reference, 'screen' => 'support_ticket']);
                    }
                    Notification::make()->title('Reply sent')->success()->send();
                }),
            Action::make('assign')->icon(Heroicon::OutlinedUserPlus)->color('gray')->visible(fn () => static::canManage())
                ->schema([Select::make('admin_id')->options(fn () => AdminUser::query()->where('is_active', true)->pluck('name', 'id'))->required()])
                ->action(fn ($record, array $data) => $record->update(['assigned_admin_id' => $data['admin_id']])),
        ];
    }

    public static function getRelations(): array
    {
        return [RelationManagers\MessagesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSupportTickets::route('/'), 'view' => Pages\ViewSupportTicket::route('/{record}')];
    }
}
