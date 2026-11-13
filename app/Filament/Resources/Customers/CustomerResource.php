<?php

namespace App\Filament\Resources\Customers;

use App\Enums\EligibilityStatus;
use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\User;
use App\Services\Driver\DriverOnboardingService;
use App\Services\Notifications\NotificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class CustomerResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'User';

    protected static ?string $navigationLabel = 'Users & customers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string $viewPermission = 'customers.view';

    protected static ?string $managePermission = 'customers.manage';

    protected static bool $allowCreate = false;

    protected static bool $allowEdit = false;

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone', 'email'];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                Section::make('Profile')->columnSpan(2)->columns(3)->schema([
                    ImageEntry::make('photo')->state(fn (User $record) => $record->photoUrl())->circular()->defaultImageUrl(asset('images/admin-logo.png'))->hiddenLabel(),
                    TextEntry::make('name')->weight('bold')->size('lg'),
                    TextEntry::make('phone')->copyable(),
                    TextEntry::make('email')->placeholder('—'),
                    TextEntry::make('locale')->badge(),
                    TextEntry::make('active_mode')->badge()->color(fn ($state) => $state === 'driver' ? 'warning' : 'info'),
                    TextEntry::make('emergency_contact_name')->label('Emergency contact')->placeholder('—'),
                    TextEntry::make('created_at')->label('Joined')->dateTime(),
                    TextEntry::make('last_login_at')->since()->placeholder('—'),
                ]),
                Section::make('Status')->columnSpan(1)->schema([
                    TextEntry::make('account_status')->badge()->color(fn ($state) => match ($state) {
                        'active' => 'success', 'suspended' => 'danger', default => 'warning'
                    }),
                    TextEntry::make('suspension_reason')->visible(fn ($record) => $record->account_status === 'suspended'),
                    TextEntry::make('eligibility_status')->badge()->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => match ($state) {
                        EligibilityStatus::Approved => 'success', EligibilityStatus::Rejected, EligibilityStatus::Suspended => 'danger', default => 'warning'
                    }),
                    TextEntry::make('driverProfile.status')->label('Driver status')->badge()->formatStateUsing(fn ($state) => $state?->label() ?? 'Not a driver')->placeholder('Not a driver'),
                    TextEntry::make('rating_avg')->label('Rating')->formatStateUsing(fn ($state, $record) => number_format((float) $state, 2).' ★ ('.$record->rating_count.')'),
                    TextEntry::make('trips')->label('Completed rides')->state(fn (User $record) => $record->customerTrips()->where('status', 'completed')->count()),
                    TextEntry::make('complaints')->label('Reports against')->state(fn (User $record) => Incident::query()->where('against_user_id', $record->id)->count()),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            ImageColumn::make('photo')->state(fn (User $record) => $record->photoUrl())->circular()->defaultImageUrl(asset('images/admin-logo.png'))->label(''),
            TextColumn::make('name')->searchable()->weight('bold')->description(fn (User $record) => $record->phone)->placeholder('Profile incomplete'),
            TextColumn::make('phone')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('eligibility_status')->label('Verification')->badge()->formatStateUsing(fn ($state) => $state->label())
                ->color(fn ($state) => match ($state) {
                    EligibilityStatus::Approved => 'success', EligibilityStatus::Rejected, EligibilityStatus::Suspended => 'danger', EligibilityStatus::Draft => 'gray', default => 'warning'
                }),
            TextColumn::make('account_status')->badge()->color(fn ($state) => match ($state) {
                'active' => 'success', 'suspended' => 'danger', default => 'warning'
            }),
            TextColumn::make('driverProfile.status')->label('Driver')->badge()->formatStateUsing(fn ($state) => $state?->label())->placeholder('—'),
            TextColumn::make('rating_avg')->label('Rating')->numeric(2)->sortable(),
            TextColumn::make('created_at')->label('Joined')->date()->sortable(),
        ])->filters([
            SelectFilter::make('eligibility_status')->options(EligibilityStatus::options()),
            SelectFilter::make('account_status')->options(['active' => 'Active', 'suspended' => 'Suspended', 'pending_deletion' => 'Pending deletion', 'deleted' => 'Deleted']),
            TernaryFilter::make('is_driver')->label('Has driver profile')->queries(true: fn ($query) => $query->has('driverProfile'), false: fn ($query) => $query->doesntHave('driverProfile')),
        ])->recordActions([ViewAction::make()]);
    }

    public static function viewHeaderActions(): array
    {
        return [
            Action::make('suspend')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible(fn (User $record) => $record->account_status === 'active' && static::canManage())
                ->schema([Textarea::make('reason')->required()->label('Reason (shown to the user)')])
                ->requiresConfirmation()
                ->action(function (User $record, array $data) {
                    if ($record->driverProfile?->is_online) {
                        app(DriverOnboardingService::class)->forceOffline($record->driverProfile, 'account_suspended');
                    }
                    $record->forceFill(['account_status' => 'suspended', 'suspension_reason' => $data['reason']])->save();
                    $record->tokens()->delete();
                    AuditLog::record('user.suspended', $record, ['account_status' => 'active'], ['account_status' => 'suspended'], ['reason' => $data['reason']]);
                    Notification::make()->title('Account suspended and signed out')->success()->send();
                }),
            Action::make('activate')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (User $record) => $record->account_status === 'suspended' && static::canManage())
                ->requiresConfirmation()
                ->action(function (User $record) {
                    $record->forceFill(['account_status' => 'active', 'suspension_reason' => null])->save();
                    AuditLog::record('user.activated', $record, ['account_status' => 'suspended'], ['account_status' => 'active']);
                    app(NotificationService::class)->send($record, 'account.status', 'Account reactivated', 'Your FemaleRider account is active again.');
                    Notification::make()->title('Account reactivated')->success()->send();
                }),
        ];
    }

    public static function getRelations(): array
    {
        return [RelationManagers\TripsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUsers::route('/'), 'view' => Pages\ViewUser::route('/{record}')];
    }
}
