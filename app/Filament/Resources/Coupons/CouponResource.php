<?php

namespace App\Filament\Resources\Coupons;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\Coupon;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CouponResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Coupons & promotions';

    protected static ?int $navigationSort = 5;

    protected static string $viewPermission = 'promotions.manage';

    protected static ?string $managePermission = 'promotions.manage';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Coupon')->columns(3)->schema([
                TextInput::make('code')->required()->maxLength(40)->unique(ignoreRecord: true)->dehydrateStateUsing(fn ($state) => strtoupper(trim($state))),
                TextInput::make('description')->columnSpan(2),
                Select::make('type')->options(['percent' => 'Percentage', 'fixed' => 'Fixed amount'])->required()->live(),
                TextInput::make('value')->numeric()->required()->minValue(1),
                TextInput::make('max_discount')->numeric()->prefix('PKR'),
                TextInput::make('min_fare')->numeric()->prefix('PKR')->default(0),
            ]),
            Section::make('Limits, budget & fraud controls')->columns(3)->schema([
                TextInput::make('usage_limit')->numeric()->placeholder('Unlimited'),
                TextInput::make('per_user_limit')->numeric()->default(1)->required(),
                TextInput::make('budget')->numeric()->prefix('PKR')->placeholder('Unlimited'),
                Toggle::make('first_ride_only'),
                Select::make('city_id')->relationship('city', 'name')->placeholder('All cities'),
                Select::make('vehicle_category_id')->relationship('vehicleCategory', 'name')->placeholder('All classes'),
            ]),
            Section::make('Validity')->columns(3)->schema([
                DateTimePicker::make('starts_at'),
                DateTimePicker::make('ends_at'),
                Toggle::make('is_active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->badge()->color('primary')->searchable()->copyable(),
            TextColumn::make('description')->limit(40),
            TextColumn::make('value')->formatStateUsing(fn ($state, $record) => $record->type === 'percent' ? "{$state}%" : 'PKR '.number_format($state)),
            TextColumn::make('used_count')->label('Used')->formatStateUsing(fn ($state, $record) => $state.($record->usage_limit ? " / {$record->usage_limit}" : '')),
            TextColumn::make('budget_used')->money('PKR')->label('Budget used'),
            TextColumn::make('ends_at')->dateTime('d M Y')->placeholder('No expiry'),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCoupons::route('/'), 'create' => Pages\CreateCoupon::route('/create'), 'edit' => Pages\EditCoupon::route('/{record}/edit')];
    }
}
