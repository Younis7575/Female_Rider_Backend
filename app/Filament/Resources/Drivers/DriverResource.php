<?php

namespace App\Filament\Resources\Drivers;

use App\Enums\DriverStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Services\Driver\DriverOnboardingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class DriverResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = DriverProfile::class;

    protected static ?string $modelLabel = 'Driver';

    protected static ?string $navigationLabel = 'Drivers & applications';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 3;

    protected static string $viewPermission = 'drivers.view';

    protected static ?string $managePermission = 'drivers.review';

    protected static bool $allowCreate = false;

    protected static bool $allowEdit = false;

    public static function getNavigationBadge(): ?string
    {
        $n = DriverProfile::query()->whereIn('status', ['pending', 'under_review'])->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function statusColor(?DriverStatus $state): string
    {
        return match ($state) {
            DriverStatus::Approved => 'success',
            DriverStatus::Rejected, DriverStatus::Suspended, DriverStatus::Expired => 'danger',
            DriverStatus::Pending, DriverStatus::UnderReview, DriverStatus::MoreInfoRequired => 'warning',
            default => 'gray',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                Section::make('Driver')->columnSpan(2)->columns(3)->schema([
                    TextEntry::make('full_name')->weight('bold')->size('lg'),
                    TextEntry::make('user.phone')->label('Phone')->copyable(),
                    TextEntry::make('cnic')->label('CNIC')->state(fn (DriverProfile $record) => $record->cnic_number ? substr($record->cnic_number, 0, 5).'-•••••••-'.substr($record->cnic_number, -1) : '—')
                        ->helperText('Masked. Full value visible only in the document images.'),
                    TextEntry::make('date_of_birth')->date()->helperText(fn ($record) => $record->date_of_birth ? $record->date_of_birth->age.' years' : null),
                    TextEntry::make('license_expiry')->date()->color(fn ($record) => $record->license_expiry?->isPast() ? 'danger' : ($record->license_expiry?->lt(now()->addDays(30)) ? 'warning' : null)),
                    TextEntry::make('address'),
                    TextEntry::make('emergency_contact_name'),
                    TextEntry::make('serviceZone.name')->label('Zone')->placeholder('—'),
                    TextEntry::make('submitted_at')->dateTime()->placeholder('Not submitted'),
                ]),
                Section::make('Status & performance')->columnSpan(1)->schema([
                    TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => self::statusColor($state)),
                    TextEntry::make('user_facing_reason')->label('Reason shown to driver')->placeholder('—'),
                    IconEntry::make('is_online')->boolean()->label('Online now'),
                    TextEntry::make('rating_avg')->label('Rating')->formatStateUsing(fn ($state, $record) => number_format((float) $state, 2).' ★ ('.$record->rating_count.')'),
                    TextEntry::make('completed_trips'),
                    TextEntry::make('cancelled_trips'),
                    TextEntry::make('acceptance')->label('Cancellation rate')->state(fn (DriverProfile $record) => ($record->completed_trips + $record->cancelled_trips) ? round($record->cancelled_trips / ($record->completed_trips + $record->cancelled_trips) * 100, 1).'%' : '—'),
                    TextEntry::make('compliance')->label('Go-online blockers')->state(fn (DriverProfile $record) => implode(' • ', $record->complianceIssues()) ?: 'None — compliant')->color(fn (DriverProfile $record) => $record->complianceIssues() ? 'danger' : 'success'),
                ]),
            ]),
            Section::make('Vehicle')->columns(4)->schema([
                TextEntry::make('primaryVehicle.category.name')->label('Class'),
                TextEntry::make('primaryVehicle.make')->label('Make / model')->formatStateUsing(fn ($state, $record) => $record->primaryVehicle?->make.' '.$record->primaryVehicle?->model.' ('.$record->primaryVehicle?->year.')'),
                TextEntry::make('primaryVehicle.color')->label('Colour'),
                TextEntry::make('primaryVehicle.plate_number')->label('Plate')->badge()->color('gray'),
                TextEntry::make('primaryVehicle.status')->label('Vehicle status')->badge()->color(fn ($state) => $state === 'approved' ? 'success' : ($state === 'rejected' ? 'danger' : 'warning')),
                TextEntry::make('primaryVehicle.registration_expiry')->label('Registration expiry')->date()->placeholder('—'),
                TextEntry::make('primaryVehicle.insurance_expiry')->label('Insurance expiry')->date()->placeholder('—'),
                TextEntry::make('primaryVehicle.rejection_reason')->label('Rejection reason')->placeholder('—'),
            ]),
            Section::make('Internal review notes')->collapsible()->schema([
                TextEntry::make('review_notes')->hiddenLabel()->placeholder('No notes')->helperText('Never shown to the driver.'),
                TextEntry::make('reviewer.name')->label('Last reviewer')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('submitted_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user', 'primaryVehicle.category'))
            ->columns([
                TextColumn::make('full_name')->label('Driver')->searchable()->weight('bold')->description(fn ($record) => $record->user?->phone)->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => self::statusColor($state)),
                IconColumn::make('is_online')->boolean()->label('Online'),
                TextColumn::make('primaryVehicle.plate_number')->label('Vehicle')->description(fn ($record) => $record->primaryVehicle?->category?->name),
                TextColumn::make('license_expiry')->date()->sortable()->color(fn ($record) => $record->license_expiry?->isPast() ? 'danger' : ($record->license_expiry?->lt(now()->addDays(30)) ? 'warning' : null)),
                TextColumn::make('rating_avg')->label('Rating')->numeric(2)->sortable(),
                TextColumn::make('completed_trips')->label('Trips')->sortable(),
                TextColumn::make('submitted_at')->since()->sortable(),
            ])->filters([
                SelectFilter::make('status')->options(DriverStatus::options()),
                TernaryFilter::make('is_online'),
                Filter::make('licence_expiring')->label('Licence expiring ≤30 days')->query(fn ($query) => $query->whereBetween('license_expiry', [now(), now()->addDays(30)])),
            ])->recordActions([ViewAction::make()->label('Open')]);
    }

    public static function viewHeaderActions(): array
    {
        $run = function (DriverProfile $record, DriverStatus $status, ?string $reason = null, ?string $notes = null) {
            try {
                app(DriverOnboardingService::class)->setStatus(auth('admin')->user(), $record, $status, $reason, $notes);
                Notification::make()->title('Driver status: '.$status->label())->success()->send();
            } catch (BusinessRuleException $e) {
                Notification::make()->title('Cannot change status')->body($e->getMessage())->danger()->send();
            }
        };
        $canReview = fn () => static::canManage();
        $canManageDrivers = fn () => (bool) auth('admin')->user()?->can('drivers.manage');

        return [
            Action::make('startReview')->label('Start review')->color('gray')->icon(Heroicon::OutlinedEye)
                ->visible(fn ($record) => $record->status === DriverStatus::Pending && $canReview())
                ->action(fn ($record) => $run($record, DriverStatus::UnderReview)),
            Action::make('approve')->label('Approve driver')->color('success')->icon(Heroicon::OutlinedCheckBadge)
                ->visible(fn ($record) => in_array($record->status, [DriverStatus::Pending, DriverStatus::UnderReview, DriverStatus::MoreInfoRequired], true) && $canReview())
                ->requiresConfirmation()->modalDescription('All mandatory documents and the vehicle must already be approved.')
                ->schema([Textarea::make('notes')->label('Internal notes')])
                ->action(fn ($record, array $data) => $run($record, DriverStatus::Approved, null, $data['notes'] ?? null)),
            Action::make('requestInfo')->label('Request documents')->color('warning')->icon(Heroicon::OutlinedDocumentPlus)
                ->visible(fn ($record) => in_array($record->status, [DriverStatus::Pending, DriverStatus::UnderReview], true) && $canReview())
                ->schema([Textarea::make('reason')->required()->label('What the driver must fix (shown to driver)'), Textarea::make('notes')->label('Internal notes')])
                ->action(fn ($record, array $data) => $run($record, DriverStatus::MoreInfoRequired, $data['reason'], $data['notes'] ?? null)),
            Action::make('reject')->color('danger')->icon(Heroicon::OutlinedXCircle)
                ->visible(fn ($record) => in_array($record->status, [DriverStatus::Pending, DriverStatus::UnderReview, DriverStatus::MoreInfoRequired], true) && $canReview())
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->label('Reason (shown to driver)'), Textarea::make('notes')->label('Internal notes')])
                ->action(fn ($record, array $data) => $run($record, DriverStatus::Rejected, $data['reason'], $data['notes'] ?? null)),
            ActionGroup::make([
                Action::make('approveVehicle')->label('Approve vehicle')->icon(Heroicon::OutlinedTruck)
                    ->visible(fn ($record) => $record->primaryVehicle && $record->primaryVehicle->status !== 'approved' && $canReview())
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        app(DriverOnboardingService::class)->reviewVehicle(auth('admin')->user(), $record->primaryVehicle, true);
                        Notification::make()->title('Vehicle approved')->success()->send();
                    }),
                Action::make('rejectVehicle')->label('Reject vehicle')->icon(Heroicon::OutlinedTruck)->color('danger')
                    ->visible(fn ($record) => $record->primaryVehicle && $record->primaryVehicle->status !== 'rejected' && $canReview())
                    ->schema([Textarea::make('reason')->required()])
                    ->action(function ($record, array $data) {
                        app(DriverOnboardingService::class)->reviewVehicle(auth('admin')->user(), $record->primaryVehicle, false, $data['reason']);
                        Notification::make()->title('Vehicle rejected')->send();
                    }),
                Action::make('suspend')->label('Suspend driver')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn ($record) => $record->status === DriverStatus::Approved && $canManageDrivers())
                    ->requiresConfirmation()->schema([Textarea::make('reason')->required()->label('Reason (shown to driver)')])
                    ->action(fn ($record, array $data) => $run($record, DriverStatus::Suspended, $data['reason'])),
                Action::make('reinstate')->label('Reinstate')->icon(Heroicon::OutlinedArrowPath)->color('success')
                    ->visible(fn ($record) => in_array($record->status, [DriverStatus::Suspended, DriverStatus::Expired], true) && $canManageDrivers() && $canReview())
                    ->requiresConfirmation()
                    ->action(fn ($record) => $run($record, DriverStatus::Approved)),
                Action::make('forceOffline')->label('Force offline')->icon(Heroicon::OutlinedSignalSlash)
                    ->visible(fn ($record) => $record->is_online && $canManageDrivers())
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        app(DriverOnboardingService::class)->forceOffline($record, 'admin');
                        AuditLog::record('driver.forced_offline', $record);
                        Notification::make()->title('Driver set offline')->send();
                    }),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    public static function getRelations(): array
    {
        return [RelationManagers\DocumentsRelationManager::class, RelationManagers\DriverTripsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDrivers::route('/'), 'view' => Pages\ViewDriver::route('/{record}')];
    }
}
