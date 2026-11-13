<?php

namespace App\Filament\Resources\Announcements;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class AnnouncementResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Announcements & push';

    protected static string $viewPermission = 'notifications.send';

    protected static ?string $managePermission = 'notifications.send';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                TextInput::make('title')->required()->maxLength(80)->columnSpan(2),
                Select::make('audience')->options(['all' => 'Everyone', 'customers' => 'Customers', 'drivers' => 'Approved drivers'])->required()->default('all'),
                Textarea::make('body')->required()->maxLength(400)->rows(4)->columnSpanFull()->helperText('Never include personal or sensitive information — push text can appear on lock screens.'),
                Select::make('city_id')->relationship('city', 'name')->placeholder('All cities'),
                Toggle::make('send_push')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('title')->weight('bold')->description(fn ($record) => str($record->body)->limit(80)),
            TextColumn::make('audience')->badge(),
            TextColumn::make('sent_at')->dateTime()->placeholder('Draft'),
            TextColumn::make('recipients_count')->label('Recipients'),
        ])->recordActions([
            Action::make('send')->icon(Heroicon::OutlinedPaperAirplane)->color('primary')->requiresConfirmation()
                ->modalDescription('This sends an in-app notification (and push, if enabled) to every matching active user.')
                ->visible(fn (Announcement $record) => ! $record->sent_at && static::canManage())
                ->action(function (Announcement $record) {
                    $query = User::query()->where('account_status', 'active')->where('eligibility_status', 'approved');
                    if ($record->audience === 'drivers') {
                        $query->whereHas('driverProfile', fn ($d) => $d->where('status', 'approved'));
                    }
                    $count = 0;
                    $svc = app(NotificationService::class);
                    $query->chunkById(200, function ($users) use ($svc, $record, &$count) {
                        foreach ($users as $u) {
                            $svc->send($u, 'announcement', $record->title, $record->body, ['announcement_id' => $record->id], $record->send_push);
                            $count++;
                        }
                    });
                    $record->update(['sent_at' => now(), 'recipients_count' => $count]);
                    AuditLog::record('announcement.sent', $record, [], ['recipients' => $count]);
                    Notification::make()->title("Sent to {$count} users")->success()->send();
                }),
            EditAction::make()->visible(fn (Announcement $record) => ! $record->sent_at),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAnnouncements::route('/'), 'create' => Pages\CreateAnnouncement::route('/create'), 'edit' => Pages\EditAnnouncement::route('/{record}/edit')];
    }
}
