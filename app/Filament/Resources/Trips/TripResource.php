<?php

namespace App\Filament\Resources\Trips;

use App\Enums\TripStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\Rides\RideResource;
use App\Models\AuditLog;
use App\Models\Trip;
use App\Services\Safety\IncidentService;
use App\Services\Trips\TripService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class TripResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Trip::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 2;

    protected static string $viewPermission = 'rides.view';

    protected static ?string $managePermission = 'rides.manage';

    protected static bool $allowCreate = false;

    protected static bool $allowEdit = false;

    public static function getNavigationBadge(): ?string
    {
        $n = Trip::query()->whereIn('status', TripStatus::activeValues())->count();

        return $n ? (string) $n : null;
    }

    public static function statusColor(TripStatus $state): string
    {
        return match (true) {
            $state === TripStatus::Completed => 'success',
            $state->isCancelled() => 'gray',
            $state === TripStatus::SafetyHold, $state === TripStatus::Disputed => 'danger',
            default => 'info',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                Section::make('Trip')->columnSpan(2)->columns(3)->schema([
                    TextEntry::make('uuid')->label('Trip ID')->fontFamily('mono')->copyable(),
                    TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => self::statusColor($state)),
                    TextEntry::make('rideRequest.uuid')->label('Ride request')->url(fn ($record) => RideResource::getUrl('view', ['record' => $record->rideRequest]))->limit(13),
                    TextEntry::make('customer.name')->label('Customer')->helperText(fn ($record) => $record->customer?->phone),
                    TextEntry::make('driver.name')->label('Driver')->helperText(fn ($record) => $record->driver?->phone),
                    TextEntry::make('vehicle.plate_number')->label('Vehicle')->formatStateUsing(fn ($state, $record) => $record->vehicle?->label().' · '.$state),
                    TextEntry::make('rideRequest.pickup_address')->label('Pickup')->columnSpan(3),
                    TextEntry::make('rideRequest.dropoff_address')->label('Drop-off')->columnSpan(3),
                    TextEntry::make('pin_attempts')->label('Failed PIN attempts'),
                    TextEntry::make('actual_distance_m')->label('Actual distance')->formatStateUsing(fn ($state) => $state ? round($state / 1000, 2).' km' : '—'),
                    TextEntry::make('actual_duration_s')->label('Actual duration')->formatStateUsing(fn ($state) => $state ? round($state / 60).' min' : '—'),
                ]),
                Section::make('Fare & payment')->columnSpan(1)->schema([
                    TextEntry::make('agreed_fare')->money('PKR')->label('Agreed (negotiated)'),
                    TextEntry::make('final_fare')->money('PKR')->placeholder('—'),
                    TextEntry::make('discount_amount')->money('PKR'),
                    TextEntry::make('amount_due')->money('PKR')->label('Customer paid')->placeholder('—'),
                    TextEntry::make('commission_amount')->money('PKR')->formatStateUsing(fn ($state, $record) => 'PKR '.number_format($state, 2).' ('.$record->commission_rate.'%)'),
                    TextEntry::make('driver_earning')->money('PKR'),
                    TextEntry::make('cancellation_fee')->money('PKR')->visible(fn ($record) => $record->cancellation_fee > 0),
                    TextEntry::make('payment_method')->badge(),
                    TextEntry::make('payment_status')->badge()->color(fn ($state) => $state === 'paid' ? 'success' : 'warning'),
                ]),
            ]),
            Section::make('Lifecycle')->columns(6)->schema([
                TextEntry::make('assigned_at')->dateTime('H:i:s'),
                TextEntry::make('en_route_at')->dateTime('H:i:s')->placeholder('—'),
                TextEntry::make('arrived_at')->dateTime('H:i:s')->placeholder('—'),
                TextEntry::make('started_at')->dateTime('H:i:s')->placeholder('—'),
                TextEntry::make('completed_at')->dateTime('H:i:s')->placeholder('—'),
                TextEntry::make('cancelled_at')->dateTime('H:i:s')->placeholder('—')->helperText(fn ($record) => $record->cancelled_by ? 'by '.$record->cancelled_by.': '.$record->cancel_reason : null),
            ]),
            Section::make('Fare breakdown')->collapsible()->schema([
                KeyValueEntry::make('fare_breakdown')->hiddenLabel()->state(fn ($record) => collect($record->fare_breakdown ?? [])->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))->all()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn ($query) => $query->with('customer', 'driver', 'rideRequest', 'vehicleCategory'))->columns([
            TextColumn::make('created_at')->dateTime('d M H:i')->sortable(),
            TextColumn::make('customer.name')->label('Customer')->searchable(),
            TextColumn::make('driver.name')->label('Driver')->searchable(),
            TextColumn::make('rideRequest.pickup_address')->label('Route')->limit(26)->description(fn ($record) => '→ '.str($record->rideRequest?->dropoff_address)->limit(26)),
            TextColumn::make('vehicleCategory.name')->label('Class')->toggleable(),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => self::statusColor($state)),
            TextColumn::make('final_fare')->money('PKR')->placeholder(fn ($record) => 'PKR '.number_format($record->agreed_fare))->sortable(),
            TextColumn::make('payment_status')->badge()->color(fn ($state) => $state === 'paid' ? 'success' : 'gray'),
        ])->filters([
            SelectFilter::make('status')->options(TripStatus::options()),
            Filter::make('active')->label('Active only')->query(fn ($query) => $query->whereIn('status', TripStatus::activeValues())),
            SelectFilter::make('payment_status')->options(['pending' => 'Pending', 'paid' => 'Paid', 'not_applicable' => 'N/A']),
            Filter::make('date')->schema([DatePicker::make('from'), DatePicker::make('until')])
                ->query(fn ($query, array $data) => $query->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))->when($data['until'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))),
        ])->recordActions([ViewAction::make()]);
    }

    public static function viewHeaderActions(): array
    {
        $guard = function (callable $fn) {
            try {
                $fn();
                Notification::make()->title('Done')->success()->send();
            } catch (BusinessRuleException $e) {
                Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();
            }
        };

        return [
            Action::make('safetyHold')->label('Safety hold')->icon(Heroicon::OutlinedShieldExclamation)->color('danger')
                ->visible(fn ($record) => $record->status->canTransitionTo(TripStatus::SafetyHold) && auth('admin')->user()?->can('safety.manage'))
                ->requiresConfirmation()->modalDescription('Freezes the trip state and flags it for the safety team.')
                ->action(fn ($record) => $guard(fn () => app(IncidentService::class)->safetyHold(auth('admin')->user(), $record))),
            Action::make('releaseHold')->label('Release hold')->icon(Heroicon::OutlinedPlay)->color('success')
                ->visible(fn ($record) => $record->status === TripStatus::SafetyHold && auth('admin')->user()?->can('safety.manage'))
                ->requiresConfirmation()
                ->action(fn ($record) => $guard(fn () => app(TripService::class)->transition($record, TripStatus::InProgress, 'admin', auth('admin')->id()))),
            Action::make('adjustFare')->label('Adjust fare')->icon(Heroicon::OutlinedCurrencyDollar)->color('warning')
                ->visible(fn ($record) => in_array($record->status, [TripStatus::Completed, TripStatus::Disputed], true) && static::canManage())
                ->schema([
                    TextInput::make('fare')->numeric()->required()->prefix('PKR')->default(fn ($record) => $record->final_fare),
                    Textarea::make('reason')->required()->minLength(10)->label('Reason (audited)'),
                ])
                ->action(fn ($record, array $data) => $guard(fn () => app(TripService::class)->adjustFare(auth('admin')->user(), $record, (float) $data['fare'], $data['reason']))),
            Action::make('cancel')->label('Cancel trip')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn ($record) => $record->status->canTransitionTo(TripStatus::CancelledByAdmin) && static::canManage())
                ->schema([Textarea::make('reason')->required()])
                ->requiresConfirmation()
                ->action(fn ($record, array $data) => $guard(function () use ($record, $data) {
                    app(TripService::class)->cancel(auth('admin')->user(), $record, $data['reason']);
                    AuditLog::record('trip.admin_cancelled', $record, [], [], ['reason' => $data['reason']]);
                })),
        ];
    }

    public static function getRelations(): array
    {
        return [RelationManagers\EventsRelationManager::class, RelationManagers\MessagesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTrips::route('/'), 'view' => Pages\ViewTrip::route('/{record}')];
    }
}
