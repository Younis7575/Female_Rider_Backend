<?php

namespace App\Filament\Resources\Rides;

use App\Enums\RideStatus;
use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\Trips\TripResource;
use App\Models\AuditLog;
use App\Models\RideRequest;
use App\Services\Rides\RideService;
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

class RideResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = RideRequest::class;

    protected static ?string $modelLabel = 'Ride request';

    protected static ?string $navigationLabel = 'Ride requests & negotiation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    protected static string $viewPermission = 'rides.view';

    protected static ?string $managePermission = 'rides.manage';

    protected static bool $allowCreate = false;

    protected static bool $allowEdit = false;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Request')->columns(4)->schema([
                TextEntry::make('uuid')->label('Ride ID')->copyable()->fontFamily('mono'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => $state->label()),
                TextEntry::make('customer.name')->label('Customer'),
                TextEntry::make('vehicleCategory.name')->label('Class'),
                TextEntry::make('pickup_address')->columnSpan(2),
                TextEntry::make('dropoff_address')->columnSpan(2),
                TextEntry::make('distance_m')->label('Distance')->formatStateUsing(fn ($state) => round($state / 1000, 1).' km'),
                TextEntry::make('duration_s')->label('Est. duration')->formatStateUsing(fn ($state) => round($state / 60).' min'),
                TextEntry::make('serviceZone.name')->label('Zone'),
                TextEntry::make('broadcast_round')->label('Dispatch rounds')->formatStateUsing(fn ($state, $record) => "{$state} (radius {$record->search_radius_km} km)"),
            ]),
            Section::make('Fare negotiation')->columns(4)->schema([
                TextEntry::make('suggested_fare')->money('PKR'),
                TextEntry::make('bounds')->label('Allowed range')->state(fn ($record) => 'PKR '.number_format($record->min_fare).' – '.number_format($record->max_fare)),
                TextEntry::make('initial_proposed_fare')->label('Customer initial offer')->money('PKR'),
                TextEntry::make('proposed_fare')->label('Customer current offer')->money('PKR'),
                TextEntry::make('surge_multiplier')->suffix('×'),
                TextEntry::make('discount_amount')->money('PKR'),
                TextEntry::make('coupon.code')->placeholder('—'),
                TextEntry::make('dispatches_count')->label('Drivers notified')->state(fn ($record) => $record->dispatches()->count()),
            ]),
            Section::make('Outcome')->columns(4)->schema([
                TextEntry::make('driver.name')->label('Assigned driver')->placeholder('—'),
                TextEntry::make('trip.uuid')->label('Trip')->placeholder('—')->url(fn ($record) => $record->trip ? TripResource::getUrl('view', ['record' => $record->trip]) : null),
                TextEntry::make('cancelled_by')->placeholder('—'),
                TextEntry::make('cancel_reason')->placeholder('—'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('expires_at')->dateTime(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn ($query) => $query->with('customer', 'vehicleCategory', 'driver'))->columns([
            TextColumn::make('created_at')->dateTime('d M H:i')->sortable(),
            TextColumn::make('customer.name')->label('Customer')->searchable(),
            TextColumn::make('pickup_address')->label('Route')->limit(28)->description(fn ($record) => '→ '.str($record->dropoff_address)->limit(28)),
            TextColumn::make('vehicleCategory.name')->label('Class'),
            TextColumn::make('proposed_fare')->money('PKR')->description(fn ($record) => 'Suggested '.number_format($record->suggested_fare)),
            TextColumn::make('offers_count')->counts('offers')->label('Offers'),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state->label())
                ->color(fn ($state) => match ($state) {
                    RideStatus::Accepted => 'success', RideStatus::Searching, RideStatus::OffersAvailable => 'info', default => 'gray'
                }),
            TextColumn::make('driver.name')->label('Driver')->placeholder('—'),
        ])->filters([SelectFilter::make('status')->options(RideStatus::options())])
            ->recordActions([ViewAction::make()]);
    }

    public static function viewHeaderActions(): array
    {
        return [
            Action::make('cancel')->label('Cancel request')->color('danger')->icon(Heroicon::OutlinedXCircle)
                ->visible(fn ($record) => $record->status->isOpen() && static::canManage())
                ->requiresConfirmation()
                ->action(function ($record) {
                    app(RideService::class)->cancel($record->customer, $record, 'Cancelled by FemaleRider operations');
                    AuditLog::record('ride.admin_cancelled', $record);
                }),
        ];
    }

    public static function getRelations(): array
    {
        return [RelationManagers\OffersRelationManager::class, RelationManagers\DispatchesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRides::route('/'), 'view' => Pages\ViewRide::route('/{record}')];
    }
}
