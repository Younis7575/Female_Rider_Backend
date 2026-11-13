<?php

namespace App\Filament\Resources\AdminUsers;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\AdminUser;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

class AdminUserResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = AdminUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Admin users';

    protected static string $viewPermission = 'admins.manage';

    protected static ?string $managePermission = 'admins.manage';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Staff account')->columns(2)->schema([
                TextInput::make('name')->required(),
                TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
                TextInput::make('password')->password()->revealable()
                    ->rule(Password::min(12)->mixedCase()->numbers()->symbols())
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText('Minimum 12 characters with upper/lower case, numbers and symbols.'),
                Select::make('roles')->relationship('roles', 'name', fn ($query) => $query->where('guard_name', 'admin'))
                    ->multiple()->preload()->required()
                    ->getOptionLabelFromRecordUsing(fn ($record) => str($record->name)->replace('_', ' ')->title()),
                Toggle::make('is_active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->weight('bold')->searchable()->description(fn ($record) => $record->email),
            TextColumn::make('roles.name')->badge()->formatStateUsing(fn ($state) => str($state)->replace('_', ' ')->title()),
            IconColumn::make('mfa')->label('MFA')->boolean()->state(fn (AdminUser $record) => $record->hasMfa()),
            IconColumn::make('is_active')->boolean(),
            TextColumn::make('last_login_at')->since()->placeholder('Never'),
        ])->recordActions([
            Action::make('resetMfa')->label('Reset MFA')->icon(Heroicon::OutlinedKey)->color('warning')->requiresConfirmation()
                ->visible(fn (AdminUser $record) => $record->hasMfa())
                ->action(function (AdminUser $record) {
                    $record->saveAppAuthenticationSecret(null);
                    $record->saveAppAuthenticationRecoveryCodes(null);
                    Notification::make()->title('MFA reset. The user must enrol again at next login.')->success()->send();
                }),
            EditAction::make()->after(fn (AdminUser $record) => AuditLog::record('admin.updated', $record, [], ['roles' => $record->getRoleNames()->all(), 'is_active' => $record->is_active])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAdminUsers::route('/'), 'create' => Pages\CreateAdminUser::route('/create'), 'edit' => Pages\EditAdminUser::route('/{record}/edit')];
    }
}
